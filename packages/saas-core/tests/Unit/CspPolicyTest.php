<?php

use SaaS\Core\Security\Csp\SaasCorePreset;
use Spatie\Csp\Policy;

/**
 * Test per SaasCorePreset.
 *
 * Verifica che il preset CSP di default configuri correttamente le direttive
 * di sicurezza nella Policy di Spatie.
 *
 * Usiamo Policy::create([SaasCorePreset::class])->getContents() per ottenere
 * la stringa CSP reale e verificarne il contenuto.
 */

beforeEach(function () {
    // CspServiceProvider non è caricato nei test — registriamo il nonce manualmente.
    // Policy::addNonce() chiama app('csp-nonce') e lancia BindingResolutionException
    // se il binding non esiste. Con csp_nonce=true (default) questo accade sempre.
    app()->instance('csp-nonce', 'test-nonce-abc123');
});

it('la policy ha default-src self', function () {
    $contents = Policy::create([SaasCorePreset::class])->getContents();
    expect($contents)->toContain("default-src 'self'");
});

it('la policy blocca object-src con none', function () {
    $contents = Policy::create([SaasCorePreset::class])->getContents();
    expect($contents)->toContain("object-src 'none'");
});

it('la policy blocca frame-ancestors con none', function () {
    $contents = Policy::create([SaasCorePreset::class])->getContents();
    expect($contents)->toContain("frame-ancestors 'none'");
});

it('la policy limita form-action al proprio dominio', function () {
    $contents = Policy::create([SaasCorePreset::class])->getContents();
    expect($contents)->toContain("form-action 'self'");
});

it('la policy include nonce per script quando csp_nonce è true', function () {
    config(['saas-core.security.csp_nonce' => true]);
    // Fornisce un nonce fittizio al container (normalmente generato da Spatie)
    app()->instance('csp-nonce', 'test-nonce-abc123');

    $contents = Policy::create([SaasCorePreset::class])->getContents();
    expect($contents)->toContain("nonce-test-nonce-abc123");
});

it('la policy usa self per script quando csp_nonce è false', function () {
    config(['saas-core.security.csp_nonce' => false]);

    $contents = Policy::create([SaasCorePreset::class])->getContents();
    expect($contents)->toContain("script-src 'self'");
});
