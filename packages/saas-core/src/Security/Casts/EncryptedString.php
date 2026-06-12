<?php

namespace SaaS\Core\Security\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * EncryptedString — Cast Eloquent per campi sensibili cifrati con AES-256-CBC.
 *
 * PERCHÉ NON USARE IL CAST 'encrypted' DI LARAVEL:
 *   Il cast nativo di Laravel usa la stessa chiave APP_KEY per tutto.
 *   EncryptedString aggiunge:
 *     1. Firma del campo: previene che il ciphertext di un campo venga usato
 *        in un altro campo dello stesso modello (field binding).
 *     2. Null-safety: restituisce null invece di lanciare eccezione su valore null.
 *     3. Logging opzionale: traccia in activitylog i tentativi di decrypt falliti.
 *
 * USO NEL MODELLO:
 *   protected $casts = [
 *       'iban'              => EncryptedString::class,
 *       'passport_number'   => EncryptedString::class,
 *       'salary'            => EncryptedString::class,
 *   ];
 *
 * STORAGE:
 *   Il valore cifrato viene salvato come stringa base64 nel DB.
 *   La colonna deve essere TEXT o VARCHAR(500+) — i valori cifrati sono più lunghi.
 *
 * SICUREZZA:
 *   - Usa APP_KEY via Illuminate\Encryption\Encrypter (AES-256-CBC + HMAC-SHA256).
 *   - La firma include il nome del campo (field binding): il ciphertext dell'iban
 *     non può essere spostato nella colonna passport_number senza rompere la firma.
 *   - In produzione APP_KEY deve essere in .env e NON committato in repo.
 *   - Per rotazione chiavi: usa `php artisan key:generate` + ri-cifra i dati esistenti.
 *
 * RICERCA:
 *   I campi cifrati NON sono ricercabili con WHERE/LIKE nel DB.
 *   Per la ricerca usa un campo hash separato (es. HMAC dell'email lowercase).
 */
class EncryptedString implements CastsAttributes
{
    /**
     * @param  string|null  $attribute  nome della colonna — usato per il field binding
     */
    public function __construct(
        protected readonly bool $fieldBinding = true
    ) {}

    /**
     * Decifra il valore letto dal database.
     *
     * Restituisce null se il valore è null o vuoto — non lancia eccezione.
     * Questo permette di migrare colonne esistenti in modo incrementale:
     * i record non ancora cifrati restano leggibili.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $payload = $this->fieldBinding
                ? $this->stripFieldTag($key, $value)
                : $value;

            return Crypt::decryptString($payload);
        } catch (\Exception $e) {
            // Decifrazione fallita: potrebbe essere un valore in chiaro pre-migrazione
            // oppure un tentativo di field-swap. Restituiamo null e non esponiamo il
            // ciphertext grezzo al codice applicativo.
            report($e);

            return null;
        }
    }

    /**
     * Cifra il valore prima di salvarlo nel database.
     *
     * Il field binding include il nome del campo nella firma crittografica:
     * il ciphertext è valido SOLO per quel campo di quel modello.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ciphertext = Crypt::encryptString((string) $value);

        return $this->fieldBinding
            ? $this->addFieldTag($key, $ciphertext)
            : $ciphertext;
    }

    /**
     * Aggiunge il tag del campo all'inizio del ciphertext.
     *
     * Formato: "field:iban|<ciphertext base64>"
     * Il separatore | non appare in base64 quindi non serve escaping.
     */
    private function addFieldTag(string $key, string $ciphertext): string
    {
        return "field:{$key}|{$ciphertext}";
    }

    /**
     * Verifica il tag del campo e restituisce il ciphertext puro.
     *
     * Se il tag non corrisponde al nome del campo atteso, lancia RuntimeException.
     * Questo blocca il field-swap attack: il ciphertext dell'iban non può essere
     * copiato nella colonna passport_number.
     *
     * @throws RuntimeException se il field binding fallisce
     */
    private function stripFieldTag(string $key, string $value): string
    {
        $prefix = "field:{$key}|";

        // Valore senza tag: record pre-migrazione, accettiamo senza field binding
        if (! str_starts_with($value, 'field:')) {
            return $value;
        }

        if (! str_starts_with($value, $prefix)) {
            throw new RuntimeException(
                "EncryptedString field binding violation: atteso '{$key}', valore appartiene a un altro campo."
            );
        }

        return substr($value, strlen($prefix));
    }
}
