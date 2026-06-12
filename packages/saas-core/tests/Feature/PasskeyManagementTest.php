<?php

use LaravelWebauthn\Facades\Webauthn;
use LaravelWebauthn\Models\WebauthnKey;
use SaaS\Core\Auth\Passkeys\PasskeyDeviceNames;
use SaaS\Core\Tests\Models\User;

/**
 * Test di feature per PasskeyManagementController.
 *
 * Copre la gestione passkey per utenti già autenticati:
 *   GET    /profile/passkeys          — lista dispositivi
 *   GET    /profile/passkeys/options  — prepara attestazione (step 1 aggiunta)
 *   POST   /profile/passkeys          — salva nuova passkey (step 2 aggiunta)
 *   DELETE /profile/passkeys/{id}     — elimina passkey
 *
 * STRATEGIA MOCK:
 *   Webauthn::swap(Mockery::mock()) crea un pure mock senza classe base,
 *   bypassando il return type strict di PublicKeyCredentialCreationOptions.
 */

// Crea un WebauthnKey di test associato a un utente
function makePasskey(User $user, string $name = 'MacBook'): WebauthnKey
{
    return WebauthnKey::create([
        'user_id'             => $user->id,
        'name'                => $name,
        'credentialId'        => base64_encode(random_bytes(32)),
        'type'                => 'public-key',
        'transports'          => [],
        'attestationType'     => 'none',
        'trustPath'           => ['type' => 'EmptyTrustPath'],
        'aaguid'              => '00000000-0000-0000-0000-000000000000',
        'credentialPublicKey' => base64_encode(random_bytes(64)),
        'counter'             => 0,
    ]);
}

// Pure mock — bypassa il return type strict di prepareAttestation()
function swapManagementPrepareMock(array $extra = []): void
{
    $mock = Mockery::mock();
    $mock->shouldReceive('prepareAttestation')
        ->once()
        ->andReturn(array_merge(['challenge' => 'dGVzdA==', 'rpId' => 'test.tuosaas.com'], $extra));
    Webauthn::swap($mock);
}

// ---------------------------------------------------------------------------
// Autenticazione richiesta su tutti gli endpoint
// ---------------------------------------------------------------------------

it('blocca GET /profile/passkeys senza autenticazione', function () {
    $this->getJson('/profile/passkeys')->assertStatus(401);
});

it('blocca GET /profile/passkeys/options senza autenticazione', function () {
    $this->getJson('/profile/passkeys/options')->assertStatus(401);
});

it('blocca POST /profile/passkeys senza autenticazione', function () {
    $this->postJson('/profile/passkeys', ['response' => []])->assertStatus(401);
});

it('blocca DELETE /profile/passkeys/{id} senza autenticazione', function () {
    $this->deleteJson('/profile/passkeys/1')->assertStatus(401);
});

// ---------------------------------------------------------------------------
// GET /profile/passkeys — lista
// ---------------------------------------------------------------------------

it('restituisce lista vuota se l utente non ha passkey', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $this->actingAs($user)
        ->getJson('/profile/passkeys')
        ->assertStatus(200)
        ->assertJson(['keys' => []]);
});

it('restituisce le passkey dell utente autenticato', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    makePasskey($user, 'MacBook Pro');
    makePasskey($user, 'iPhone 15');

    $response = $this->actingAs($user)
        ->getJson('/profile/passkeys')
        ->assertStatus(200);

    $keys = $response->json('keys');
    expect($keys)->toHaveCount(2);
    expect(collect($keys)->pluck('name')->toArray())->toContain('MacBook Pro', 'iPhone 15');
});

it('non espone i campi sensibili delle passkey (credentialPublicKey, credentialId)', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    makePasskey($user);

    $response = $this->actingAs($user)
        ->getJson('/profile/passkeys')
        ->assertStatus(200);

    $key = $response->json('keys.0');
    expect($key)->not->toHaveKey('credentialPublicKey');
    expect($key)->not->toHaveKey('credentialId');
    expect($key)->toHaveKeys(['id', 'name', 'registered_at']);
});

