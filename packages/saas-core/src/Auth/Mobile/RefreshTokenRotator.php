<?php

namespace SaaS\Core\Auth\Mobile;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * RefreshTokenRotator
 *
 * Gestisce la rotazione del refresh token.
 *
 * COS'È LA ROTAZIONE?
 *   Ogni volta che usi il refresh token per ottenere nuovi token,
 *   il refresh token usato viene IMMEDIATAMENTE REVOCATO e ne viene
 *   emesso uno nuovo.
 *
 * PERCHÉ È IMPORTANTE?
 *   Se un attaccante ruba il refresh token e lo usa, tu (utente legittimo)
 *   riceverai un errore al prossimo rinnovo perché il token è già stato usato.
 *   Questo segnala che qualcosa non va → forza nuovo login.
 *
 * FLUSSO:
 *   App mobile → POST /auth/mobile/refresh
 *                Header: Authorization: Bearer <refresh_token>
 *   Server     → verifica che sia un token con ability 'refresh'
 *             → revoca il refresh token usato
 *             → emette nuova coppia access + refresh
 *             → risponde con la nuova coppia
 */
class RefreshTokenRotator
{
    public function __construct(
        private readonly DeviceTokenService $deviceTokenService
    ) {}

    /**
     * Esegue la rotazione del refresh token.
     *
     * Restituisce la nuova coppia di token, oppure null se il token
     * non è valido, non ha l'ability 'refresh', o è già scaduto.
     *
     * @return array{ access_token: string, refresh_token: string, token_type: string, expires_in: int }|null
     */
    public function rotate(Request $request): ?array
    {
        // Legge il token dalla request (Sanctum lo trova nell'header Authorization)
        $currentToken = $request->user()?->currentAccessToken();

        if (is_null($currentToken)) {
            return null;
        }

        // Verifica che questo token abbia esplicitamente l'ability 'refresh'.
        // Un access token normale non può essere usato per rinnovarsi.
        if (! $currentToken->can('refresh')) {
            return null;
        }

        // Estrae il device_id dal nome del token (formato "refresh:{device_id}")
        $deviceId = $this->extractDeviceId($currentToken->name);

        if (is_null($deviceId)) {
            return null;
        }

        $user = $request->user();

        // Revoca il refresh token attuale PRIMA di emettere il nuovo.
        // Ordine importante: se la creazione del nuovo fallisce, il vecchio
        // è già revocato → l'utente deve rifare il login (fail-safe).
        $currentToken->delete();

        // Crea e restituisce la nuova coppia per lo stesso device
        return $this->deviceTokenService->createTokenPair($user, $deviceId);
    }

    /**
     * Estrae il device_id dal nome del token.
     * Il formato è sempre "refresh:{device_id}" o "access:{device_id}".
     */
    private function extractDeviceId(string $tokenName): ?string
    {
        // Divide per ":" e prende tutto dopo il primo segmento
        $parts = explode(':', $tokenName, 2);

        return isset($parts[1]) ? $parts[1] : null;
    }
}
