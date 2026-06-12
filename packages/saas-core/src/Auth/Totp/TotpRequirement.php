<?php

namespace SaaS\Core\Auth\Totp;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * TotpRequirement — logica TOTP condivisa tra web e mobile.
 *
 * Prima di questa classe la stessa logica viveva duplicata in
 * TotpMiddleware e TotpChallengeController; con l'arrivo del flusso
 * mobile (MobileTotpController) sarebbero diventate tre copie.
 *
 * RESPONSABILITÀ:
 *   isRequiredFor()       → l'utente deve completare il TOTP?
 *   verifyCodeOrRecovery() → valida un codice 6 cifre O un recovery code
 *                            (consumandolo: i recovery sono monouso)
 */
class TotpRequirement
{
    /**
     * L'utente deve completare il TOTP per questa autenticazione?
     *
     * Condizioni:
     *   1. Il TOTP è confermato (two_factor_confirmed_at != null)
     *   2. 'totp_required_roles' è vuota (= tutti quelli che l'hanno attivato)
     *      oppure contiene uno dei ruoli dell'utente
     */
    public static function isRequiredFor(mixed $user): bool
    {
        if (! $user || ! $user->two_factor_confirmed_at) {
            return false;
        }

        $requiredRoles = config('saas-core.auth.totp_required_roles', []);

        if (empty($requiredRoles)) {
            return true;
        }

        return method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole($requiredRoles);
    }

    /**
     * Verifica un codice TOTP (6 cifre) oppure un recovery code (monouso).
     *
     * Restituisce:
     *   'totp'     → codice TOTP valido
     *   'recovery' → recovery code valido (è stato consumato)
     *   null       → nessuno dei due
     */
    public static function verifyCodeOrRecovery(
        mixed $user,
        string $code,
        TwoFactorAuthenticationProvider $totp
    ): ?string {
        // Prima prova il codice TOTP standard (6 cifre)
        if (ctype_digit($code) && strlen($code) === 6) {
            $secret = Crypt::decryptString($user->two_factor_secret);

            if ($totp->verify($secret, $code)) {
                return 'totp';
            }
        }

        // Poi prova i codici di recovery (formato XXXXX-XXXXX)
        if (self::consumeRecoveryCode($user, $code)) {
            return 'recovery';
        }

        return null;
    }

    /**
     * Verifica e consuma un recovery code (monouso, salvato come hash bcrypt).
     */
    private static function consumeRecoveryCode(mixed $user, string $code): bool
    {
        if (! $user->two_factor_recovery_codes) {
            return false;
        }

        $storedCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);

        if (! is_array($storedCodes)) {
            return false;
        }

        foreach ($storedCodes as $index => $hashedCode) {
            if (Hash::check($code, $hashedCode)) {
                unset($storedCodes[$index]);

                $user->forceFill([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values($storedCodes))),
                ])->save();

                return true;
            }
        }

        return false;
    }
}
