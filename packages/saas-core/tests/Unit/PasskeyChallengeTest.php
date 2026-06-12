<?php

use SaaS\Core\Auth\Passkeys\PasskeyChallenge;
use Illuminate\Support\Facades\Cache;

/**
 * Test unitari per PasskeyChallenge.
 *
 * Questi test verificano solo la logica della classe PasskeyChallenge,
 * senza fare chiamate HTTP. Veloci, isolati, precisi.
 *
 * Usiamo la Cache array (in memoria) configurata nel TestCase base —
 * nessun Redis necessario, ogni test parte con cache vuota.
 */

beforeEach(function () {
    // Istanza fresca per ogni test — nessuno stato condiviso
    $this->challenge = new PasskeyChallenge();
});

// ---------------------------------------------------------------------------
// generate()
// ---------------------------------------------------------------------------

it('genera una challenge in formato esadecimale di 64 caratteri', function () {
    $challenge = $this->challenge->generate('user@example.com');

    // 32 byte → bin2hex → 64 caratteri esadecimali
    expect($challenge)
        ->toBeString()
        ->toHaveLength(64)
        ->toMatch('/^[0-9a-f]+$/');  // solo caratteri hex, minuscoli
});

it('genera challenge diverse ad ogni chiamata', function () {
    $first  = $this->challenge->generate('user@example.com');

    // Svuota la cache tra le due generate sullo stesso identifier
    Cache::flush();

    $second = $this->challenge->generate('user@example.com');

    // Due challenge identiche sarebbero un bug crittografico grave
    expect($first)->not->toBe($second);
});

it('salva la challenge in cache con il prefisso corretto', function () {
    $this->challenge->generate('user@example.com');

    // Verifica che la chiave esista nella cache
    expect(Cache::has('passkey_challenge:user@example.com'))->toBeTrue();
});

it('challenge per utenti diversi sono indipendenti', function () {
    $this->challenge->generate('alice@example.com');
    $this->challenge->generate('bob@example.com');

    expect(Cache::has('passkey_challenge:alice@example.com'))->toBeTrue();
    expect(Cache::has('passkey_challenge:bob@example.com'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// verify()
// ---------------------------------------------------------------------------

it('verifica correttamente una challenge valida', function () {
    $challenge = $this->challenge->generate('user@example.com');

    expect($this->challenge->verify('user@example.com', $challenge))->toBeTrue();
});

it('rifiuta una challenge errata', function () {
    $this->challenge->generate('user@example.com');

    expect($this->challenge->verify('user@example.com', 'challenge_sbagliata'))->toBeFalse();
});

it('rifiuta una challenge per un identifier diverso', function () {
    $challenge = $this->challenge->generate('alice@example.com');

    // Bob prova a usare la challenge di Alice
    expect($this->challenge->verify('bob@example.com', $challenge))->toBeFalse();
});

it('elimina la challenge dalla cache dopo la verifica corretta', function () {
    $challenge = $this->challenge->generate('user@example.com');
    $this->challenge->verify('user@example.com', $challenge);

    // La challenge non deve più esistere — non riusabile
    expect(Cache::has('passkey_challenge:user@example.com'))->toBeFalse();
});

it('elimina la challenge dalla cache anche dopo verifica fallita', function () {
    $this->challenge->generate('user@example.com');
    $this->challenge->verify('user@example.com', 'sbagliata');

    // Un tentativo fallito non deve lasciare la challenge disponibile
    expect(Cache::has('passkey_challenge:user@example.com'))->toBeFalse();
});

it('restituisce false se la challenge è già scaduta o non esiste', function () {
    // Nessuna generate() chiamata — la chiave non esiste in cache
    expect($this->challenge->verify('user@example.com', 'qualsiasi'))->toBeFalse();
});

it('la stessa challenge non può essere usata due volte', function () {
    $challenge = $this->challenge->generate('user@example.com');

    $primaVerifica  = $this->challenge->verify('user@example.com', $challenge);
    $secondaVerifica = $this->challenge->verify('user@example.com', $challenge);

    expect($primaVerifica)->toBeTrue();
    expect($secondaVerifica)->toBeFalse();  // replay attack bloccato
});
