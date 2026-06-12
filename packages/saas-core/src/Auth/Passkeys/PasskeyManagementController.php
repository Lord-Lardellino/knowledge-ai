<?php

namespace SaaS\Core\Auth\Passkeys;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use LaravelWebauthn\Facades\Webauthn;
use LaravelWebauthn\Models\WebauthnKey;

/**
 * PasskeyManagementController
 *
 * Gestisce le passkey di un utente già autenticato:
 *   - Lista le passkey registrate
 *   - Aggiunge una nuova passkey (secondo dispositivo, laptop di backup, ecc.)
 *   - Elimina una passkey (device smarrito/rubato)
 *
 * PROTEZIONE ELIMINAZIONE:
 *   Non si può eliminare l'ultima passkey se l'email non è verificata.
 *   Senza questa regola l'utente si chiuderebbe fuori dall'account.
 *
 * AGGIUNTA NUOVA PASSKEY (flusso 2 step):
 *   1. GET  /profile/passkeys/options  → prepareAttestation($user) → challenge
 *   2. navigator.credentials.create()  → biometrica → nuova coppia di chiavi
 *   3. POST /profile/passkeys          → validateAttestation($user, $response, $keyName)
 */
class PasskeyManagementController extends Controller
{
    /**
     * index() — lista tutte le passkey dell'utente autenticato
     *
     * Ritorna solo i campi utili al frontend — mai la chiave pubblica grezza.
     */
    public function index(Request $request): JsonResponse
    {
        $keys = WebauthnKey::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'type', 'created_at'])
            ->map(fn ($key) => [
                'id'            => $key->id,
                'name'          => $key->name,
                'type'          => $key->type,
                'registered_at' => $key->created_at->format('d/m/Y'),
            ]);

        return response()->json(['keys' => $keys]);
    }

    /**
     * options() — step 1 per aggiungere una nuova passkey
     *
     * Differenza dalla registrazione: l'utente è già autenticato,
     * quindi usiamo auth()->user() invece di creare un nuovo utente.
     */
    public function options(Request $request): JsonResponse
    {
        try {
            $publicKeyOptions = Webauthn::prepareAttestation($request->user());
        } catch (\Exception $e) {
            return response()->json(['message' => 'Impossibile preparare la registrazione.'], 500);
        }

        // Salviamo in sessione per il secondo step
        session(['passkey_add_pending' => true]);

        return response()->json($publicKeyOptions);
    }

    /**
     * store() — step 2 per aggiungere una nuova passkey
     *
     * Verifica l'attestazione e aggiunge la nuova chiave pubblica all'account.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'response' => ['required', 'array'],
            'key_name' => ['sometimes', 'string', 'max:255'],
        ]);

        if (! session('passkey_add_pending')) {
            return response()->json(['message' => 'Sessione scaduta. Ricomincia.'], 422);
        }

        $keyName = $request->input('key_name', 'Dispositivo ' . now()->format('d/m/Y'));

        try {
            Webauthn::validateAttestation(
                $request->user(),
                $request->input('response'),
                $keyName
            );
        } catch (\Exception $e) {
            return response()->json(['message' => 'Registrazione passkey fallita. Riprova.'], 422);
        }

        session()->forget('passkey_add_pending');

        // Sovrascrive il nome con quello derivato dall'AAGUID se riconosciuto.
        $latestKey = WebauthnKey::where('user_id', $request->user()->id)->latest('created_at')->first();
        if ($latestKey) {
            PasskeyDeviceNames::autoName($latestKey, $request->userAgent() ?? '');
        }

        return response()->json(['message' => 'Passkey aggiunta con successo.']);
    }

    /**
     * rename() — rinomina una passkey
     *
     * Permette all'utente di assegnare un nome significativo a una passkey
     * (es. "iPhone 15 personale" invece del nome auto-rilevato dal browser).
     */
    public function rename(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        /** @var WebauthnKey $key */
        $key = WebauthnKey::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $key->update(['name' => $request->input('name')]);

        return response()->json(['message' => 'Passkey rinominata.']);
    }

    /**
     * destroy() — elimina una passkey
     *
     * Sicurezza: verifica che la passkey appartenga all'utente autenticato
     * e che non sia l'ultima (a meno che l'email non sia verificata).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        try {
            DB::transaction(function () use ($user, $id) {
                /** @var WebauthnKey $key */
                $key = WebauthnKey::where('id', $id)
                                  ->where('user_id', $user->id)
                                  ->lockForUpdate()
                                  ->firstOrFail();

                // Recupera tutte le righe con lockForUpdate() — COUNT(*) FOR UPDATE
                // non è supportato da PostgreSQL. Il lock sulle righe serializza le
                // delete concorrenti: la seconda richiesta aspetta che la prima
                // completi la transazione prima di procedere con il suo conteggio.
                $totalKeys = WebauthnKey::where('user_id', $user->id)
                                        ->lockForUpdate()
                                        ->get(['id'])
                                        ->count();

                if ($totalKeys <= 1 && ! $user->hasVerifiedEmail()) {
                    throw new DomainException('LAST_KEY_NO_EMAIL');
                }

                $key->delete();
            });
        } catch (DomainException $e) {
            return response()->json([
                'message' => 'Non puoi eliminare l\'unica passkey senza un\'email verificata.',
            ], 422);
        }

        return response()->json(['message' => 'Passkey eliminata.']);
    }
}
