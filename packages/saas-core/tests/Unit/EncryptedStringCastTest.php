<?php

use Illuminate\Database\Eloquent\Model;
use SaaS\Core\Security\Casts\EncryptedString;
use SaaS\Core\Security\Traits\HasEncryptedAttributes;

/**
 * Test unitari per EncryptedString cast e HasEncryptedAttributes trait.
 *
 * Verifica il comportamento del cast AES-256-CBC con field binding:
 *   - Cifratura in set() + decifratura in get()
 *   - Null/stringa vuota restituiscono null
 *   - Field binding: ciphertext di campo A non funziona in campo B
 *   - Valori pre-migrazione (senza field tag) ancora decifrabili
 *   - Valori corrotti restituiscono null invece di lanciare eccezione
 *
 * SETUP:
 *   I test usano un Model Eloquent anonimo in memoria.
 *   Non servono connessioni DB — il cast opera solo su stringhe in memoria.
 */

// Modello Eloquent anonimo con due campi cifrati — usato in tutti i test
function makeModelWithCasts(): Model
{
    return new class extends Model {
        use HasEncryptedAttributes;

        protected $casts = [
            'iban'   => EncryptedString::class,
            'salary' => EncryptedString::class,
        ];
    };
}

// Istanza del cast con field binding attivo (default)
function cast(): EncryptedString
{
    return new EncryptedString(fieldBinding: true);
}

// Istanza del cast senza field binding (per testare valori pre-migrazione)
function castNoBinding(): EncryptedString
{
    return new EncryptedString(fieldBinding: false);
}

// ---------------------------------------------------------------------------
// Cifratura e decifratura base
// ---------------------------------------------------------------------------

it('cifra il valore in set() e non lo salva in chiaro', function () {
    $model  = makeModelWithCasts();
    $result = cast()->set($model, 'iban', 'IT60 X054 2811 1010 0000 0123 456', []);

    // Il risultato non deve contenere il valore originale
    expect($result)->not->toContain('IT60 X054 2811 1010 0000 0123 456');
    // Deve contenere il field tag
    expect($result)->toStartWith('field:iban|');
});

it('decifra il valore in get() e restituisce il testo originale', function () {
    $model      = makeModelWithCasts();
    $ciphertext = cast()->set($model, 'iban', 'IT60 X054 2811 1010 0000 0123 456', []);
    $plaintext  = cast()->get($model, 'iban', $ciphertext, []);

    expect($plaintext)->toBe('IT60 X054 2811 1010 0000 0123 456');
});

it('set() con null restituisce null', function () {
    $model = makeModelWithCasts();

    expect(cast()->set($model, 'iban', null, []))->toBeNull();
});

it('set() con stringa vuota restituisce null', function () {
    $model = makeModelWithCasts();

    expect(cast()->set($model, 'iban', '', []))->toBeNull();
});

it('get() con null restituisce null', function () {
    $model = makeModelWithCasts();

    expect(cast()->get($model, 'iban', null, []))->toBeNull();
});

it('get() con valore corrotto restituisce null invece di lanciare eccezione', function () {
    $model = makeModelWithCasts();

    // Un ciphertext corrotto o manomesso non deve esporre eccezioni
    $result = cast()->get($model, 'iban', 'field:iban|CIPHERTEXT_CORROTTO', []);

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// Field binding — protezione contro il campo-swap attack
// ---------------------------------------------------------------------------

it('field binding: ciphertext del campo iban non decifra nel campo salary', function () {
    $model = makeModelWithCasts();

    // Cifra come 'iban'
    $ciphertextOfIban = cast()->set($model, 'iban', '12345', []);

    // Tenta di decifrare lo stesso ciphertext come 'salary' — deve restituire null
    // (il field tag 'field:iban|...' non corrisponde al campo 'salary')
    $result = cast()->get($model, 'salary', $ciphertextOfIban, []);

    expect($result)->toBeNull();
});

it('valore pre-migrazione senza field tag viene decifrato senza field binding check', function () {
    $model = makeModelWithCasts();

    // Un valore cifrato senza field tag (formato pre-migrazione):
    // non inizia con 'field:', quindi viene trattato come legacy
    $legacyCiphertext = castNoBinding()->set($model, 'iban', 'VALORE_LEGACY', []);
    // Il cast senza binding non aggiunge 'field:iban|'
    expect($legacyCiphertext)->not->toStartWith('field:iban|');

    // Il cast con binding deve accettare il valore legacy senza errori
    $result = cast()->get($model, 'iban', $legacyCiphertext, []);

    expect($result)->toBe('VALORE_LEGACY');
});

// ---------------------------------------------------------------------------
// HasEncryptedAttributes trait
// ---------------------------------------------------------------------------

it('encryptedFields() elenca i campi con cast EncryptedString', function () {
    $model = makeModelWithCasts();

    expect($model->encryptedFields())->toBe(['iban', 'salary']);
});

it('hasEncryptedField() restituisce true solo per campi cifrati', function () {
    $model = makeModelWithCasts();

    expect($model->hasEncryptedField('iban'))->toBeTrue();
    expect($model->hasEncryptedField('salary'))->toBeTrue();
    expect($model->hasEncryptedField('email'))->toBeFalse();
});
