<?php

use SaaS\Core\Audit\GdprEraser;
use SaaS\Core\Tests\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Test per GdprEraser.
 *
 * Verifica che il diritto all'oblio sia implementato correttamente:
 * i dati personali vengono anonimizzati, il record rimane per integrità referenziale,
 * e l'evento di cancellazione viene loggato.
 */

beforeEach(function () {
    $this->eraser = new GdprEraser();

    $this->user = User::create([
        'name'     => 'Mario Rossi',
        'email'    => 'mario@example.com',
        'password' => bcrypt('secret'),
    ]);
});

it('anonimizza il nome dell utente', function () {
    $this->eraser->erase($this->user);

    $this->user->refresh();
    expect($this->user->name)->toBe('[deleted]');
});

it('anonimizza l email con formato univoco non reale', function () {
    $id = $this->user->id;
    $this->eraser->erase($this->user);

    $this->user->refresh();
    expect($this->user->email)->toBe("deleted_{$id}@deleted.invalid");
});

it('rimuove la password', function () {
    $this->eraser->erase($this->user);

    $this->user->refresh();
    expect($this->user->password)->toBeNull();
});

it('il record utente rimane nel database dopo la cancellazione', function () {
    $id = $this->user->id;
    $this->eraser->erase($this->user);

    // Il record deve esistere anche dopo la cancellazione (soft delete + anonimizzato)
    // withTrashed() include i record soft-deleted
    $found = User::withTrashed()->find($id);
    expect($found)->not->toBeNull();
});

it('due utenti cancellati hanno email anonime diverse', function () {
    $user2 = User::create([
        'name'  => 'Luigi Verdi',
        'email' => 'luigi@example.com',
    ]);

    $this->eraser->erase($this->user);
    $this->eraser->erase($user2);

    $this->user->refresh();
    $user2->refresh();

    expect($this->user->email)->not->toBe($user2->email);
});

it('logga l evento di cancellazione GDPR in activity_log', function () {
    $this->eraser->erase($this->user);

    $log = Activity::where('description', 'user.gdpr_erased')->first();

    expect($log)->not->toBeNull();
    expect($log->properties['user_id'])->toBe($this->user->id);
    expect($log->properties['reason'])->toContain('GDPR');
});
