<?php

namespace SaaS\Core\Auth\Passkeys;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LaravelWebauthn\Facades\Webauthn;
use LaravelWebauthn\Models\WebauthnKey;
use RuntimeException;
use SaaS\Core\Auth\Mobile\DeviceTokenService;
use SaaS\Core\Tenancy\TenantOnboarding;

/**
 * PasskeyRegistrationController
 *
 * Gestisce la registrazione di un nuovo utente tramite passkey.
 *
 * FLUSSO IN 2 STEP:
 *
 *   Step 1 — POST /auth/passkey/register/options
 *     Il client manda nome + email.
 *     Il server crea l'utente e restituisce le opzioni WebAuthn
 *     (challenge, rpId, user info) per il browser.
 *
 *   Step 2 — POST /auth/passkey/register
 *     Il browser ha eseguito navigator.credentials.create() con la biometrica.
 *     Il client manda la risposta crittografica.
 *     Il server verifica, salva la chiave pubblica, autentica l'utente.
 *
 * ROLLBACK AUTOMATICO:
 *   Se il browser non completa la biometrica (utente annulla, timeout),
 *   l'utente creato nello step 1 viene eliminato alla prossima chiamata
 *   options() con la stessa email — o rimane "pending" finché non si registra.
 *
 * DIFFERENZA CON IL LOGIN:
 *   - Login:        l'utente ESISTE già con una passkey salvata → verifica
 *   - Registrazione: l'utente NON ESISTE → crea + salva passkey
 */
class PasskeyRegistrationController extends Controller
{
    public function __construct(
        private readonly DeviceTokenService $deviceTokenService,
        private readonly TenantOnboarding $onboarding,
    ) {}

    /**
     * options() — Step 1
     *
     * Prepara le opzioni di attestazione WebAuthn per la registrazione.
     *
     * SICUREZZA — 3 casi:
     *
     *   1. Email NON esiste → crea l'utente e procedi
     *
     *   2. Email esiste + ha passkey attive → BLOCCA
     *      Chiunque conosca l'email di un utente potrebbe altrimenti chiamare
     *      questo endpoint, completare il WebAuthn sul proprio dispositivo e
     *      aggiungere la propria passkey all'account altrui.
     *      → 422 "Email già registrata"
     *
     *   3. Email esiste + nessuna passkey (registrazione abbandonata) → riusa il record
     *      L'utente ha iniziato la registrazione ma non ha completato la biometrica.
     *      È sicuro riusare il record perché non ha ancora accesso all'account.
     */
    public function options(Request $request): JsonResponse
    {
        $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email', 'max:255'],
            // ONBOARDING TENANT (entrambi opzionali — retrocompatibile):
            //   company      → self-signup: crea il tenant, l'utente diventa owner
            //   invite_token → entra in un tenant esistente con il ruolo dell'invito
            // Nessuno dei due → utente senza tenant (può crearlo/riceverlo dopo).
            'company'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'invite_token' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $userModel = config('auth.providers.users.model');
        $email     = $request->input('email');
        $name      = $request->input('name');

        // Invito: deve esistere, essere pendente e combaciare con l'email.
        // Validato QUI (step 1) per dare errore subito, prima della biometrica.
        $invite = null;
        if ($request->filled('invite_token')) {
            $invite = $this->onboarding->findPendingInvite($request->input('invite_token'));

            if (! $invite) {
                return response()->json(['message' => 'Invito non valido o scaduto.'], 422);
            }

            if (strcasecmp($invite->email, $email) !== 0) {
                return response()->json(['message' => 'L\'invito è destinato a un altro indirizzo email.'], 422);
            }
        }

        // lockForUpdate() dentro la transazione evita la race condition:
        // due richieste concorrenti con la stessa email non possono entrambe
        // superare il check ed eseguire la CREATE simultaneamente.
        try {
            ['user' => $user, 'created' => $userWasCreated] = DB::transaction(
                function () use ($userModel, $email, $name) {
                    $existing = $userModel::where('email', $email)->lockForUpdate()->first();

                    if ($existing) {
                        $hasPasskeys = WebauthnKey::where('user_id', $existing->id)->exists();

                        if ($hasPasskeys) {
                            // CASO 2: account attivo → lancia eccezione per uscire dalla transazione
                            throw new RuntimeException('EMAIL_TAKEN');
                        }

                        // CASO 3: registrazione abbandonata — riusa il record esistente
                        return ['user' => $existing, 'created' => false];
                    }

                    // CASO 1: nuova email — crea l'utente
                    return [
                        'user'    => $userModel::create(['email' => $email, 'name' => $name]),
                        'created' => true,
                    ];
                }
            );
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'EMAIL_TAKEN') {
                return response()->json([
                    'message' => 'Email già registrata. Accedi dalla pagina di login.',
                ], 422);
            }
            throw $e;
        }

