<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use SaaS\Core\Auth\Mobile\DeviceTokenService;
use SaaS\Core\Tests\Models\User;

/**
 * Test di feature per il flusso TOTP mobile (stateless, Sanctum).
 *
 * FLUSSO SOTTO TEST:
 *   1. POST /auth/mobile/passkey/verify con utente TOTP attivo
 *      → { totp_required, pending_token } e NESSUN access token (gate 2FA)
 *   2. POST /auth/mobile/totp/verify con pending token + codice
 *      → coppia access/refresh definitiva, pending revocato
 *
 * STRATEGIE MOCK (stessi pattern degli altri test):
 *   - Webauthn::swap(Mockery::mock()) per validateAssertion (classe final)
 *   - app()->instance() per il TwoFactorAuthenticationProvider
 */

// Utente con TOTP confermato e recovery code noto ('ABCDE-FGHIJ')
function makeMobileTotpUser(): User
{
    $user = User::create([
        'name'              => 'Mario Mobile',
        'email'             => 'mobile@example.com',
        'email_verified_at' => now(),
    ]);

    $user->forceFill([
        'two_factor_secret'         => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at'   => now(),
        'two_factor_recovery_codes' => encrypt(json_encode([
            Hash::make('ABCDE-FGHIJ'),
        ])),
    ])->save();

    return $user;
}

// Utente senza TOTP
function makeMobileUserWithoutTotp(): User
{
    return User::create([
        'name'              => 'Anna NoTotp',
        'email'             => 'no-totp@example.com',
        'email_verified_at' => now(),
    ]);
}

function swapMobileTotpProvider(bool $verifyReturn): void
{
    $mock = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $mock->shouldReceive('verify')->andReturn($verifyReturn);
    app()->instance(TwoFactorAuthenticationProvider::class, $mock);
}

function swapWebauthnAssertionValid(): void
{
    // Webauthn::validateAssertion è un metodo STATICO del service: la facade
    // swap non lo intercetta. Internamente però risolve dal container il
    // CredentialAssertionValidator (invokable) — mockiamo quello.
    $mock = Mockery::mock(\LaravelWebauthn\Services\Webauthn\CredentialAssertionValidator::class);
    $mock->shouldReceive('__invoke')->andReturn(true);
    app()->instance(\LaravelWebauthn\Services\Webauthn\CredentialAssertionValidator::class, $mock);
}

function passkeyVerifyPayload(string $email): array
{
    return [
        'email'     => $email,
        'device_id' => 'seeker-test',
        'response'  => [
            'id'       => 'fake-id',
            'type'     => 'public-key',
            'rawId'    => 'fake-raw-id',
            'response' => [],
        ],
    ];
}

// ---------------------------------------------------------------------------
// GATE 2FA su POST /auth/mobile/passkey/verify
// ---------------------------------------------------------------------------

it('login mobile con TOTP attivo restituisce pending token e NESSUN access token', function () {
    swapWebauthnAssertionValid();
    $user = makeMobileTotpUser();

    $response = $this->postJson('/auth/mobile/passkey/verify', passkeyVerifyPayload($user->email))
        ->assertStatus(200)
        ->assertJson(['totp_required' => true])
        ->assertJsonStructure(['pending_token', 'token_type', 'expires_in'])
        ->assertJsonMissing(['access_token'])
        ->assertJsonMissing(['refresh_token']);

    // Il pending token esiste nel DB con la sola ability totp-pending
    $token = $user->tokens()->where('name', 'totp-pending:seeker-test')->first();
    expect($token)->not->toBeNull()
        ->and($token->abilities)->toBe(['totp-pending']);
});

it('login mobile senza TOTP restituisce subito la coppia di token (regressione)', function () {
    swapWebauthnAssertionValid();
    $user = makeMobileUserWithoutTotp();

    $this->postJson('/auth/mobile/passkey/verify', passkeyVerifyPayload($user->email))
        ->assertStatus(200)
        ->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in'])
        ->assertJsonMissing(['totp_required']);
});

// ---------------------------------------------------------------------------
// POST /auth/mobile/totp/verify — challenge stateless
// ---------------------------------------------------------------------------

