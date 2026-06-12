<?php

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use SaaS\Core\Auth\Recovery\RecoveryLinkNotification;
use SaaS\Core\Tests\Models\User;

/**
 * Test di feature per RecoveryController.
 *
 * Copre il flusso di recupero accesso via email magic link:
 *   POST /recover          — invia il link
 *   GET  /recover/verify   — verifica il link e autentica
 *
 * SICUREZZA TESTATA:
 *   - Risposta identica per email nota/sconosciuta (no enumerazione)
 *   - Link scaduto viene rifiutato
 *   - Firma manomessa viene rifiutata
 *   - Session regeneration dopo l'autenticazione
 *   - Email marcata come verificata dopo recovery
 *   - Rate limiting
 */

// ---------------------------------------------------------------------------
// POST /recover — invio magic link
// ---------------------------------------------------------------------------

it('rifiuta la richiesta senza email', function () {
    $this->postJson('/recover', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rifiuta la richiesta con email malformata', function () {
    $this->postJson('/recover', ['email' => 'non-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('risponde con lo stesso messaggio per email registrata e non registrata', function () {
    Notification::fake();

    User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $responseKnown = $this->postJson('/recover', ['email' => 'mario@example.com'])
        ->assertStatus(200)
        ->json('message');

    $responseUnknown = $this->postJson('/recover', ['email' => 'ghost@example.com'])
        ->assertStatus(200)
        ->json('message');

    // Stessa risposta: non rivela se l'email è registrata
    expect($responseKnown)->toBe($responseUnknown);
});

it('invia la notifica email solo per utenti esistenti', function () {
    Notification::fake();

    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $this->postJson('/recover', ['email' => 'mario@example.com']);

    Notification::assertSentTo($user, RecoveryLinkNotification::class);
});

it('non invia notifiche per email non registrate', function () {
    Notification::fake();

    $this->postJson('/recover', ['email' => 'ghost@example.com']);

    Notification::assertNothingSent();
});

it('il link di recovery contiene email e signature e scade', function () {
    Notification::fake();

    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $this->postJson('/recover', ['email' => 'mario@example.com']);

    Notification::assertSentTo($user, RecoveryLinkNotification::class,
        function (RecoveryLinkNotification $notification) {
            // Accede al link tramite riflessione per verificarne la struttura
            $reflection = new \ReflectionProperty($notification, 'link');
            $reflection->setAccessible(true);
            $link = $reflection->getValue($notification);

            expect($link)->toContain('recover/verify');
            expect($link)->toContain('email=');
            expect($link)->toContain('signature=');
            expect($link)->toContain('expires=');
            return true;
        }
    );
});

it('applica rate limiting dopo 3 tentativi in 10 minuti', function () {
    Notification::fake();

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/recover', ['email' => 'mario@example.com'])
            ->assertStatus(200);
    }

    // Il quarto tentativo supera il limite
    $this->postJson('/recover', ['email' => 'mario@example.com'])
        ->assertStatus(429);
});

// ---------------------------------------------------------------------------
// GET /recover/verify — verifica link e autenticazione
// ---------------------------------------------------------------------------

it('autentica l utente con un link valido', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $link = URL::temporarySignedRoute(
        'recover.verify',
        now()->addMinutes(15),
        ['email' => $user->email]
    );

    $this->get($link)
        ->assertRedirect('/profile/passkeys?recovery=1');

    $this->assertAuthenticatedAs($user);
});

it('rigenera la sessione dopo l autenticazione via recovery (session fixation)', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $link = URL::temporarySignedRoute(
        'recover.verify',
        now()->addMinutes(15),
        ['email' => $user->email]
    );

    // Cattura il session ID prima del click
    $sessionBefore = session()->getId();

    $this->get($link);

    // Il session ID deve essere cambiato per prevenire session fixation
    $sessionAfter = session()->getId();
    expect($sessionAfter)->not->toBe($sessionBefore);
});

it('marca l email come verificata dopo il recovery', function () {
    $user = User::create([
        'name'              => 'Mario',
        'email'             => 'mario@example.com',
        'email_verified_at' => null,
    ]);

    expect($user->hasVerifiedEmail())->toBeFalse();

    $link = URL::temporarySignedRoute(
        'recover.verify',
        now()->addMinutes(15),
        ['email' => $user->email]
    );

    $this->get($link);

    // Ricarica dal DB
    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue();
});

it('non sovrascrive email_verified_at se già verificata', function () {
    $originalTimestamp = now()->subDays(30)->timestamp;
    $user = User::create([
        'name'              => 'Mario',
        'email'             => 'mario@example.com',
        'email_verified_at' => now()->subDays(30),
    ]);

    $link = URL::temporarySignedRoute(
        'recover.verify',
        now()->addMinutes(15),
        ['email' => $user->email]
    );

    $this->get($link);

    $user->refresh();

    // Email_verified_at deve essere presente e il timestamp non deve essere recente
    // (non è stato sovrascritto con now())
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->email_verified_at->timestamp)->toBe($originalTimestamp);
});

it('rifiuta il link scaduto', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    // Link già scaduto (expires nel passato)
    $link = URL::temporarySignedRoute(
        'recover.verify',
        now()->subMinutes(1),  // scaduto 1 minuto fa
        ['email' => $user->email]
    );

    $this->get($link)->assertStatus(403);

    $this->assertGuest();
});

it('rifiuta un link con firma manomessa', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $link = URL::temporarySignedRoute(
        'recover.verify',
        now()->addMinutes(15),
        ['email' => $user->email]
    );

    // Sostituisce l'email nella query string senza aggiornare la firma
    $tamperedLink = str_replace(
        urlencode($user->email),
        urlencode('victim@example.com'),
        $link
    );

    $this->get($tamperedLink)->assertStatus(403);

    $this->assertGuest();
});

it('restituisce redirect al login se l utente non esiste più al momento del verify', function () {
    $user = User::create(['name' => 'Mario', 'email' => 'mario@example.com']);

    $link = URL::temporarySignedRoute(
        'recover.verify',
        now()->addMinutes(15),
        ['email' => $user->email]
    );

    // Elimina l'utente prima che clicchi il link
    $user->forceDelete();

    $this->get($link)->assertRedirect('/login');

    $this->assertGuest();
});

// ---------------------------------------------------------------------------
// Flusso end-to-end: recovery → aggiungi passkey → elimina vecchia passkey
// ---------------------------------------------------------------------------

it('flusso completo: recovery marca email verificata permettendo gestione passkey', function () {
    // Utente con passkey esistente ma email non verificata
    $user = User::create([
        'name'              => 'Mario',
        'email'             => 'mario@example.com',
        'email_verified_at' => null,
    ]);

    // Clicca il link di recovery → autenticato + email verificata
    $link = URL::temporarySignedRoute(
        'recover.verify',
        now()->addMinutes(15),
        ['email' => $user->email]
    );

    $this->get($link)
        ->assertRedirect('/profile/passkeys?recovery=1');

    $user->refresh();

    // Email ora verificata: può eliminare l'unica passkey e aggiungerne una nuova
    expect($user->hasVerifiedEmail())->toBeTrue();
    $this->assertAuthenticatedAs($user);
});
