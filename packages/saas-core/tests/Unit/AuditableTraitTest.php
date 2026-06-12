<?php

use SaaS\Core\Tests\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Test per il trait Auditable.
 *
 * Usiamo il Model User di test che ha il trait Auditable applicato.
 * Ogni operazione su User deve generare un log in activity_log.
 */

beforeEach(function () {
    // Aggiunge il trait Auditable al Model User di test a runtime
    // senza modificare il file del Model — tecnica utile per i test di package
    \Illuminate\Database\Eloquent\Model::unguard();
});

it('logga la creazione di un record', function () {
    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $log = Activity::where('subject_type', User::class)
        ->where('subject_id', $user->id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->event)->toBe('created');
});

it('logga la modifica di un record con valori before e after', function () {
    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $user->update(['name' => 'Mario Bianchi']);

    $log = Activity::where('subject_type', User::class)
        ->where('subject_id', $user->id)
        ->where('event', 'updated')
        ->first();

    expect($log)->not->toBeNull();

    // Le properties devono contenere old e new per il campo modificato
    $properties = $log->properties->toArray();
    expect($properties['old']['name'])->toBe('Mario Rossi');
    expect($properties['attributes']['name'])->toBe('Mario Bianchi');
});

it('non crea log se nessun campo è cambiato', function () {
    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $countBefore = Activity::count();

    // Salva senza modificare nulla
    $user->save();

    expect(Activity::count())->toBe($countBefore);
});

it('logga la cancellazione di un record', function () {
    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $userId = $user->id;
    $user->delete();

    $log = Activity::where('subject_type', User::class)
        ->where('subject_id', $userId)
        ->where('event', 'deleted')
        ->first();

    expect($log)->not->toBeNull();
});

it('il nome del log è il nome della classe senza namespace', function () {
    $user = User::create([
        'name'  => 'Mario Rossi',
        'email' => 'mario@example.com',
    ]);

    $log = Activity::where('subject_type', User::class)
        ->where('subject_id', $user->id)
        ->first();

    // "SaaS\Core\Tests\Models\User" → "User"
    expect($log->log_name)->toBe('User');
});

it('isFinancialModel restituisce false per model non configurati', function () {
    $user = new User();

    expect($user->isFinancialModel())->toBeFalse();
});
