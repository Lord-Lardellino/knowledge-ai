<?php

namespace SaaS\Core\Auth\Mobile;

use Illuminate\Foundation\Auth\User;

/**
 * DeviceTokenService
 *
 * Gestisce la creazione dei token Sanctum per dispositivi mobile (React Native).
 *
 * PERCHÉ DUE TOKEN?
 *   Access token  → vita breve (24h default). Viene mandato in ogni request API.
 *                   Se intercettato, ha una finestra di danno limitata.
 *   Refresh token → vita lunga (30gg default). Usato SOLO per ottenere
 *                   una nuova coppia di token quando l'access scade.
 *                   Non viene mai mandato nelle request normali.
 *
 * COME FUNZIONA IN REACT NATIVE:
 *   1. Login via passkey → riceve { access_token, refresh_token }
 *   2. Ogni chiamata API → Header: Authorization: Bearer <access_token>
 *   3. Access token scaduto → POST /auth/mobile/refresh con refresh_token
 *   4. Riceve nuova coppia → vecchio refresh_token revocato (rotazione)
 *
 * DEVICE ID:
 *   Ogni dispositivo ha un ID univoco (es. UUID generato al primo avvio dell'app).
 *   Lo includiamo nel nome del token così puoi vedere quali dispositivi
 *   sono attivi nell'account e revocare singolarmente (es. "Esci da tutti i device").
 *
 * DIPENDE DA: laravel/sanctum (già incluso nel package)
 */
class DeviceTokenService
{
    /**
     * Crea una coppia access + refresh token per un dispositivo.
     *
     * Restituisce un array con i token in plaintext — sono mostrati UNA SOLA VOLTA.
     * Dopo la creazione Sanctum salva solo l'hash nel DB, non il valore originale.
     *
     * @param  User   $user      Utente autenticato
     * @param  string $deviceId  ID univoco del dispositivo (UUID dall'app mobile)
     * @return array{ access_token: string, refresh_token: string, token_type: string, expires_in: int }
     */
    public function createTokenPair(User $user, string $deviceId): array
    {
        // Revoca eventuali token precedenti per questo device.
        // Un solo login attivo per device: previene sessioni zombie.
        $this->revokeDeviceTokens($user, $deviceId);

        $accessTtl  = config('saas-core.auth.token_ttl', 86400);      // secondi
        $refreshTtl = config('saas-core.auth.refresh_ttl', 2592000);  // secondi

        // Access token: ability 'access' → autorizza le chiamate API normali
        // Il nome include device_id per identificare il device nel pannello admin
        $accessToken = $user->createToken(
            name: "access:{$deviceId}",
            abilities: ['access'],
            expiresAt: now()->addSeconds($accessTtl)
        );

        // Refresh token: ability 'refresh' → autorizza SOLO il rinnovo dei token
        // Non deve mai essere accettato dagli endpoint API normali
        $refreshToken = $user->createToken(
            name: "refresh:{$deviceId}",
            abilities: ['refresh'],
            expiresAt: now()->addSeconds($refreshTtl)
        );

        return [
            'access_token'  => $accessToken->plainTextToken,
            'refresh_token' => $refreshToken->plainTextToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $accessTtl,
        ];
    }

    /**
     * Crea un token "pending TOTP" — passkey verificata, secondo fattore no.
     *
     * Vita breve (10 minuti) e UNICA ability 'totp-pending': puo chiamare
     * solo POST /auth/mobile/totp/verify. Niente 'access' ne 'refresh' —
     * finche il codice TOTP non e verificato l'app non puo fare nulla.
     *
     * @return array{ pending_token: string, token_type: string, expires_in: int }
     */
    public function createTotpPendingToken(User $user, string $deviceId): array
    {
        // Revoca eventuali pending precedenti per questo device
        $user->tokens()
            ->where('name', "totp-pending:{$deviceId}")
            ->delete();

        $ttl = 600; // 10 minuti: abbondante per aprire l'app authenticator

        $token = $user->createToken(
            name: "totp-pending:{$deviceId}",
            abilities: ['totp-pending'],
            expiresAt: now()->addSeconds($ttl)
        );

        return [
            'pending_token' => $token->plainTextToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $ttl,
        ];
    }

    /**
     * Revoca tutti i token attivi per un dispositivo specifico.
     * Usato prima di creare nuovi token (evita duplicati) e nel logout.
     */
    public function revokeDeviceTokens(User $user, string $deviceId): void
    {
        // Cancella dal DB tutti i token il cui nome contiene il device_id
        // Sia access che refresh vengono revocati
        $user->tokens()
            ->where('name', 'like', "%:{$deviceId}")
            ->delete();
    }

    /**
     * Revoca tutti i token dell'utente su tutti i dispositivi.
     * Usato per: cambio password, account compromesso, logout globale.
     */
    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }
}