it('non restituisce le passkey di altri utenti', function () {
    $userA = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    $userB = User::create(['name' => 'Luigi', 'email' => 'luigi@example.com']);

    makePasskey($userA, 'MacBook di Mario');
    makePasskey($userB, 'iPhone di Luigi');

    $response = $this->actingAs($userA)
        ->getJson('/profile/passkeys')
        ->assertStatus(200);

    $keys = $response->json('keys');
    expect($keys)->toHaveCount(1);
    expect($keys[0]['name'])->toBe('MacBook di Mario');
});

// ---------------------------------------------------------------------------
// GET /profile/passkeys/options — step 1 aggiunta passkey
// ---------------------------------------------------------------------------

it('restituisce le opzioni di attestazione per l utente autenticato', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    swapManagementPrepareMock();

    $this->actingAs($user)
        ->getJson('/profile/passkeys/options')
        ->assertStatus(200)
        ->assertJsonStructure(['challenge', 'rpId']);
});

it('salva passkey_add_pending in sessione dopo options', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    swapManagementPrepareMock();

    $this->actingAs($user)->getJson('/profile/passkeys/options');

    expect(session('passkey_add_pending'))->toBeTrue();
});

it('gestisce errore interno durante prepareAttestation nel management', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $mock = Mockery::mock();
    $mock->shouldReceive('prepareAttestation')->once()->andThrow(new \Exception('errore'));
    Webauthn::swap($mock);

    $this->actingAs($user)
        ->getJson('/profile/passkeys/options')
        ->assertStatus(500)
        ->assertJson(['message' => 'Impossibile preparare la registrazione.']);
});

// ---------------------------------------------------------------------------
// POST /profile/passkeys — step 2 aggiunta passkey
// ---------------------------------------------------------------------------

it('rifiuta store senza sessione passkey_add_pending', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $this->actingAs($user)
        ->postJson('/profile/passkeys', ['response' => ['a' => 'b']])
        ->assertStatus(422)
        ->assertJson(['message' => 'Sessione scaduta. Ricomincia.']);
});

it('rifiuta store senza il campo response', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $this->actingAs($user)
        ->withSession(['passkey_add_pending' => true])
        ->postJson('/profile/passkeys', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['response']);
});

it('restituisce 422 se validateAttestation fallisce nell aggiunta', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $mock = Mockery::mock();
    $mock->shouldReceive('validateAttestation')->once()->andThrow(new \Exception('attestation invalid'));
    Webauthn::swap($mock);

    $this->actingAs($user)
        ->withSession(['passkey_add_pending' => true])
        ->postJson('/profile/passkeys', ['response' => ['a' => 'b']])
        ->assertStatus(422)
        ->assertJson(['message' => 'Registrazione passkey fallita. Riprova.']);
});

it('aggiunge la passkey e pulisce la sessione dopo validazione riuscita', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $mock = Mockery::mock();
    $mock->shouldReceive('validateAttestation')->once();
    Webauthn::swap($mock);

    $this->actingAs($user)
        ->withSession(['passkey_add_pending' => true])
        ->postJson('/profile/passkeys', [
            'response' => ['a' => 'b'],
            'key_name' => 'iPad Pro',
        ])
        ->assertStatus(200)
        ->assertJson(['message' => 'Passkey aggiunta con successo.']);

    expect(session('passkey_add_pending'))->toBeNull();
});

// ---------------------------------------------------------------------------
// PATCH /profile/passkeys/{id} — rinomina
// ---------------------------------------------------------------------------

it('rinomina una passkey di proprietà dell utente', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    $key  = makePasskey($user, 'Windows');

    $this->actingAs($user)
        ->patchJson("/profile/passkeys/{$key->id}", ['name' => 'iPhone 15 personale'])
        ->assertStatus(200)
        ->assertJson(['message' => 'Passkey rinominata.']);

    expect(WebauthnKey::find($key->id)->name)->toBe('iPhone 15 personale');
});

