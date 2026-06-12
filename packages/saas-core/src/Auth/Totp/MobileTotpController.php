<?php

namespace SaaS\Core\Auth\Totp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use SaaS\Core\Auth\Mobile\DeviceTokenService;

/**
 * MobileTotpController — secondo fattore TOTP per le app native.
 *
 * PERCHÉ UN CONTROLLER SEPARATO:
 *   Il flusso web (TotpChallengeController) usa la sessione
 *   (`totp_verified` flag). Le app mobile sono stateless: usano
 *   un token Sanctum "pending" con ability 'totp-pending'.
 *
 * FLUSSO MOBILE COMPLETO:
 *   1. POST /auth/mobile/passkey/verify
 *      → utente con TOTP attivo: { totp_required: true, pending_token }
 *      → utente senza TOTP: { access_token, refresh_token } (come prima)
 *   2. L'app mostra il campo codice (TotpChallengeScreen)
 *   3. POST /auth/mobile/totp/verify con Bearer <pending_token> + { code }
 *      → codice valido: revoca il pending, emette { access_token, refresh_token }
 *      → codice errato: 422 (il pending resta valido per riprovare, max 10 min)
 *
 * SICUREZZA:
 *   - Il pending token ha SOLO l'ability 'totp-pending': non passa
 *     'abilities:refresh' né può essere usato come access token sulle
 *     route protette da 'abilities:access'.
 *   - throttle:login sulle route → max 5 tentativi/minuto contro brute
 *     force sui 6 cifre (finestra TOTP 30s → ~2-3 guess utili).
 */
class MobileTotpController extends Controller
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $totp,
        private readonly DeviceTokenService $deviceTokenService,
    ) {}

    /**
     * Verifica il codice TOTP (o recovery code) e completa il login mobile.
     *
     * Richiede il pending token emesso da PasskeyController::verifyMobile.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'code'      => ['required', 'string'],
            'device_id' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        if (! $user->two_factor_confirmed_at) {
            return response()->json(['message' => 'TOTP non configurato.'], 422);
        }

        $result = TotpRequirement::verifyCodeOrRecovery($user, $request->input('code'), $this->totp);

        if ($result === null) {
            return response()->json([
                'message' => 'Codice non valido. Prova con un codice di recovery se hai perso l\'app.',
            ], 422);
        }

        // Secondo fattore superato: il pending token ha esaurito il suo scopo.
        // createTokenPair() revoca comunque tutti i token del device
        // (access:, refresh:, totp-pending:) prima di emettere i nuovi.
        $tokens = $this->deviceTokenService->createTokenPair(
            $user,
            $request->string('device_id')->toString()
        );

        if ($result === 'recovery') {
            $tokens['message'] = 'Codice di recovery utilizzato. Genera nuovi codici appena possibile.';
        }

        return response()->json($tokens);
    }

    /**
     * Stato TOTP dell'utente — versione stateless di TotpChallengeController::status.
     *
     * Usato dall'app per decidere se mostrare la sezione 2FA come attiva.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'totp_enabled'  => $user->two_factor_confirmed_at !== null,
            'totp_required' => TotpRequirement::isRequiredFor($user),
        ]);
    }
}
