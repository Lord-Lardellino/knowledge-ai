<?php

use LaravelWebauthn\Facades\Webauthn;
use SaaS\Core\Tenancy\Models\Tenant;
use SaaS\Core\Tenancy\Models\TenantInvite;
use SaaS\Core\Tenancy\TenantOnboarding;
use SaaS\Core\Tests\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Test del flusso di onboarding tenant:
 *   - self-signup: registrazione con "company" → tenant creato, utente owner
 *   - invito: registrazione con "invite_token" → utente nel tenant con ruolo
 *   - slug univoci e riservati
 *
 * I mock Webauthn seguono la stessa strategia di PasskeyRegistrationTest
 * (swap con pure mock — vedi commento lì).
 */

beforeEach(function () {
    foreach (['owner', 'admin', 'user'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

function swapWebauthnOnboardingMocks(): void
{
    $mock = Mockery::mock();
    $mock->shouldReceive('prepareAttestation')->andReturn([
        'challenge'        => 'dGVzdC1jaGFsbGVuZ2U=',
        'rpId'             => 'test.tuosaas.com',
        'user'             => ['id' => 'dXNlcjE=', 'name' => 'mario@example.com', 'displayName' => 'Mario'],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
        'timeout'          => 60000,
    ]);
    $mock->shouldReceive('validateAttestation')->andReturn(true);
    Webauthn::swap($mock);
}

// ---------------------------------------------------------------------------
// SELF-SIGNUP — company crea il tenant
// ---------------------------------------------------------------------------

it('crea il tenant e rende owner chi si registra con company', function () {
    swapWebauthnOnboardingMocks();

    $this->postJson('/auth/passkey/register/options', [
        'name'    => 'Mario Rossi',
        'email'   => 'mario@example.com',
        'company' => 'Rossi SRL',
    ])->assertOk();

    // Il tenant NON esiste ancora: viene creato solo a passkey verificata
    expect(Tenant::where('slug', 'rossi-srl')->exists())->toBeFalse();

    $this->postJson('/auth/passkey/register', [
        'response' => ['id' => 'fake'],
    ])->assertOk();

    $tenant = Tenant::where('slug', 'rossi-srl')->first();
    $user   = User::where('email', 'mario@example.com')->first();

    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('Rossi SRL')
        ->and($user->tenant_id)->toBe($tenant->id)
        ->and($user->hasRole('owner'))->toBeTrue();
});

it('non crea il tenant se la registrazione resta a metà (niente step 2)', function () {
    swapWebauthnOnboardingMocks();

    $this->postJson('/auth/passkey/register/options', [
        'name'    => 'Mario Rossi',
        'email'   => 'mario@example.com',
        'company' => 'Rossi SRL',
    ])->assertOk();

    expect(Tenant::count())->toBe(0);
});

it('registra senza tenant quando company e invite_token mancano', function () {
    swapWebauthnOnboardingMocks();

    $this->postJson('/auth/passkey/register/options', [
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ])->assertOk();

    $this->postJson('/auth/passkey/register', [
        'response' => ['id' => 'fake'],
    ])->assertOk();

    expect(User::where('email', 'mario@example.com')->first()->tenant_id)->toBeNull()
        ->and(Tenant::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// SLUG
// ---------------------------------------------------------------------------

it('genera slug univoci con suffisso numerico per nomi uguali', function () {
    $onboarding = app(TenantOnboarding::class);

    $first  = $onboarding->createTenant('Rossi SRL');
    $second = $onboarding->createTenant('Rossi SRL');

    expect($first->slug)->toBe('rossi-srl')
        ->and($second->slug)->toBe('rossi-srl-2');
});

it('rifiuta gli slug riservati ripiegando su un nome neutro', function () {
    $onboarding = app(TenantOnboarding::class);

    expect($onboarding->uniqueSlug('www'))->toBe('azienda')
        ->and($onboarding->uniqueSlug('!!!'))->toBe('azienda');
});

// ---------------------------------------------------------------------------
// INVITI
// ---------------------------------------------------------------------------

it('aggancia l invitato al tenant con il ruolo dell invito', function () {
    swapWebauthnOnboardingMocks();
    $onboarding = app(TenantOnboarding::class);
    $tenant     = $onboarding->createTenant('Acme');
    ['token' => $token] = $onboarding->invite($tenant, 'collega@example.com', 'admin');

    $this->postJson('/auth/passkey/register/options', [
        'name'         => 'Collega',
        'email'        => 'collega@example.com',
        'invite_token' => $token,
    ])->assertOk();

    $this->postJson('/auth/passkey/register', [
        'response' => ['id' => 'fake'],
    ])->assertOk();

    $user = User::where('email', 'collega@example.com')->first();

    expect($user->tenant_id)->toBe($tenant->id)
        ->and($user->hasRole('admin'))->toBeTrue()
        ->and(TenantInvite::first()->accepted_at)->not->toBeNull();
});

it('rifiuta un invite_token inesistente', function () {
    $this->postJson('/auth/passkey/register/options', [
        'name'         => 'Intruso',
        'email'        => 'intruso@example.com',
        'invite_token' => 'token-inventato',
    ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Invito non valido o scaduto.']);
});

it('rifiuta l invito usato con una email diversa da quella invitata', function () {
    $onboarding = app(TenantOnboarding::class);
    $tenant     = $onboarding->createTenant('Acme');
    ['token' => $token] = $onboarding->invite($tenant, 'collega@example.com', 'user');

    $this->postJson('/auth/passkey/register/options', [
        'name'         => 'Altro',
        'email'        => 'altro@example.com',
        'invite_token' => $token,
    ])
        ->assertStatus(422)
        ->assertJson(['message' => 'L\'invito è destinato a un altro indirizzo email.']);
});

it('rifiuta un invito scaduto', function () {
    $onboarding = app(TenantOnboarding::class);
    $tenant     = $onboarding->createTenant('Acme');
    ['token' => $token, 'invite' => $invite] = $onboarding->invite($tenant, 'collega@example.com', 'user');
    $invite->forceFill(['expires_at' => now()->subDay()])->save();

    $this->postJson('/auth/passkey/register/options', [
        'name'         => 'Collega',
        'email'        => 'collega@example.com',
        'invite_token' => $token,
    ])->assertStatus(422);
});

it('salva nel DB solo l hash del token, mai il token in chiaro', function () {
    $onboarding = app(TenantOnboarding::class);
    $tenant     = $onboarding->createTenant('Acme');
    ['token' => $token, 'invite' => $invite] = $onboarding->invite($tenant, 'collega@example.com', 'user');

    expect($invite->token)->not->toBe($token)
        ->and($invite->token)->toBe(hash('sha256', $token));
});

it('reinvitare la stessa email rigenera il token invalidando il precedente', function () {
    $onboarding = app(TenantOnboarding::class);
    $tenant     = $onboarding->createTenant('Acme');
    ['token' => $vecchio] = $onboarding->invite($tenant, 'collega@example.com', 'user');
    ['token' => $nuovo]   = $onboarding->invite($tenant, 'collega@example.com', 'admin');

    expect($onboarding->findPendingInvite($vecchio))->toBeNull()
        ->and($onboarding->findPendingInvite($nuovo))->not->toBeNull()
        ->and(TenantInvite::count())->toBe(1);
});
