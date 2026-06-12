<?php

use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * Test per auth.passkey_origins — origin WebAuthn delle app native.
 *
 * Le passkey native Android inviano un origin "android:apk-key-hash:<base64url>"
 * che webauthn-lib rifiuta di default ("Invalid scheme. HTTPS required.").
 * Il SaasCoreServiceProvider estende la CeremonyStepManagerFactory con
 * setAllowedOrigins() quando la config non è vuota, includendo sempre APP_URL
 * per non rompere il flusso browser.
 *
 * La proprietà allowedOrigins è privata: la leggiamo via reflection.
 * È il modo più diretto per verificare il comportamento dell'extend
 * senza dover montare un'intera ceremony WebAuthn.
 */

function allowedOriginsOf(CeremonyStepManagerFactory $factory): ?array
{
    $property = new ReflectionProperty($factory, 'allowedOrigins');

    return $property->getValue($factory);
}

it('senza passkey_origins la factory resta con il comportamento di default', function () {
    config(['saas-core.auth.passkey_origins' => []]);

    $factory = app(CeremonyStepManagerFactory::class);

    // null = nessun setAllowedOrigins chiamato → CheckOrigin standard (solo HTTPS)
    expect(allowedOriginsOf($factory))->toBeNull();
});

it('con passkey_origins la factory riceve gli origin extra più APP_URL', function () {
    config([
        'app.url' => 'https://acme.example.com',
        'saas-core.auth.passkey_origins' => [
            'android:apk-key-hash:-sYXRdwJA3hvue3mKpYrOZ9zSPC7b4mbgzJmdZEDO5w',
        ],
    ]);

    $factory = app(CeremonyStepManagerFactory::class);
    $origins = allowedOriginsOf($factory);

    expect($origins)
        ->toContain('https://acme.example.com')
        ->toContain('android:apk-key-hash:-sYXRdwJA3hvue3mKpYrOZ9zSPC7b4mbgzJmdZEDO5w');
});

it('gli origin duplicati vengono deduplicati', function () {
    config([
        'app.url' => 'https://acme.example.com',
        'saas-core.auth.passkey_origins' => [
            'https://acme.example.com',   // duplicato di APP_URL
            'android:apk-key-hash:AAAA',
        ],
    ]);

    $factory = app(CeremonyStepManagerFactory::class);
    $origins = allowedOriginsOf($factory);

    expect(array_count_values($origins)['https://acme.example.com'])->toBe(1)
        ->and($origins)->toHaveCount(2);
});

it('la chiave config legge WEBAUTHN_ALLOWED_ORIGINS come lista separata da virgole', function () {
    // Simula il parsing fatto in config/saas-core.php
    $raw    = 'android:apk-key-hash:AAA , ios:bundle-id:com.example.app,';
    $parsed = array_filter(array_map('trim', explode(',', $raw)));

    expect($parsed)->toBe([
        'android:apk-key-hash:AAA',
        'ios:bundle-id:com.example.app',
    ]);
});
