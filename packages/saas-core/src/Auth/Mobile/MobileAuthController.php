<?php

namespace SaaS\Core\Auth\Mobile;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * MobileAuthController
 *
 * Gestisce le operazioni di autenticazione specifiche per React Native.
 *
 * ENDPOINT:
 *   POST /auth/mobile/refresh  → rinnova la coppia access/refresh token
 *   POST /auth/mobile/logout   → revoca tutti i token del device corrente
 *   POST /auth/mobile/logout-all → revoca tutti i token su tutti i device
 *
 * NOTA: Il login via passkey è gestito da PasskeyController che, per
 *       le request mobile (non browser), emette già la coppia di token.
 *       Questo controller gestisce solo le operazioni successive.
 */
class MobileAuthController extends Controller
{
    public function __construct(
        private readonly RefreshTokenRotator $rotator,
        private readonly DeviceTokenService $tokenService
    ) {}

    /**
     * refresh()
     *
     * Rinnova la coppia access + refresh token.
     * Richiede un refresh token valido nell'header Authorization.
     *
     * Middleware richiesto sulla route: auth:sanctum
     * Il middleware verifica il token e popola $request->user() prima
     * che questo metodo venga chiamato.
     */
    public function refresh(Request $request): JsonResponse
    {
        $tokens = $this->rotator->rotate($request);

        if (is_null($tokens)) {
            // Token non valido, scaduto, o senza ability 'refresh'
            return response()->json([
                'message' => 'Token di refresh non valido o scaduto. Effettua nuovamente il login.',
            ], 401);
        }

        return response()->json($tokens);
    }

    /**
     * logout()
     *
     * Revoca tutti i token del dispositivo corrente.
     * L'app mobile deve cancellare i token salvati localmente dopo questa chiamata.
     */
    public function logout(Request $request): JsonResponse
    {
        $deviceId = $request->header('X-Device-ID');

        if ($deviceId) {
            // Revoca solo i token di questo device
            $this->tokenService->revokeDeviceTokens($request->user(), $deviceId);
        } else {
            // Fallback: revoca solo il token corrente
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Logout effettuato.']);
    }

    /**
     * logoutAll()
     *
     * Revoca tutti i token dell'utente su tutti i dispositivi.
     * Utile in caso di: cambio password, account compromesso, "esci ovunque".
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->tokenService->revokeAllTokens($request->user());

        return response()->json(['message' => 'Logout effettuato su tutti i dispositivi.']);
    }
}
