<?php

namespace SaaS\Core\Auth\Totp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * TotpSetupController — Gestione enrollment TOTP dal profilo utente.
 *
 * FLUSSO ENROLLMENT:
 *
 *   1. GET  /profile/totp/setup
 *      Genera un segreto TOTP e lo salva (non confermato) sul profilo.
 *      Restituisce il segreto in base32 + l'URL otpauth:// per il QR code.
 *
 *   2. POST /profile/totp/confirm   { "code": "123456" }
 *      Verifica il codice inserito dall'utente — se corretto, segna il TOTP
 *      come confermato (two_factor_confirmed_at = now()).
 *      Restituisce i codici di recovery (unica volta in chiaro).
 *
 *   3. POST /profile/totp/disable   { "code": "123456" }
 *      Disabilita il TOTP — richiede il codice attuale per evitare
 *      che qualcuno con sessione rubata disabiliti il 2FA.
 *
 * SICUREZZA — segreto cifrato:
 *   Il segreto TOTP viene cifrato con APP_KEY prima di salvarlo nel DB.
 *   Anche se il database viene compromesso, il segreto non è leggibile
 *   senza APP_KEY. Fortify fa lo stesso con two_factor_secret.
 *
 * CODICI DI RECOVERY:
 *   Generati alla conferma del TOTP, mostrati una sola volta.
 *   Salvati come hash (bcrypt) nel DB — non in chiaro, non recuperabili.
 *   L'utente DEVE salvarli al momento della generazione.
 */
class TotpSetupController extends Controller
{
    public function __construct(
        protected readonly TwoFactorAuthenticationProvider $totp
    ) {}

    /**
     * setup() — Genera il segreto TOTP e restituisce l'URL per il QR code.
     *
     * Il segreto viene salvato subito ma con two_factor_confirmed_at = null
     * (non ancora confermato). Il TOTP non è attivo finché l'utente non
     * chiama confirm() con un codice valido.
     *
     * Questo previene che qualcuno intercetti il QR e lo usi prima dell'utente:
     * senza conferma, il segreto generato non è ancora attivo.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        // Se l'utente ha già TOTP confermato, non sovrascrivere il segreto
        // senza prima disabilitarlo. Protezione contro reset non autorizzati.
        if ($user->two_factor_confirmed_at !== null) {
            return response()->json([
                'message' => 'TOTP già attivo. Disabilitalo prima di riconfigurarlo.',
            ], 422);
        }

        // Genera un nuovo segreto a 16 byte (128 bit) in base32
        $secret = $this->totp->generateSecretKey();

        $user->forceFill([
            // Cifra il segreto con APP_KEY — non salvare mai in chiaro
            'two_factor_secret'       => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => null, // ancora non confermato
        ])->save();

        // otpauth:// URL standard — compatibile con Google Authenticator, Authy, 1Password
        $qrUrl = $this->totp->qrCodeUrl(
            config('app.name', 'SaaS'),
            $user->email,
            $secret
        );

        return response()->json([
            'secret'     => $secret,   // mostrato solo per inserimento manuale
            'qr_url'     => $qrUrl,    // il frontend genera il QR da questo URL
            'app_name'   => config('app.name'),
            'account'    => $user->email,
        ]);
    }

    /**
     * confirm() — Verifica il primo codice TOTP e attiva il secondo fattore.
     *
     * Questo è il momento in cui il TOTP diventa operativo.
     * Restituisce i codici di recovery — mostrati UNA SOLA VOLTA.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = $request->user();

        if (! $user->two_factor_secret) {
            return response()->json([
                'message' => 'Nessun segreto TOTP generato. Chiama prima /profile/totp/setup.',
            ], 422);
        }

        if ($user->two_factor_confirmed_at !== null) {
            return response()->json([
                'message' => 'TOTP già confermato.',
            ], 422);
        }

        $secret = Crypt::decryptString($user->two_factor_secret);

        if (! $this->totp->verify($secret, $request->input('code'))) {
            return response()->json([
                'message' => 'Codice TOTP non valido. Verifica l\'orologio del tuo dispositivo.',
            ], 422);
        }

        // Genera 8 codici di recovery monouso
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at'   => now(),
            // Salvati come hash: l'utente DEVE copiarli adesso
            'two_factor_recovery_codes' => encrypt(json_encode(
                array_map(fn ($code) => Hash::make($code), $recoveryCodes)
            )),
        ])->save();

        return response()->json([
            'message'        => 'TOTP attivato con successo.',
            // Codici in chiaro: mostrati solo qui, poi non recuperabili
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * disable() — Disabilita il TOTP richiedendo il codice corrente.
     *
     * Richiede il codice attuale per prevenire che una sessione web
     * rubata (es. XSS) possa disabilitare il secondo fattore silenziosamente.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = $request->user();

        if (! $user->two_factor_confirmed_at) {
            return response()->json([
                'message' => 'TOTP non attivo.',
            ], 422);
        }

        $secret = Crypt::decryptString($user->two_factor_secret);

        if (! $this->totp->verify($secret, $request->input('code'))) {
            return response()->json([
                'message' => 'Codice TOTP non valido.',
            ], 422);
        }

        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ])->save();

        return response()->json(['message' => 'TOTP disabilitato.']);
    }

    /**
     * Genera 8 codici di recovery nel formato XXXXX-XXXXX (leggibili, no ambiguità).
     *
     * Il formato usa solo lettere e numeri non ambigui (no 0/O, no 1/l/I).
     *
     * @return array<string>
     */
    private function generateRecoveryCodes(): array
    {
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $codes = [];

        for ($i = 0; $i < 8; $i++) {
            $part1 = '';
            $part2 = '';

            for ($j = 0; $j < 5; $j++) {
                $part1 .= $chars[random_int(0, strlen($chars) - 1)];
                $part2 .= $chars[random_int(0, strlen($chars) - 1)];
            }

            $codes[] = "{$part1}-{$part2}";
        }

        return $codes;
    }
}