it('blocca rinomina senza autenticazione', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    $key  = makePasskey($user, 'Windows');

    $this->patchJson("/profile/passkeys/{$key->id}", ['name' => 'iPhone'])
        ->assertStatus(401);
});

it('restituisce 404 se la passkey da rinominare non esiste', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $this->actingAs($user)
        ->patchJson('/profile/passkeys/99999', ['name' => 'Test'])
        ->assertStatus(404);
});

it('restituisce 404 se la passkey da rinominare appartiene a un altro utente', function () {
    $userA = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    $userB = User::create(['name' => 'Luigi', 'email' => 'luigi@example.com']);
    $keyB  = makePasskey($userB, 'MacBook di Luigi');

    $this->actingAs($userA)
        ->patchJson("/profile/passkeys/{$keyB->id}", ['name' => 'Rubato'])
        ->assertStatus(404);

    expect(WebauthnKey::find($keyB->id)->name)->toBe('MacBook di Luigi');
});

it('valida che il campo name sia presente per la rinomina', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    $key  = makePasskey($user, 'Windows');

    $this->actingAs($user)
        ->patchJson("/profile/passkeys/{$key->id}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

// ---------------------------------------------------------------------------
// DELETE /profile/passkeys/{id} — eliminazione
// ---------------------------------------------------------------------------

it('elimina una passkey di proprietà dell utente', function () {
    $user = User::create([
        'name'              => 'Mario',
        'email'             => 'mario@example.com',
        'email_verified_at' => now(),
    ]);
    $key1 = makePasskey($user, 'MacBook');
    makePasskey($user, 'iPhone');

    $this->actingAs($user)
        ->deleteJson("/profile/passkeys/{$key1->id}")
        ->assertStatus(200)
        ->assertJson(['message' => 'Passkey eliminata.']);

    expect(WebauthnKey::find($key1->id))->toBeNull();
});

it('restituisce 404 se la passkey non esiste', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $this->actingAs($user)
        ->deleteJson('/profile/passkeys/99999')
        ->assertStatus(404);
});

it('restituisce 404 se la passkey appartiene a un altro utente', function () {
    $userA = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    $userB = User::create(['name' => 'Luigi', 'email' => 'luigi@example.com']);
    $keyB  = makePasskey($userB, 'iPhone di Luigi');

    $this->actingAs($userA)
        ->deleteJson("/profile/passkeys/{$keyB->id}")
        ->assertStatus(404);

    expect(WebauthnKey::find($keyB->id))->not->toBeNull();
});

it('blocca l eliminazione dell unica passkey se l email non è verificata', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    $key  = makePasskey($user, 'unico-device');

    $this->actingAs($user)
        ->deleteJson("/profile/passkeys/{$key->id}")
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Non puoi eliminare l\'unica passkey senza un\'email verificata.']);
});

it('permette l eliminazione dell unica passkey se l email è verificata', function () {
    $user = User::create([
        'name'              => 'Mario',
        'email'             => 'mario@example.com',
        'email_verified_at' => now(),
    ]);
    $key = makePasskey($user, 'unico-device');

    $this->actingAs($user)
        ->deleteJson("/profile/passkeys/{$key->id}")
        ->assertStatus(200);

    expect(WebauthnKey::find($key->id))->toBeNull();
});

it('permette di eliminare una passkey quando ce ne sono più di una anche senza email verificata', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    $key1 = makePasskey($user, 'MacBook');
    makePasskey($user, 'iPhone');

    $this->actingAs($user)
        ->deleteJson("/profile/passkeys/{$key1->id}")
        ->assertStatus(200);

    expect(WebauthnKey::find($key1->id))->toBeNull();
    expect(WebauthnKey::where('user_id', $user->id)->count())->toBe(1);
});
