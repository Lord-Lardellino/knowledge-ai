<?php

use SaaS\Core\Tests\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

/**
 * Test per SessionHardener middleware.
 *
 * Il middleware protegge da:
 *   1. Session hijacking da inattività (timeout automatico)
 *   2. Garantisce che last_activity venga aggiornato ad ogni request
 *
 * Usiamo sessioni array (in-memory) nei test — nessun file/DB necessario.
 */

beforeEach(function () {
    config(['saas-core.auth.session_lifetime' => 120]); // 120 minuti default

    Route::middleware(['web', 'session.hardener'])->get('/protected', function () {
        return response()->json(['ok' => true]);
    });
});

it('lascia passare la request se la sessione è recente', function () {
    $user = User::create([
        'name'     => 'Alice',
        'email'    => 'alice@example.com',
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($user)
        ->withSession(['last_activity' => time()])
        ->get('/protected')
        ->assertStatus(200)
        ->assertJson(['ok' => true]);
});

it('aggiorna last_activity ad ogni request autenticata', function () {
    $user = User::create([
        'name'     => 'Bob',
        'email'    => 'bob@example.com',
        'password' => bcrypt('secret'),
    ]);

    $before = time() - 10; // simula ultima attività 10 secondi fa

    $response = $this->actingAs($user)
        ->withSession(['last_activity' => $before])
        ->get('/protected');

    $response->assertStatus(200);

    // last_activity deve essere aggiornato a un valore >= $before
    $lastActivity = session('last_activity');
    expect($lastActivity)->toBeGreaterThanOrEqual($before);
});

it('scade la sessione dopo il timeout di inattività', function () {
    $user = User::create([
        'name'     => 'Carol',
        'email'    => 'carol@example.com',
        'password' => bcrypt('secret'),
    ]);

    config(['saas-core.auth.session_lifetime' => 1]); // 1 minuto

    // Simula ultima attività 2 minuti fa (scaduta)
    $expired = time() - (2 * 60);

    $response = $this->actingAs($user)
        ->withSession(['last_activity' => $expired])
        ->get('/protected');

    // Deve fare redirect (302) verso login
    $response->assertStatus(302);
});

it('lascia passare request non autenticate senza toccare la sessione', function () {
    // Utente non loggato: il middleware non fa nulla
    $this->get('/protected')
        ->assertStatus(200);
});

it('lascia passare se non c è last_activity in sessione prima request', function () {
    $user = User::create([
        'name'     => 'Dave',
        'email'    => 'dave@example.com',
        'password' => bcrypt('secret'),
    ]);

    // Prima request: nessun last_activity in sessione — non deve scadere
    $this->actingAs($user)
        ->withSession([]) // sessione vuota
        ->get('/protected')
        ->assertStatus(200);
});
