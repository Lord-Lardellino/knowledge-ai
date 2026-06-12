<?php

use LaravelWebauthn\Facades\Webauthn;
use LaravelWebauthn\Models\WebauthnKey;
use SaaS\Core\Tests\Models\User;

/**
 * Test di feature per PasskeyRegistrationController.
 *
 * Copre l'endpoint pubblico di registrazione in 2 step:
 *   POST /auth/passkey/register/options  (step 1 — challenge)
 *   POST /auth/passkey/register           (step 2 — verifica)
 *
 * STRATEGIA MOCK:
 *   Webauthn::swap(Mockery::mock()) crea un pure mock senza classe base,
 *   bypassando il return type strict di PublicKeyCredentialCreationOptions
 *   che è una classe final non mockabile direttamente.
 */

// Configura un pure mock per Webauthn::prepareAttestation()
// Restituisce un array JSON-serializzabile — il controller lo passa a response()->json()
function swapWebauthnPrepareMock(array $options = []): object
{
    $mock = Mockery::mock();
    $mock->shouldReceive('prepareAttestation')
        ->andReturn(array_merge([
            'challenge'        => 'dGVzdC1jaGFsbGVuZ2U=',
            'rpId'             => 'test.tuosaas.com',
            'user'             => ['id' => 'dXNlcjE=', 'name' => 'mario@example.com', 'displayName' => 'Mario'],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
            'timeout'          => 60000,
        ], $options));
    Webauthn::swap($mock);
    return $mock;
}

function swapWebauthnValidateMock(?\Throwable $throw = null): object
{
    $mock = Mockery::mock();
    $expect = $mock->shouldReceive('validateAttestation')->once();
    if ($throw) {
        $expect->andThrow($throw);
    }
    Webauthn::swap($mock);
    return $mock;
}

// ---------------------------------------------------------------------------
// POST /auth/passkey/register/options — validazione input
// ---------------------------------------------------------------------------

it('rifiuta la richiesta senza name', function () {
    $this->postJson('/auth/passkey/register/options', [
        'email' => 'mario@example.com',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('rifiuta la richiesta senza email', function () {
    $this->postJson('/auth/passkey/register/options', [
        'name' => 'Mario Rossi',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rifiuta la richiesta con email malformata', function () {
    $this->postJson('/auth/passkey/register/options', [
        'name'  => 'Mario Rossi',
        'email' => 'non-una-email',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

// ---------------------------------------------------------------------------
// POST /auth/passkey/register/options — logica di business
// ---------------------------------------------------------------------------

it('blocca la registrazione se l email ha già passkey attive', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    WebauthnKey::create([
        'user_id'             => $user->id,
        'name'                => 'MacBook',
        'credentialId'        => base64_encode(random_bytes(32)),
        'type'                => 'public-key',
        'transports'          => [],
        'attestationType'     => 'none',
        'trustPath'           => ['type' => 'EmptyTrustPath'],
        'aaguid'              => '00000000-0000-0000-0000-000000000000',
        'credentialPublicKey' => base64_encode(random_bytes(64)),
        'counter'             => 0,
    ]);

    $this->postJson('/auth/passkey/register/options', [
        'name'  => 'Attaccante',
        'email' => 'mario@example.com',
    ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Email già registrata. Accedi dalla pagina di login.']);
});

it('permette la re-registrazione se l email esiste senza passkey (tentativo abbandonato)', function () {
    User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    swapWebauthnPrepareMock();

    $this->postJson('/auth/passkey/register/options', [
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ])
        ->assertStatus(200);
});

it('crea un nuovo utente per una email mai vista', function () {
    swapWebauthnPrepareMock();

    $this->postJson('/auth/passkey/register/options', [
        'name'  => 'Mario Rossi',
        'email' => 'nuovo@example.com',
    ])
        ->assertStatus(200);

    expect(User::where('email', 'nuovo@example.com')->exists())->toBeTrue();
});

it('salva l id utente in sessione dopo options', function () {
    swapWebauthnPrepareMock();

    $this->postJson('/auth/passkey/register/options', [
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $userId = session('passkey_register_user_id');
    expect($userId)->not->toBeNull();

    $user = User::find($userId);
    expect($user->email)->toBe('mario@example.com');
});

it('restituisce le opzioni di attestazione WebAuthn nel formato corretto', function () {
    swapWebauthnPrepareMock();

    $this->postJson('/auth/passkey/register/options', [
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ])
        ->assertStatus(200)
        ->assertJsonStructure(['challenge', 'rpId', 'user', 'pubKeyCredParams']);
});

it('gestisce un errore interno di WebAuthn durante prepareAttestation', function () {
    $mock = Mockery::mock();
    $mock->shouldReceive('prepareAttestation')
        ->once()
        ->andThrow(new \Exception('WebAuthn service error'));
    Webauthn::swap($mock);

    $this->postJson('/auth/passkey/register/options', [
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ])
        ->assertStatus(500)
        ->assertJson(['message' => 'Impossibile preparare la registrazione.']);
});

// ---------------------------------------------------------------------------
// POST /auth/passkey/register — step 2
// ---------------------------------------------------------------------------

it('rifiuta register senza sessione attiva di registrazione', function () {
    $this->postJson('/auth/passkey/register', [
        'response' => ['clientDataJSON' => 'abc', 'attestationObject' => 'def'],
    ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Sessione di registrazione scaduta. Ricomincia.']);
});

it('rifiuta register senza il campo response', function () {
    $this->withSession(['passkey_register_user_id' => 999])
        ->postJson('/auth/passkey/register', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['response']);
});

it('rifiuta register se l utente in sessione non esiste più nel DB', function () {
    $this->withSession(['passkey_register_user_id' => 99999])
        ->postJson('/auth/passkey/register', [
            'response' => ['clientDataJSON' => 'abc'],
        ])
        ->assertStatus(404)
        ->assertJson(['message' => 'Utente non trovato.']);
});

it('elimina l utente e restituisce 422 se validateAttestation fallisce', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    swapWebauthnValidateMock(new \Exception('Invalid attestation'));

    $this->withSession(['passkey_register_user_id' => $user->id])
        ->postJson('/auth/passkey/register', [
            'response' => ['clientDataJSON' => 'abc'],
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Registrazione passkey fallita. Riprova.']);

    expect(User::withTrashed()->find($user->id))->toBeNull();
});

it('autentica l utente e pulisce la sessione dopo registrazione riuscita', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);
    swapWebauthnValidateMock();

    $response = $this->withSession(['passkey_register_user_id' => $user->id])
        ->postJson('/auth/passkey/register', [
            'response' => ['clientDataJSON' => 'abc'],
        ]);

    $response->assertStatus(200)->assertJsonStructure(['redirect']);
    expect(session('passkey_register_user_id'))->toBeNull();
    $this->assertAuthenticated();
});

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

it('applica rate limiting dopo troppi tentativi', function () {
    $mock = Mockery::mock();
    $mock->shouldReceive('prepareAttestation')->andReturn(['challenge' => 'dGVzdA==']);
    Webauthn::swap($mock);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/auth/passkey/register/options', [
            'name'  => 'Mario',
            'email' => "mail{$i}@example.com",
        ]);
    }

    $this->postJson('/auth/passkey/register/options', [
        'name'  => 'Mario',
        'email' => 'ultimo@example.com',
    ])->assertStatus(429);
});
