<?php

use SaaS\Core\Auth\Mobile\DeviceTokenService;
use SaaS\Core\Tests\Models\User;

/**
 * Test unitari per DeviceTokenService.
 *
 * Usiamo il TestCase base che esegue migrate:fresh prima di ogni test,
 * così Sanctum (personal_access_tokens) e users sono sempre puliti.
 */

beforeEach(function () {
    $this->service = new DeviceTokenService();

    // Crea un utente reale nel DB — serve HasApiTokens per createToken()
    $this->user = User::create([
        'name'     => 'Test User',
        'email'    => 'test@example.com',
        'password' => null,
    ]);
});

// ---------------------------------------------------------------------------
// createTokenPair()
// ---------------------------------------------------------------------------

it('crea una coppia access e refresh token per il device', function () {
    $tokens = $this->service->createTokenPair($this->user, 'device-uuid-123');

    expect($tokens)->toHaveKeys(['access_token', 'refresh_token', 'token_type', 'expires_in']);
    expect($tokens['token_type'])->toBe('Bearer');
    expect($tokens['expires_in'])->toBeInt()->toBeGreaterThan(0);
    expect($tokens['access_token'])->toBeString()->not->toBeEmpty();
    expect($tokens['refresh_token'])->toBeString()->not->toBeEmpty();
});

it('access token e refresh token sono valori diversi', function () {
    $tokens = $this->service->createTokenPair($this->user, 'device-uuid-123');

    expect($tokens['access_token'])->not->toBe($tokens['refresh_token']);
});

it('crea esattamente due token nel DB per il device', function () {
    $this->service->createTokenPair($this->user, 'device-uuid-123');

    expect($this->user->tokens()->count())->toBe(2);
});

it('revoca i token precedenti dello stesso device prima di crearne di nuovi', function () {
    $this->service->createTokenPair($this->user, 'device-uuid-123');
    expect($this->user->tokens()->count())->toBe(2);

    // Secondo login dallo stesso device
    $this->service->createTokenPair($this->user, 'device-uuid-123');

    // Devono esserci ancora solo 2 token — i vecchi sono stati revocati
    expect($this->user->tokens()->count())->toBe(2);
});

it('device diversi hanno token indipendenti', function () {
    $this->service->createTokenPair($this->user, 'device-A');
    $this->service->createTokenPair($this->user, 'device-B');

    // 2 device × 2 token = 4 token totali
    expect($this->user->tokens()->count())->toBe(4);
});

// ---------------------------------------------------------------------------
// revokeDeviceTokens()
// ---------------------------------------------------------------------------

it('revoca solo i token del device specificato', function () {
    $this->service->createTokenPair($this->user, 'device-A');
    $this->service->createTokenPair($this->user, 'device-B');

    $this->service->revokeDeviceTokens($this->user, 'device-A');

    expect($this->user->tokens()->count())->toBe(2);
    expect($this->user->tokens()->where('name', 'like', '%:device-B')->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// revokeAllTokens()
// ---------------------------------------------------------------------------

it('revoca tutti i token su tutti i device', function () {
    $this->service->createTokenPair($this->user, 'device-A');
    $this->service->createTokenPair($this->user, 'device-B');
    $this->service->createTokenPair($this->user, 'device-C');

    $this->service->revokeAllTokens($this->user);

    expect($this->user->tokens()->count())->toBe(0);
});
