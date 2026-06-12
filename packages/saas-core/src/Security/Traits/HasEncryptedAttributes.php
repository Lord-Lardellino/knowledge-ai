<?php

namespace SaaS\Core\Security\Traits;

use SaaS\Core\Security\Casts\EncryptedString;

/**
 * HasEncryptedAttributes — Trait per Eloquent Model con campi cifrati.
 *
 * PERCHÉ ESISTE:
 *   Il cast EncryptedString::class va dichiarato manualmente per ogni campo in $casts.
 *   Questo trait aggiunge metodi di utilità che semplificano la gestione dei campi cifrati:
 *     - encryptedFields(): lista i campi cifrati del modello
 *     - hasEncryptedField(): verifica se un campo è cifrato
 *     - getRawEncrypted(): legge il ciphertext grezzo (utile per esportazione GDPR)
 *
 * USO:
 *   class Employee extends Model
 *   {
 *       use HasEncryptedAttributes;
 *
 *       protected $casts = [
 *           'iban'   => EncryptedString::class,
 *           'salary' => EncryptedString::class,
 *       ];
 *   }
 *
 *   // Poi:
 *   $employee->encryptedFields();    // ['iban', 'salary']
 *   $employee->hasEncryptedField('iban'); // true
 */
trait HasEncryptedAttributes
{
    /**
     * Restituisce la lista dei campi che usano EncryptedString cast.
     *
     * @return array<string>
     */
    public function encryptedFields(): array
    {
        return array_keys(array_filter(
            $this->getCasts(),
            fn ($cast) => $cast === EncryptedString::class
                || (is_string($cast) && str_starts_with($cast, EncryptedString::class))
        ));
    }

    /**
     * Verifica se un campo specifico è cifrato.
     */
    public function hasEncryptedField(string $field): bool
    {
        return in_array($field, $this->encryptedFields(), true);
    }

    /**
     * Legge il ciphertext grezzo di un campo cifrato, senza decifrare.
     *
     * Utile per esportazione GDPR: puoi esportare i dati cifrati
     * senza esporre i valori in chiaro nei log di export.
     *
     * Restituisce null se il campo non è cifrato o non esiste.
     */
    public function getRawEncrypted(string $field): ?string
    {
        if (! $this->hasEncryptedField($field)) {
            return null;
        }

        return $this->getRawOriginal($field);
    }
}
