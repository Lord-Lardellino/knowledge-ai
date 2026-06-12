<?php

namespace SaaS\Core\Auth\Passkeys;

use LaravelWebauthn\Models\WebauthnKey;

/**
 * PasskeyDeviceNames
 *
 * Mappa AAGUID → nome leggibile del dispositivo autenticatore.
 *
 * L'AAGUID (Authenticator Attestation GUID) identifica univocamente il tipo
 * di autenticatore fisico: iPhone Face ID, Windows Hello, YubiKey 5, ecc.
 * È affidabile anche nel flusso cross-device (iPhone che autentica via QR
 * code su un PC Windows): il client JS direbbe "Windows", ma l'AAGUID
 * rivela che l'autenticatore fisico è un iPhone.
 *
 * FONTE: FIDO Alliance Metadata Service (MDS3) + lista curata.
 * Per aggiornamenti: https://mds.fidoalliance.org
 */
class PasskeyDeviceNames
{
    private static array $map = [

        // ---- Apple -------------------------------------------------------
        'adce0002-35bc-c60a-648b-0b25f1f05503' => 'iPhone / iPad',
        'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => 'Mac (Touch ID)',
        'fbefdf68-fe86-0246-8b0e-1dfb2a02c1b9' => 'iPhone / iPad (iCloud Keychain)',
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'iPhone / iPad (iCloud Keychain)',
        'b84e4048-15dc-4dd0-8640-f4f60813c8af' => 'iPhone / iPad (iCloud Keychain)',

        // ---- Windows Hello -----------------------------------------------
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
        '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello',
        '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => 'Windows Hello (PIN)',
        'b91b3d2b-78f9-4218-af0b-37dc6a45d0d0' => 'Windows Hello (TPM)',

        // ---- Google / Android --------------------------------------------
        'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Android (Google Password Manager)',
        'b93fd961-f2e6-462f-b122-82002247de78' => 'Android (Authenticator)',
        '39a5647e-1853-446c-a1f6-a79bae9f5bc7' => 'Android',

        // ---- Samsung -----------------------------------------------------
        '42b4fb4a-2866-43b2-9bf7-6c6669c2e5d3' => 'Samsung Pass',

        // ---- YubiKey (Yubico) --------------------------------------------
        '2fc0579f-8113-47ea-b116-bb5a8db9202a' => 'YubiKey 5 NFC',
        'c1f9a0bc-1dd2-404a-b27f-8e29047a43fd' => 'YubiKey 5C NFC',
        'ee882879-721c-4913-9775-3dfcce97072a' => 'YubiKey 5 NFC',
        '149a2021-3ef5-4598-b35e-149b8eded856' => 'YubiKey 5Ci',
        '83c47309-aabb-4108-8470-8be838b573cb' => 'YubiKey Bio',
        '0bb43545-fd2c-4185-87dd-feb0b2916ace' => 'Security Key by Yubico',
        '6d44ba9b-f6ec-2e49-b930-0c8fe920cb73' => 'YubiKey 5 FIPS',
        'a4e9fc6d-4cbe-4758-b8ba-37598bb5bbaa' => 'YubiKey 5 FIPS NFC',
        '73bb0cd4-e502-49b8-9c6f-b59445bf720b' => 'YubiKey 5C NFC FIPS',

        // ---- Password manager / software keys ----------------------------
        'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
        'fdb141b2-5d84-443e-8a35-4698c205a502' => 'KeePassXC',

        // ---- Feitian -------------------------------------------------------
        'b6ede29c-3772-412c-8a78-539c1f4c62d2' => 'Feitian ePass FIDO2',
        '12ded745-4bed-47d4-abaa-e713f51d6393' => 'Feitian AllinPass FIDO2',
        '77010bd7-212a-4fc9-b236-d2ca5e9d4084' => 'Feitian BioPass FIDO2',
    ];

    /**
     * Risolve l'AAGUID nel nome leggibile del dispositivo.
     *
     * Restituisce null se l'AAGUID è nil (autenticatore non attestato)
     * o non presente nella mappa — il chiamante decide il fallback.
     *
     * @param  mixed  $aaguid  UUID object (Symfony\Uid) o stringa
     */
    public static function resolve(mixed $aaguid): ?string
    {
        if ($aaguid === null) {
            return null;
        }

        $uuid = strtolower((string) $aaguid);

        if ($uuid === '00000000-0000-0000-0000-000000000000') {
            return null;
        }

        return self::$map[$uuid] ?? null;
    }

    /**
     * Costruisce il nome automatico combinando dispositivo (AAGUID) + browser (UA).
     *
     * Esempi:
     *   iPhone cross-device su Chrome Windows → "iPhone / iPad (iCloud Keychain) · Chrome"
     *   Windows Hello su Edge               → "Windows Hello · Edge"
     *   AAGUID sconosciuto su Firefox        → "Firefox"
     *
     * @param  mixed   $aaguid     UUID dell'autenticatore
     * @param  string  $userAgent  HTTP User-Agent del browser che ha fatto la richiesta
     */
    /**
     * Applica il nome auto-rilevato alla passkey.
     * Centralizza il pattern usato sia in registrazione che in management.
     */
    public static function autoName(WebauthnKey $key, string $userAgent): void
    {
        $key->update(['name' => self::buildName($key->aaguid, $userAgent)]);
    }

    public static function buildName(mixed $aaguid, string $userAgent): string
    {
        $device  = self::resolve($aaguid);
        $browser = self::detectBrowser($userAgent);

        return $device !== null ? "{$device} · {$browser}" : $browser;
    }

    /**
     * Rileva il nome del browser dal User-Agent.
     * L'ordine dei controlli è importante (Edge e Samsung contengono "Chrome").
     */
    public static function detectBrowser(string $userAgent): string
    {
        if (str_contains($userAgent, 'Edg/') || str_contains($userAgent, 'Edge/')) {
            return 'Edge';
        }
        if (str_contains($userAgent, 'SamsungBrowser/')) {
            return 'Samsung Internet';
        }
        if (str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera/')) {
            return 'Opera';
        }
        if (str_contains($userAgent, 'Chrome/')) {
            return 'Chrome';
        }
        if (str_contains($userAgent, 'Firefox/')) {
            return 'Firefox';
        }
        if (str_contains($userAgent, 'Safari/') && str_contains($userAgent, 'Version/')) {
            return 'Safari';
        }

        return 'Browser';
    }
}