        // Genera challenge + opzioni per il browser.
        // prepareAttestation() è chiamata FUORI dalla transazione perché fa I/O
        // esterno (calcola la challenge). Se lancia, eliminiamo l'utente appena
        // creato per non lasciare record orfani — ma solo se lo abbiamo creato noi
        // (caso 3: record pre-esistente non va toccato).
        try {
            $publicKeyOptions = Webauthn::prepareAttestation($user);
        } catch (\Exception $e) {
            if ($userWasCreated) {
                $user->forceDelete();
            }
            return response()->json(['message' => 'Impossibile preparare la registrazione.'], 500);
        }

        // Salviamo l'ID utente in sessione per il secondo step.
        // company/invite restano in sessione: il tenant viene creato SOLO
        // a registrazione completata (step 2) — niente tenant orfani se
        // l'utente annulla la biometrica.
        session([
            'passkey_register_user_id'   => $user->id,
            'passkey_register_company'   => $request->input('company'),
            'passkey_register_invite_id' => $invite?->id,
        ]);

        return response()->json($publicKeyOptions);
    }

    /**
     * register() — Step 2
     *
     * Verifica l'attestazione ricevuta dal browser e salva la chiave pubblica.
     * Dopo la verifica, autentica l'utente automaticamente.
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'response' => ['required', 'array'],
            'key_name' => ['sometimes', 'string', 'max:255'],
        ]);

        // Recupera l'utente dalla sessione dello step 1
        $userId = session('passkey_register_user_id');
        if (! $userId) {
            return response()->json(['message' => 'Sessione di registrazione scaduta. Ricomincia.'], 422);
        }

        $userModel = config('auth.providers.users.model');
        $user = $userModel::find($userId);

        if (! $user) {
            return response()->json(['message' => 'Utente non trovato.'], 404);
        }

        $keyName = $request->input('key_name', 'Dispositivo ' . now()->format('d/m/Y'));

        try {
            // Verifica crittograficamente l'attestazione e salva la chiave pubblica.
            Webauthn::validateAttestation($user, $request->input('response'), $keyName);
        } catch (\Exception $e) {
            // Pulisce la sessione prima di eliminare l'utente: se forceDelete()
            // fallisce, un retry non trova la session key → 404 invece di loop.
            session()->forget('passkey_register_user_id');
            $user->forceDelete();

            return response()->json(['message' => 'Registrazione passkey fallita. Riprova.'], 422);
        }

        // Sovrascrive il nome con quello derivato dall'AAGUID se riconosciuto
        // (es. "Windows" → "iPhone / iPad" nel flusso cross-device via QR).
        $latestKey = WebauthnKey::where('user_id', $user->id)->latest('created_at')->first();
        if ($latestKey) {
            PasskeyDeviceNames::autoName($latestKey, $request->userAgent() ?? '');
        }

        // ONBOARDING TENANT — eseguito solo a passkey verificata:
        //   invito  → aggancia al tenant esistente con il ruolo dell'invito
        //   company → crea il tenant, l'utente è owner
        //   nessuno → utente senza tenant (self-signup rimandato)
        $inviteId = session('passkey_register_invite_id');
        $company  = session('passkey_register_company');

        if ($inviteId) {
            $invite = \SaaS\Core\Tenancy\Models\TenantInvite::find($inviteId);
            if ($invite && $invite->isPending()) {
                $this->onboarding->acceptInvite($invite, $user);
            }
        } elseif ($company) {
            $tenant = $this->onboarding->createTenant($company);
            $this->onboarding->attachOwner($user, $tenant);
        }

        // Pulisce la sessione di registrazione
        session()->forget(['passkey_register_user_id', 'passkey_register_company', 'passkey_register_invite_id']);

        // Autentica l'utente nella sessione web
        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['redirect' => config('saas-core.auth.redirect_after_login', '/dashboard')]);
    }

    /**
     * Step 1 per registrazione mobile nativa.
     *
     * Non usa sessione web: il client mobile rimanda email e device_id nello step 2.
     */
    public function optionsMobile(Request $request): JsonResponse
    {
        $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email', 'max:255'],
            // Vedi options(): flusso stateless — il client rimanda gli stessi
            // valori nello step 2 (registerMobile), qui li validiamo soltanto.
            'company'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'invite_token' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($request->filled('invite_token')) {
            $invite = $this->onboarding->findPendingInvite($request->input('invite_token'));

            if (! $invite) {
                return response()->json(['message' => 'Invito non valido o scaduto.'], 422);
            }

            if (strcasecmp($invite->email, $request->input('email')) !== 0) {
                return response()->json(['message' => 'L\'invito è destinato a un altro indirizzo email.'], 422);
            }
        }

        $userModel = config('auth.providers.users.model');
        $email     = $request->input('email');
        $name      = $request->input('name');

        try {
            ['user' => $user, 'created' => $userWasCreated] = DB::transaction(
                function () use ($userModel, $email, $name) {
                    $existing = $userModel::where('email', $email)->lockForUpdate()->first();

                    if ($existing) {
                        $hasPasskeys = WebauthnKey::where('user_id', $existing->id)->exists();

                        if ($hasPasskeys) {
                            throw new RuntimeException('EMAIL_TAKEN');
                        }

                        return ['user' => $existing, 'created' => false];
                    }

                    return [
                        'user'    => $userModel::create(['email' => $email, 'name' => $name]),
                        'created' => true,
                    ];
                }
            );
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'EMAIL_TAKEN') {
                return response()->json([
                    'message' => 'Email già registrata. Accedi dalla pagina di login.',
                ], 422);
            }
            throw $e;
        }

        try {
            $publicKeyOptions = Webauthn::prepareAttestation($user);
        } catch (\Exception $e) {
            if ($userWasCreated) {
                $user->forceDelete();
            }
            return response()->json(['message' => 'Impossibile preparare la registrazione.'], 500);
        }

        return response()->json($publicKeyOptions);
    }

    /**
     * Step 2 per registrazione mobile nativa.
     *
     * Verifica l'attestazione, salva la passkey e restituisce access/refresh token.
     */
    public function registerMobile(Request $request): JsonResponse
    {
        $request->validate([
            'email'     => ['required', 'email', 'max:255'],
            'response'  => ['required', 'array'],
            'device_id' => ['required', 'string', 'max:255'],
            'key_name'  => ['sometimes', 'string', 'max:255'],
            'company'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'invite_token' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $userModel = config('auth.providers.users.model');
        $user = $userModel::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['message' => 'Utente non trovato. Ricomincia la registrazione.'], 404);
        }

        $keyName = $request->input('key_name', 'Dispositivo mobile ' . now()->format('d/m/Y'));

        try {
            Webauthn::validateAttestation($user, $request->input('response'), $keyName);
        } catch (\Exception $e) {
            if (! WebauthnKey::where('user_id', $user->id)->exists()) {
                $user->forceDelete();
            }

            return response()->json(['message' => 'Registrazione passkey fallita. Riprova.'], 422);
        }

        $latestKey = WebauthnKey::where('user_id', $user->id)->latest('created_at')->first();
        if ($latestKey) {
            PasskeyDeviceNames::autoName($latestKey, $request->userAgent() ?? '');
        }

        // ONBOARDING TENANT (stateless: valori rimandati dal client nello step 2).
        // L'email dell'invito vince sempre sul campo email della request.
        if ($request->filled('invite_token')) {
            $invite = $this->onboarding->findPendingInvite($request->input('invite_token'));
            if ($invite && strcasecmp($invite->email, $user->email) === 0) {
                $this->onboarding->acceptInvite($invite, $user);
            }
        } elseif ($request->filled('company') && ! $user->tenant_id) {
            $tenant = $this->onboarding->createTenant($request->input('company'));
            $this->onboarding->attachOwner($user, $tenant);
        }

        // 'tenant' nella risposta: il client mobile lo salva in sessione e lo
        // usa come X-Tenant-ID — senza, resterebbe sul default di config.
        $tenantSlug = $user->tenant_id
            ? \SaaS\Core\Tenancy\Models\Tenant::find($user->tenant_id)?->slug
            : null;

        return response()->json(array_merge(
            $this->deviceTokenService->createTokenPair($user, $request->string('device_id')->toString()),
            ['tenant' => $tenantSlug],
        ));
    }
}
