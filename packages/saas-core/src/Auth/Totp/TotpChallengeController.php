<?php

namespace SaaS\Core\Auth\Totp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * TotpChallengeController — Verifica il codice TOTP durante il login.
 *
 * QUANDO VIENE CHIAMATO:
 *   Dopo che l'utente ha completato la passkey (step 1 di autenticazione),
 *   se il suo ruolo richiede il TOTP e il TOTP è configurato, il frontend
 *   deve richiedere il codice 6 cifre prima di accedere al dashboard.
 *
 * FLUSSO:
 *   1. PasskeyController::verify() autentica l'utente
 *   2. Se TotpMiddleware rileva che il TOTP è richiesto e non ancora verificato,
 *      restituisce 403 con `{ "totp_required": true }`
 *   3. Il frontend mostra il campo per il codice
 *   4. POST /auth/totp/challenge { "code": "123456" }
 *   5. Se corretto: session flag `totp_verified = true`, redirect al dashboard
 *
 * RECOVERY CODE:
 *   Se l'utente ha perso l'app TOTP, può usare uno dei codici di recovery.
 *   I codici sono monouso — vengono marcati come usati dopo la verifica.
 *
 * SESSIONE:
 *   Usiamo `totp_verified` nella sessione anziché un campo DB per evitare
 *   state persistente: il flag dura quanto la sessione (120 min).
 *   Alla prossima sessione l'utente deve reinserire il codice.
 */
class TotpChallengeController extends Controller
{
    public function __construct(
        protected readonly TwoFactorAuthenticationProvider $totp
    ) {}

    /**
     * verify() — Verifica il codice TOTP (o il recovery code) durante il login.
     *
     * Imposta `totp_verified = true` nella sessione se il codice è corretto.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Non autenticato.'], 401);
        }

        if (! $user->two_factor_confirmed_at) {
            return response()->json(['message' => 'TOTP non configurato.'], 422);
        }

        // Logica di verifica condivisa con il flusso mobile (TotpRequirement)
        $result = TotpRequirement::verifyCodeOrRecovery($user, $request->input('code'), $this->totp);

        if ($result === 'totp') {
            $this->markTotpVerified($request);

            return response()->json([
                'redirect' => config('saas-core.auth.redirect_after_login', '/dashboard'),
            ]);
        }

        if ($result === 'recovery') {
            $this->markTotpVerified($request);

            return response()->json([
                'message'  => 'Codice di recovery utilizzato. Genera nuovi codici appena possibile.',
                'redirect' => config('saas-core.auth.redirect_after_login', '/dashboard'),
            ]);
        }

        return response()->json([
            'message' => 'Codice non valido. Prova con un codice di recovery se hai perso l\'app.',
        ], 422);
    }

    /**
     * status() — Controlla se il TOTP è richiesto per l'utente corrente.
     *
     * Usato dal frontend per decidere se mostrare il campo TOTP dopo il login.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['totp_required' => false]);
        }

        return response()->json([
            'totp_required' => TotpRequirement::isRequiredFor($user) && ! session('totp_verified'),
            'totp_enabled'  => $user->two_factor_confirmed_at !== null,
        ]);
    }

    /**
     * Segna il TOTP come verificato nella sessione corrente.
     */
    private function markTotpVerified(Request $request): void
    {
        session(['totp_verified' => true]);

        // Rigenera la sessione per prevenire session fixation dopo il secondo fattore
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }

}