it('verify mobile con codice valido emette la coppia di token e revoca il pending', function () {
    swapMobileTotpProvider(verifyReturn: true);
    $user = makeMobileTotpUser();

    $pending = app(DeviceTokenService::class)->createTotpPendingToken($user, 'seeker-test');

    $this->withHeader('Authorization', 'Bearer ' . $pending['pending_token'])
        ->postJson('/auth/mobile/totp/verify', ['code' => '123456', 'device_id' => 'seeker-test'])
        ->assertStatus(200)
        ->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in']);

    // Il pending token è stato revocato insieme agli altri token del device
    expect($user->tokens()->where('name', 'totp-pending:seeker-test')->exists())->toBeFalse();
});

it('verify mobile con codice errato restituisce 422 e il pending resta valido', function () {
    swapMobileTotpProvider(verifyReturn: false);
    $user = makeMobileTotpUser();

    $pending = app(DeviceTokenService::class)->createTotpPendingToken($user, 'seeker-test');

    $this->withHeader('Authorization', 'Bearer ' . $pending['pending_token'])
        ->postJson('/auth/mobile/totp/verify', ['code' => '000000', 'device_id' => 'seeker-test'])
        ->assertStatus(422);

    // Il pending sopravvive: l'utente può riprovare (entro 10 minuti)
    expect($user->tokens()->where('name', 'totp-pending:seeker-test')->exists())->toBeTrue();
});

it('verify mobile con recovery code valido emette i token e lo consuma', function () {
    swapMobileTotpProvider(verifyReturn: false);
    $user = makeMobileTotpUser();

    $pending = app(DeviceTokenService::class)->createTotpPendingToken($user, 'seeker-test');

    $this->withHeader('Authorization', 'Bearer ' . $pending['pending_token'])
        ->postJson('/auth/mobile/totp/verify', ['code' => 'ABCDE-FGHIJ', 'device_id' => 'seeker-test'])
        ->assertStatus(200)
        ->assertJsonStructure(['access_token', 'refresh_token', 'message']);

    // Recovery code monouso: la lista ora è vuota
    $codes = json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true);
    expect($codes)->toBeEmpty();
});

it('verify mobile rifiuta un ACCESS token (serve il pending)', function () {
    swapMobileTotpProvider(verifyReturn: true);
    $user = makeMobileTotpUser();

    $tokens = app(DeviceTokenService::class)->createTokenPair($user, 'seeker-test');

    // Access token con ability 'access' ≠ 'totp-pending' → 403
    $this->withHeader('Authorization', 'Bearer ' . $tokens['access_token'])
        ->postJson('/auth/mobile/totp/verify', ['code' => '123456', 'device_id' => 'seeker-test'])
        ->assertStatus(403);
});

it('il pending token non può usare il refresh endpoint', function () {
    $user = makeMobileTotpUser();

    $pending = app(DeviceTokenService::class)->createTotpPendingToken($user, 'seeker-test');

    // ability 'totp-pending' ≠ 'refresh' → 403
    $this->withHeader('Authorization', 'Bearer ' . $pending['pending_token'])
        ->postJson('/auth/mobile/refresh')
        ->assertStatus(403);
});

it('verify mobile senza autenticazione restituisce 401', function () {
    $this->postJson('/auth/mobile/totp/verify', ['code' => '123456', 'device_id' => 'x'])
        ->assertStatus(401);
});

// ---------------------------------------------------------------------------
// GET /auth/mobile/totp/status + setup mobile (riuso TotpSetupController)
// ---------------------------------------------------------------------------

it('status mobile riporta totp_enabled per utente con TOTP attivo', function () {
    $user = makeMobileTotpUser();

    $tokens = app(DeviceTokenService::class)->createTokenPair($user, 'seeker-test');

    $this->withHeader('Authorization', 'Bearer ' . $tokens['access_token'])
        ->getJson('/auth/mobile/totp/status')
        ->assertStatus(200)
        ->assertJson(['totp_enabled' => true, 'totp_required' => true]);
});

it('setup mobile genera segreto e QR url con un access token', function () {
    $user = makeMobileUserWithoutTotp();

    $tokens = app(DeviceTokenService::class)->createTokenPair($user, 'seeker-test');

    $this->withHeader('Authorization', 'Bearer ' . $tokens['access_token'])
        ->getJson('/auth/mobile/totp/setup')
        ->assertStatus(200)
        ->assertJsonStructure(['secret', 'qr_url']);
});

it('setup mobile senza token restituisce 401', function () {
    $this->getJson('/auth/mobile/totp/setup')
        ->assertStatus(401);
});
