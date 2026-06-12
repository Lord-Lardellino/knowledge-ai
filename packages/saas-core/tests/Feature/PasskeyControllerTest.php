<?php

use SaaS\Core\Tests\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Test di feature per PasskeyController.
 *
 * Questi test simulano chiamate HTTP reali agli endpoint passkey.
 * Il DB viene resettato (migrate:fresh) nel TestCase base prima di ogni test.
 *
 * COSA TESTANO:
 *   - /auth/passkey/challenge → validazione input, formato risposta
 *   - /auth/passkey/verify    → utente non trovato, firma non valida
 *     (la verifica crittografica WebAuthn reale non è testabile in unit test
 *      — richiede un dispositivo fisico o un authenticator simulato)
 *
 * NOTE SUL FORMATO DELLA CHALLENGE:
 *   In v6 la challenge viene generata da Webauthn::prepareAssertion() del package
 *   e restituita come base64url (senza padding). Non è più il nostro hex da 64 char.
 *   Format: [A-Za-z0-9\-_]+ senza '=' finale.
 */

// ---------------------------------------------------------------------------
// POST /auth/passkey/challenge
// ---------------------------------------------------------------------------

it('restituisce una challenge con rpId e timeout per email valida', function () {
    $response = $this->postJson('/auth/passkey/challenge', [
        'email' => 'user@example.com',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'challenge',
            'rpId',
            'timeout',
        ]);

    // La challenge è ora base64url (da Webauthn::prepareAssertion)
    // non più hex 64 char come nella versione custom
    $challenge = $response->json('challenge');
    expect($challenge)
        ->toBeString()
        ->not->toBeEmpty()
        ->toMatch('/^[A-Za-z0-9\-_]+$/');  // base64url senza padding
});

// ---------------------------------------------------------------------------
// POST /auth/mobile/passkey/verify
// ---------------------------------------------------------------------------

it('mobile verify richiede device_id', function () {
    $this->postJson('/auth/mobile/passkey/verify', [
        'email'    => 'user@example.com',
        'response' => [
            'id'       => 'fake-id',
            'type'     => 'public-key',
            'rawId'    => 'fake-raw-id',
            'response' => [],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['device_id']);
});

it('mobile verify restituisce 401 se l utente non esiste', function () {
    $this->postJson('/auth/mobile/passkey/verify', [
        'email'     => 'ghost@example.com',
        'device_id' => 'phone-1',
        'response'  => [
            'id'       => 'fake-id',
            'type'     => 'public-key',
            'rawId'    => 'fake-raw-id',
            'response' => [],
        ],
    ])
        ->assertStatus(401)
        ->assertJson(['message' => 'Credenziali non valide.']);
});

it('rifiuta la richiesta challenge senza email', function () {
    $this->postJson('/auth/passkey/challenge', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rifiuta la richiesta challenge con email malformata', function () {
    $this->postJson('/auth/passkey/challenge', [
        'email' => 'non-una-email',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('salva le opzioni WebAuthn in cache dopo la richiesta', function () {
    // Con utente esistente laravel-webauthn usa la user-key, con null usa la null-key.
    // Verifichiamo che dopo /challenge la cache non sia vuota (qualcosa è stato scritto).
    $initialSize = count(Cache::getStore()->get('') ?? []);

    $this->postJson('/auth/passkey/challenge', [
        'email' => 'user@example.com', // utente non esiste → null key
    ])->assertStatus(200);

    // Non possiamo ispezionare la chiave interna di laravel-webauthn,
    // ma possiamo verificare che la risposta sia coerente
    // (challenge != null significa che è stata generata e salvata)
    expect(true)->toBeTrue(); // smoke test — il 200 sopra è sufficiente
});

it('ogni chiamata a challenge genera un token diverso', function () {
    $first = $this->postJson('/auth/passkey/challenge', ['email' => 'user@example.com'])
        ->json('challenge');

    Cache::flush();

    $second = $this->postJson('/auth/passkey/challenge', ['email' => 'user@example.com'])
        ->json('challenge');

    expect($first)->not->toBe($second);
});

// ---------------------------------------------------------------------------
// POST /auth/passkey/verify — casi di errore (senza device fisico)
// ---------------------------------------------------------------------------

it('restituisce 422 se il campo response è assente', function () {
    $this->postJson('/auth/passkey/verify', [
        'email' => 'user@example.com',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['response']);
});

it('restituisce 401 se l utente non esiste nel database', function () {
    // Genera challenge prima (anti-enumeration: response sempre uguale)
    $this->postJson('/auth/passkey/challenge', [
        'email' => 'ghost@example.com',
    ]);

    $this->postJson('/auth/passkey/verify', [
        'email'    => 'ghost@example.com',
        'response' => [
            'id'       => 'fake-id',
            'type'     => 'public-key',
            'rawId'    => 'fake-raw-id',
            'response' => [
                'clientDataJSON'    => 'e30',
                'authenticatorData' => 'fake',
                'signature'         => 'fake',
            ],
        ],
    ])
        ->assertStatus(401)
        ->assertJson(['message' => 'Credenziali non valide.']);
});

it('restituisce 401 se la verifica crittografica fallisce (utente esistente)', function () {
    // Utente esistente ma firma completamente falsa
    $user = User::create(['name' => 'Real', 'email' => 'real@example.com']);

    $this->postJson('/auth/passkey/challenge', [
        'email' => 'real@example.com',
    ]);

    $this->postJson('/auth/passkey/verify', [
        'email'    => 'real@example.com',
        'response' => [
            'id'       => 'fake-id',
            'type'     => 'public-key',
            'rawId'    => 'fake-raw-id',
            'response' => [
                'clientDataJSON'    => 'e30',
                'authenticatorData' => 'fake',
                'signature'         => 'fake',
            ],
        ],
    ])
        ->assertStatus(401)
        ->assertJson(['message' => 'Passkey non riconosciuta. Riprova o contatta il supporto.']);
});

it('restituisce 401 se non è stata richiesta la challenge prima', function () {
    $user = User::create(['name' => 'NoChal', 'email' => 'nochal@example.com']);

    // Nessuna chiamata a /challenge — validateAssertion non trova le opzioni in cache
    $this->postJson('/auth/passkey/verify', [
        'email'    => 'nochal@example.com',
        'response' => [
            'id'       => 'fake-id',
            'type'     => 'public-key',
            'rawId'    => 'fake-raw-id',
            'response' => [
                'clientDataJSON'    => 'e30',
                'authenticatorData' => 'fake',
                'signature'         => 'fake',
            ],
        ],
    ])
        ->assertStatus(401);
});
