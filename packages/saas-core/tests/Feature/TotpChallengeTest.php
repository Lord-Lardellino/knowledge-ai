<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use SaaS\Core\Tests\Models\User;

/**
 * Test di feature per TotpChallengeController.
 *
 * Copre la verifica del TOTP durante il login (post-passkey):
 *   POST /auth/totp/challenge  — verifica codice 6 cifre o recovery code
 *   GET  /auth/totp/status     — stato TOTP per l'utente corrente
 *
 * STRATEGIA MOCK:
 *   TwoFactorAuthenticationProvider mockato via app()->instance() — identico
 *   al pattern usato in TotpSetupTest.
 */

// Crea un utente con TOTP confermato e un recovery code noto.
// Il recovery code in chiaro è 'ABCDE-FGHIJ' — salvato come hash nel DB.
function makeUserWithActivatedTotp(string $secret = 'JBSWY3DPEHPK3PXP'): User
{
    $user = User::create([
        'name'              => 'Mario Rossi',
        'email'             => 'mario@example.com',
        'email_verified_at' => now(),
    ]);

    $user->forceFill([
        'two_factor_secret'         => Crypt::encryptString($secret),
        'two_factor_confirmed_at'   => now(),
        'two_factor_recovery_codes' => encrypt(json_encode([
            Hash::make('ABCDE-FGHIJ'),
            Hash::make('KKKKK-LLLLL'),
        ])),
    ])->save();

    return $user;
}

// Inietta un mock del provider TOTP che approva o rifiuta il codice.
function swapChallengeMock(bool $verifyReturn): void
{
    $mock = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $mock->shouldReceive('verify')->andReturn($verifyReturn);
    app()->instance(TwoFactorAuthenticationProvider::class, $mock);
}

// ---------------------------------------------------------------------------
// verify() — Verifica codice TOTP standard (6 cifre)
// ---------------------------------------------------------------------------

it('verify con codice valido imposta totp_verified in sessione e redirect', function () {
    swapChallengeMock(verifyReturn: true);

    $user = makeUserWithActivatedTotp();

    $this->actingAs($user)
        ->postJson('/auth/totp/challenge', ['code' => '123456'])
        ->assertStatus(200)
        ->assertJsonStructure(['redirect']);
});

it('verify con codice non valido restituisce 422', function () {
    swapChallengeMock(verifyReturn: false);

    $user = makeUserWithActivatedTotp();

    $this->actingAs($user)
        ->postJson('/auth/totp/challenge', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Codice non valido. Prova con un codice di recovery se hai perso l\'app.']);
});

it('verify senza autenticazione restituisce 401', function () {
    $this->postJson('/auth/totp/challenge', ['code' => '123456'])
        ->assertStatus(401);
});

it('verify con TOTP non configurato restituisce 422', function () {
    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $this->actingAs($user)
        ->postJson('/auth/totp/challenge', ['code' => '123456'])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'TOTP non configurato.']);
});

it('verify valida che il campo code sia presente', function () {
    $user = makeUserWithActivatedTotp();

    $this->actingAs($user)
        ->postJson('/auth/totp/challenge', [])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// verify() — Recovery codes (monouso)
// ---------------------------------------------------------------------------

it('verify con recovery code valido autentica e consuma il codice', function () {
    // Non serve mock del TOTP provider: i recovery code bypassano verify()
    $user = makeUserWithActivatedTotp();

    $this->actingAs($user)
        ->postJson('/auth/totp/challenge', ['code' => 'ABCDE-FGHIJ'])
        ->assertStatus(200)
        ->assertJsonStructure(['redirect']);

    // Il codice deve essere stato rimosso dalla lista
    $user->refresh();
    $remainingCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
    expect($remainingCodes)->toHaveCount(1);
});

it('verify con recovery code usato una seconda volta fallisce', function () {
    $user = makeUserWithActivatedTotp();

    // Primo uso: successo
    $this->actingAs($user)
        ->postJson('/auth/totp/challenge', ['code' => 'ABCDE-FGHIJ'])
        ->assertStatus(200);

    // Secondo uso dello stesso codice: deve fallire
    // (non possiamo riusare $this->actingAs sulla stessa sessione, creiamo una nuova request)
    $user->refresh();

    swapChallengeMock(verifyReturn: false); // il codice a 6 cifre fallisce anche

    $this->actingAs($user)
        ->postJson('/auth/totp/challenge', ['code' => 'ABCDE-FGHIJ'])
        ->assertStatus(422);
});

it('verify con recovery code inesistente restituisce 422', function () {
    swapChallengeMock(verifyReturn: false);

    $user = makeUserWithActivatedTotp();

    $this->actingAs($user)
        ->postJson('/auth/totp/challenge', ['code' => 'ZZZZZ-ZZZZZ'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// status() — Stato TOTP per l'utente corrente
// ---------------------------------------------------------------------------

it('status restituisce totp_required false se TOTP non configurato', function () {
    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $this->actingAs($user)
        ->getJson('/auth/totp/status')
        ->assertStatus(200)
        ->assertJson([
            'totp_required' => false,
            'totp_enabled'  => false,
        ]);
});

it('status restituisce totp_required false se ruolo non in totp_required_roles configurati', function () {
    // Con ruoli specificati, solo quegli utenti vengono forzati
    config(['saas-core.auth.totp_required_roles' => ['superadmin']]);

    $user = makeUserWithActivatedTotp(); // ha ruolo 'user', non 'superadmin'

    $this->actingAs($user)
        ->getJson('/auth/totp/status')
        ->assertStatus(200)
        ->assertJson(['totp_required' => false, 'totp_enabled' => true]);
});

it('status restituisce totp_required true se totp_required_roles è vuota e TOTP attivo', function () {
    // Lista vuota = richiesto per tutti gli utenti che hanno attivato il TOTP
    config(['saas-core.auth.totp_required_roles' => []]);

    $user = makeUserWithActivatedTotp();

    $this->actingAs($user)
        ->getJson('/auth/totp/status')
        ->assertStatus(200)
        ->assertJson(['totp_required' => true, 'totp_enabled' => true]);
});

it('status senza autenticazione restituisce 401', function () {
    // La route ha middleware 'auth' — la status non è pubblica
    // (evita info leak su quali utenti hanno il TOTP configurato)
    $this->getJson('/auth/totp/status')->assertStatus(401);
});
