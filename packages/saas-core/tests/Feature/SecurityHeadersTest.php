<?php

use Illuminate\Support\Facades\Route;

/**
 * Test per SecurityHeaders middleware.
 *
 * Verifica che gli header HTTP di sicurezza vengano aggiunti correttamente
 * ad ogni response, e che HSTS sia condizionato a HTTPS.
 */

beforeEach(function () {
    // Route di test senza HTTPS: simula development
    Route::middleware('security.headers')->get('/test-headers', function () {
        return response('ok');
    });

    // Route di test con HTTPS simulato
    Route::middleware('security.headers')->get('/test-headers-secure', function () {
        return response('ok');
    });
});

it('aggiunge X-Frame-Options DENY', function () {
    $this->get('/test-headers')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('aggiunge X-Content-Type-Options nosniff', function () {
    $this->get('/test-headers')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('aggiunge Referrer-Policy strict-origin-when-cross-origin', function () {
    $this->get('/test-headers')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('aggiunge Permissions-Policy con API disabilitate', function () {
    $response = $this->get('/test-headers');
    $policy   = $response->headers->get('Permissions-Policy');

    expect($policy)->toContain('camera=()')
        ->and($policy)->toContain('microphone=()')
        ->and($policy)->toContain('geolocation=()');
});

it('non aggiunge HSTS su HTTP', function () {
    // get() usa HTTP di default nei test
    $this->get('/test-headers')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('aggiunge HSTS con max-age dalla config su HTTPS', function () {
    config(['saas-core.security.hsts_max_age' => 31536000]);

    // https:// nell'URL fa sì che Symfony riconosca la request come sicura
    $response = $this->get('https://localhost/test-headers-secure');

    $hsts = $response->headers->get('Strict-Transport-Security');
    expect($hsts)->toBeString()
        ->toContain('max-age=31536000')
        ->toContain('includeSubDomains');
});

it('rispetta il valore hsts_max_age personalizzato', function () {
    config(['saas-core.security.hsts_max_age' => 63072000]); // 2 anni

    $response = $this->get('https://localhost/test-headers-secure');

    expect($response->headers->get('Strict-Transport-Security'))
        ->toBeString()
        ->toContain('max-age=63072000');
});
