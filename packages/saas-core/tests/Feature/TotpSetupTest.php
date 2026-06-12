<?php

use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use SaaS\Core\Tests\Models\User;

/**
 * Test di feature per TotpSetupController.
 *
 * Copre l'enrollment TOTP dal profilo utente autenticato:
 *   GET  /profile/totp/setup   — genera segreto + URL QR
 *   POST /profile/totp/confirm — verifica primo codice, attiva TOTP
 *   POST /profile/totp/disable — disabilita TOTP (richiede codice corrente)
 *
 * STRATEGIA MOCK:
 *   TwoFactorAuthenticationProvider viene sostituito con un mock tramite
 *   app()->instance() per controllare generateSecretKey(), qrCodeUrl() e verify()
 *   senza dipendere dall'orologio di sistema o da codici TOTP reali.
 */

// Sostituisce il TwoFactorAuthenticationProvider con un mock controllabile.
// $verifyReturn: valore restituito da verify() — true = codice valido, false = invalido.
function swapTotpMock(bool $verifyReturn = true): object
{
    $mock = Mockery::mock(TwoFactorAuthenticationProvider::class);

    $mock->shouldReceive('generateSecretKey')
        ->andReturn('JBSWY3DPEHPK3PXP');

    $mock->shouldReceive('qrCodeUrl')
        ->andReturn('otpauth://totp/SaaS:mario%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=SaaS');

    $mock->shouldReceive('verify')
        ->andReturn($verifyReturn);

    app()->instance(TwoFactorAuthenticationProvider::class, $mock);

    return $mock;
}

// Crea un utente con TOTP già confermato (utile per test disable/double-setup).
function makeUserWithConfirmedTotp(): User
{
    $user = User::create([
        'name'              => 'Mario Rossi',
        'email'             => 'mario@example.com',
        'email_verified_at' => now(),
    ]);

    $user->forceFill([
        'two_factor_secret'         => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at'   => now(),
        'two_factor_recovery_codes' => encrypt(json_encode([
            \Illuminate\Support\Facades\Hash::make('ABCDE-FGHIJ'),
        ])),
    ])->save();

    return $user;
}

// ---------------------------------------------------------------------------
// Autenticazione richiesta su tutti gli endpoint
// ---------------------------------------------------------------------------

it('blocca GET /profile/totp/setup senza autenticazione', function () {
    $this->getJson('/profile/totp/setup')->assertStatus(401);
});

it('blocca POST /profile/totp/confirm senza autenticazione', function () {
    $this->postJson('/profile/totp/confirm', ['code' => '123456'])->assertStatus(401);
});

it('blocca POST /profile/totp/disable senza autenticazione', function () {
    $this->postJson('/profile/totp/disable', ['code' => '123456'])->assertStatus(401);
});

// ---------------------------------------------------------------------------
// setup() — Genera segreto TOTP e URL QR
// ---------------------------------------------------------------------------

it('setup restituisce segreto e URL QR', function () {
    swapTotpMock();

    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $this->actingAs($user)
        ->getJson('/profile/totp/setup')
        ->assertStatus(200)
        ->assertJsonStructure(['secret', 'qr_url', 'app_name', 'account'])
        ->assertJsonFragment([
            'secret'  => 'JBSWY3DPEHPK3PXP',
            'account' => 'mario@example.com',
        ]);
});

it('setup salva il segreto cifrato (non confermato) nel DB', function () {
    swapTotpMock();

    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $this->actingAs($user)->getJson('/profile/totp/setup')->assertStatus(200);

    $user->refresh();

    // Il segreto deve essere salvato cifrato — non in chiaro
    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_secret)->not->toBe('JBSWY3DPEHPK3PXP');
    // Non ancora confermato
    expect($user->two_factor_confirmed_at)->toBeNull();
});

it('setup fallisce se TOTP già confermato', function () {
    swapTotpMock();

    $user = makeUserWithConfirmedTotp();

    $this->actingAs($user)
        ->getJson('/profile/totp/setup')
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'TOTP già attivo. Disabilitalo prima di riconfigurarlo.']);
});

// ---------------------------------------------------------------------------
// confirm() — Verifica il primo codice e attiva il TOTP
// ---------------------------------------------------------------------------

it('confirm con codice valido attiva il TOTP e restituisce 8 recovery codes', function () {
    swapTotpMock(verifyReturn: true);

    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);
    $user->forceFill([
        'two_factor_secret'       => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => null,
    ])->save();

    $response = $this->actingAs($user)
        ->postJson('/profile/totp/confirm', ['code' => '123456'])
        ->assertStatus(200)
        ->assertJsonStructure(['message', 'recovery_codes']);

    // Deve restituire esattamente 8 codici di recovery
    $codes = $response->json('recovery_codes');
    expect($codes)->toHaveCount(8);
    // Formato XXXXX-XXXXX
    foreach ($codes as $code) {
        expect($code)->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/');
    }

    // Il TOTP deve essere confermato nel DB
    $user->refresh();
    expect($user->two_factor_confirmed_at)->not->toBeNull();
    // I codici nel DB sono hashed, non in chiaro
    expect($user->two_factor_recovery_codes)->not->toBeNull();
});

it('confirm con codice non valido restituisce 422', function () {
    swapTotpMock(verifyReturn: false);

    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);
    $user->forceFill([
        'two_factor_secret'       => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($user)
        ->postJson('/profile/totp/confirm', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Codice TOTP non valido. Verifica l\'orologio del tuo dispositivo.']);

    // Il TOTP NON deve essere confermato
    $user->refresh();
    expect($user->two_factor_confirmed_at)->toBeNull();
});

it('confirm senza setup precedente restituisce 422', function () {
    swapTotpMock();

    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $this->actingAs($user)
        ->postJson('/profile/totp/confirm', ['code' => '123456'])
        ->assertStatus(422);
});

it('confirm se TOTP già confermato restituisce 422', function () {
    swapTotpMock();

    $user = makeUserWithConfirmedTotp();

    $this->actingAs($user)
        ->postJson('/profile/totp/confirm', ['code' => '123456'])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'TOTP già confermato.']);
});

it('confirm valida che il campo code sia di 6 cifre', function () {
    swapTotpMock();

    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);
    $user->forceFill(['two_factor_secret' => Crypt::encryptString('SECRET')])->save();

    $this->actingAs($user)
        ->postJson('/profile/totp/confirm', ['code' => '12'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// disable() — Disabilita il TOTP richiedendo il codice corrente
// ---------------------------------------------------------------------------

it('disable con codice valido disattiva il TOTP', function () {
    swapTotpMock(verifyReturn: true);

    $user = makeUserWithConfirmedTotp();

    $this->actingAs($user)
        ->postJson('/profile/totp/disable', ['code' => '123456'])
        ->assertStatus(200)
        ->assertJsonFragment(['message' => 'TOTP disabilitato.']);

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
    expect($user->two_factor_recovery_codes)->toBeNull();
});

it('disable con codice non valido restituisce 422', function () {
    swapTotpMock(verifyReturn: false);

    $user = makeUserWithConfirmedTotp();

    $this->actingAs($user)
        ->postJson('/profile/totp/disable', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Codice TOTP non valido.']);

    // Il TOTP deve restare attivo
    $user->refresh();
    expect($user->two_factor_confirmed_at)->not->toBeNull();
});

it('disable se TOTP non attivo restituisce 422', function () {
    swapTotpMock();

    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $this->actingAs($user)
        ->postJson('/profile/totp/disable', ['code' => '123456'])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'TOTP non attivo.']);
});
