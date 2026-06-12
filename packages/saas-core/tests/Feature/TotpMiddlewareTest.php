<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use SaaS\Core\Tests\Models\User;

/**
 * Test di feature per TotpMiddleware.
 *
 * Verifica che il middleware 'totp' blocchi correttamente l'accesso
 * alle route protette finché il secondo fattore non è verificato.
 *
 * SETUP ROUTE:
 *   Registriamo una route di test `/test-totp-protected` con middleware
 *   ['auth', 'totp'] in beforeEach, per simulare esattamente come
 *   un'app consumer userebbe il middleware.
 *
 * CASI TESTATI:
 *   - Utente senza TOTP configurato → passa (TOTP opzionale)
 *   - Utente con TOTP e sessione già verificata → passa
 *   - Utente con TOTP e ruolo richiesto, non verificato → 403 JSON
 *   - Richiesta web (non JSON) con TOTP non verificato → redirect
 *   - totp_required_roles vuota → passa sempre (TOTP facoltativo)
 */

beforeEach(function () {
    // Registra una route protetta da 'totp' per i test
    Route::middleware(['web', 'auth', 'totp'])
        ->get('/test-totp-protected', fn () => response()->json(['ok' => true]));

    // Simula la route della pagina challenge che l'app consumer definisce nel web.php
    Route::middleware(['web'])
        ->get('/auth/totp', fn () => response()->json(['totp_page' => true]))
        ->name('totp.challenge.page');
});

// Crea un utente con TOTP attivo e il ruolo 'admin'.
function makeUserWithTotpAndAdminRole(): User
{
    $user = User::create([
        'name'              => 'Mario Rossi',
        'email'             => 'mario@example.com',
        'email_verified_at' => now(),
    ]);

    $user->forceFill([
        'two_factor_secret'       => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $user->assignRole(
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])
    );

    return $user;
}

// ---------------------------------------------------------------------------
// Casi in cui il middleware deve lasciar passare
// ---------------------------------------------------------------------------

it('middleware passa se utente non ha TOTP configurato', function () {
    config(['saas-core.auth.totp_required_roles' => ['admin']]);

    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $this->actingAs($user)
        ->getJson('/test-totp-protected')
        ->assertStatus(200)
        ->assertJson(['ok' => true]);
});

it('middleware blocca se totp_required_roles è vuota e utente ha TOTP attivo', function () {
    // Lista vuota = TOTP richiesto per tutti gli utenti che l'hanno attivato.
    config(['saas-core.auth.totp_required_roles' => []]);

    $user = makeUserWithTotpAndAdminRole();

    $this->actingAs($user)
        ->getJson('/test-totp-protected')
        ->assertStatus(403)
        ->assertJson(['totp_required' => true]);
});

it('middleware passa se totp_verified è true in sessione', function () {
    config(['saas-core.auth.totp_required_roles' => ['admin']]);

    $user = makeUserWithTotpAndAdminRole();

    // Simula che l'utente abbia già verificato il TOTP in questa sessione
    session(['totp_verified' => true]);

    $this->actingAs($user)
        ->getJson('/test-totp-protected')
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Casi in cui il middleware deve bloccare
// ---------------------------------------------------------------------------

it('middleware restituisce 403 JSON se TOTP richiesto e non verificato', function () {
    config(['saas-core.auth.totp_required_roles' => ['admin']]);

    $user = makeUserWithTotpAndAdminRole();

    $this->actingAs($user)
        ->getJson('/test-totp-protected')   // wantsJson() = true per Accept: application/json
        ->assertStatus(403)
        ->assertJson(['totp_required' => true]);
});

it('middleware redirect a totp.challenge.page se richiesta web e TOTP non verificato', function () {
    config(['saas-core.auth.totp_required_roles' => ['admin']]);

    $user = makeUserWithTotpAndAdminRole();

    // Richiesta web normale (non JSON) — il middleware deve fare redirect alla PAGINA
    $this->actingAs($user)
        ->get('/test-totp-protected')   // niente header Accept: application/json
        ->assertRedirect(route('totp.challenge.page'));
});
