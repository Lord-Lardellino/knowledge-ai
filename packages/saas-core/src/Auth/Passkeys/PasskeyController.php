<?php

namespace SaaS\Core\Auth\Passkeys;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use LaravelWebauthn\Services\Webauthn;
use SaaS\Core\Auth\Mobile\DeviceTokenService;

/**
 * PasskeyController
 *
 * Gestisce il flusso di autenticazione passwordless via WebAuthn (passkey).
 *
 * DUE ENDPOINT:
 *   POST /auth/passkey/challenge  → prepara le opzioni WebAuthn e le invia al client
 *   POST /auth/passkey/verify     → verifica la firma e autentica l'utente
 *
 * PERCHÉ NON USIAMO PIÙ PasskeyChallenge CUSTOM:
 *   La challenge viene generata e memorizzata in Cache da
 *   Webauthn::prepareAssertion(), che la recupera automaticamente in
 *   Webauthn::validateAssertion(). Doppia gestione portava a:
 *     - $request->input('response.clientDataJSON.challenge') = null
 *       (clientDataJSON è base64, non array nested)
 *     - validateAssertion() non trovava le opzioni in cache (mai salvate)
 *
 * ANTI USER-ENUMERATION:
 *   Se l'email non è registrata, passiamo null a prepareAssertion() che
 *   restituisce opzioni valide ma senza allowedCredentials.
 *   Il client riceve sempre 200 — impossibile capire se l'email esiste.
 */
class PasskeyController extends Controller
{
    public function __construct(
        private readonly Webauthn $webauthn,
        private readonly DeviceTokenService $deviceTokenService
    ) {}

    /**
     * challenge()
     *
     * Step 1 del flusso passkey.
     * Genera le PublicKeyCredentialRequestOptions via laravel-webauthn,
     * che internamente salva la challenge in Cache.
     * Restituisce challenge + rpId + timeout al client.
     */
    public function challenge(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Cerca l'utente — se non esiste passiamo null (anti user-enumeration)
        $userModel = config('auth.providers.users.model');
        $user      = $userModel::where('email', $request->email)->first();

        // prepareAssertion() genera la challenge, la salva in Cache,
        // e restituisce il wrapper PublicKeyCredentialRequestOptions.
        // Con $user = null ritorna opzioni senza allowedCredentials.
        $options = Webauthn::prepareAssertion($user);

        // $options->data è il Webauthn\PublicKeyCredentialRequestOptionsBase
        // ->challenge è una stringa binaria raw — va codificata per il browser
        $challengeB64 = rtrim(strtr(base64_encode($options->data->challenge), '+/', '-_'), '=');

        return response()->json([
            'challenge' => $challengeB64,
            'rpId'      => $options->data->rpId ?? parse_url(config('app.url'), PHP_URL_HOST),
            'timeout'   => $options->data->timeout ?? 60000,
        ]);
    }

    /**
     * verify()
     *
     * Step 2 del flusso passkey.
     * validateAssertion() recupera le opzioni dalla Cache (messe da prepareAssertion),
     * verifica la challenge, la firma crittografica FIDO2 e il counter.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'response' => ['required', 'array'],
        ]);

        $userModel = config('auth.providers.users.model');
        $user      = $userModel::where('email', $request->email)->first();

        if (is_null($user)) {
            return response()->json(['message' => 'Credenziali non valide.'], 401);
        }

        // validateAssertion() gestisce internamente:
        //   - recupero delle opzioni dalla Cache (challenge, rpId, allowedCredentials)
        //   - verifica della challenge (clientDataJSON.challenge === stored_challenge)
        //   - verifica crittografica della firma FIDO2
        //   - aggiornamento del counter (anti replay)
        try {
            $valid = Webauthn::validateAssertion($user, $request->input('response'));
        } catch (\Exception $e) {
            return response()->json(['message' => 'Passkey non riconosciuta. Riprova o contatta il supporto.'], 401);
        }

        if (! $valid) {
            return response()->json(['message' => 'Passkey non riconosciuta. Riprova o contatta il supporto.'], 401);
        }

        // --- WEB: crea sessione Laravel ---
        // Le route passkey passano per il middleware 'web' → hanno sempre una sessione.
        // Il browser manda Accept: application/json (fetch), quindi expectsJson() = true
        // e non ha X-Inertia. Usiamo hasSession() per distinguere web da mobile API:
        // le chiamate React Native non hanno sessione (niente cookie/web middleware).
        if ($request->hasSession()) {
            Auth::login($user);
            $request->session()->regenerate();

            return response()->json(['redirect' => config('saas-core.auth.redirect_after_login', '/dashboard')]);
        }

        // --- MOBILE (React Native): Sanctum token ---
        // Le route mobile non passano per 'web' → nessuna sessione.
        $deviceId = $request->header('X-Device-ID', 'unknown');

        return response()->json($this->deviceTokenService->createTokenPair($user, $deviceId));
    }

    /**
     * Variante esplicita per app mobile.
     *
     * Verifica la passkey e restituisce sempre una coppia access/refresh token,
     * anche quando la richiesta arriva da una pagina browser con sessione web.
     */
    public function verifyMobile(Request $request): JsonResponse
    {
        $request->validate([
            'email'     => ['required', 'email'],
            'response'  => ['required', 'array'],
            'device_id' => ['required', 'string', 'max:255'],
        ]);

        $userModel = config('auth.providers.users.model');
        $user      = $userModel::where('email', $request->email)->first();

        if (is_null($user)) {
            return response()->json(['message' => 'Credenziali non valide.'], 401);
        }

        // react-native-passkeys include "attestationObject": null nelle risposte
        // di LOGIN (WebAuthn L3 lo permette come campo opzionale). Il denormalizer
        // di webauthn-lib sceglie il ramo attestation/assertion in base alla
        // PRESENZA della chiave → con null crasha su Base64::decode(null).
        // La rimuoviamo: per il login conta solo l'assertion.
        $assertion = $request->input('response');

        if (array_key_exists('attestationObject', $assertion['response'] ?? [])
            && $assertion['response']['attestationObject'] === null) {
            unset($assertion['response']['attestationObject']);
        }

        try {
            // \Throwable e non \Exception: i payload malformati fanno emergere
            // TypeError dalla libreria (estende Error) — senza questo catch
            // diventerebbero 500 invece di 401.
            $valid = Webauthn::validateAssertion($user, $assertion);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Passkey non riconosciuta. Riprova o contatta il supporto.'], 401);
        }

        if (! $valid) {
            return response()->json(['message' => 'Passkey non riconosciuta. Riprova o contatta il supporto.'], 401);
        }

        $deviceId = $request->string('device_id')->toString();

        // --- GATE 2FA ---
        // Se l'utente ha il TOTP attivo (e richiesto per il suo ruolo), la
        // passkey da sola NON basta: emettiamo solo un token "pending" con
        // ability totp-pending. I token veri arrivano da
        // POST /auth/mobile/totp/verify dopo il codice corretto.
        if (\SaaS\Core\Auth\Totp\TotpRequirement::isRequiredFor($user)) {
            return response()->json([
                'totp_required' => true,
            ] + $this->deviceTokenService->createTotpPendingToken($user, $deviceId));
        }

        return response()->json(
            $this->deviceTokenService->createTokenPair($user, $deviceId)
        );
    }
}
