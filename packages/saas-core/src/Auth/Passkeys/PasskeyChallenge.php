<?php

namespace SaaS\Core\Auth\Passkeys;

use Illuminate\Support\Facades\Cache;

/**
 * PasskeyChallenge
 *
 * Gestisce il ciclo di vita della challenge WebAuthn.
 *
 * COS'È UNA CHALLENGE?
 * È un token casuale che il server genera e manda al client.
 * Il client lo firma con la sua chiave privata (salvata sul dispositivo).
 * Il server verifica la firma con la chiave pubblica registrata.
 * Questo prova che il client possiede fisicamente il dispositivo.
 *
 * PERCHÉ REDIS (Laravel Cache)?
 * La challenge deve vivere solo pochi minuti.
 * Cache::put() con TTL fa esattamente questo: scade automaticamente,
 * è veloce, non sporca il database.
 *
 * PERCHÉ NON LA SESSIONE?
 * React Native non ha sessioni cookie.
 * La Cache di Laravel funziona per entrambi (web e mobile)
 * usando come chiave l'identifier dell'utente.
 */
class PasskeyChallenge
{
    // Prefisso delle chiavi Cache per evitare collisioni con altre chiavi dell'app
    private const CACHE_PREFIX = 'passkey_challenge:';

    // Durata della challenge — 5 minuti sono più che sufficienti per firmarla
    private const TTL_SECONDS = 300;

    /**
     * Genera e salva una nuova challenge per un dato identificatore.
     *
     * $identifier può essere:
     *   - l'email dell'utente durante il login
     *   - un nonce temporaneo durante la registrazione
     *
     * Restituisce la challenge (stringa hex da 64 caratteri) da mandare al client.
     */
    public function generate(string $identifier): string
    {
        // random_bytes() genera byte crittograficamente sicuri (CSPRNG).
        // bin2hex() li converte in stringa esadecimale leggibile dal client.
        // Str::random() di Laravel NON va bene qui: non è crittograficamente sicuro.
        $challenge = bin2hex(random_bytes(32));

        Cache::put(
            self::CACHE_PREFIX . $identifier,
            $challenge,
            self::TTL_SECONDS
        );

        return $challenge;
    }

    /**
     * Verifica che la challenge fornita corrisponda a quella salvata in Cache.
     *
     * Dopo la verifica la challenge viene eliminata — usa-e-getta.
     * Protezione da replay attack: non puoi riusare la stessa firma due volte.
     */
    public function verify(string $identifier, string $challenge): bool
    {
        $stored = Cache::get(self::CACHE_PREFIX . $identifier);

        if (is_null($stored)) {
            // Challenge non trovata: scaduta o mai generata
            return false;
        }

        // Elimina subito, anche se non corrisponde.
        // Un tentativo fallito non può riprovare con la stessa challenge.
        Cache::forget(self::CACHE_PREFIX . $identifier);

        // hash_equals() per confronto time-safe: evita timing attack.
        // Un === normale impiega più tempo se i primi caratteri coincidono,
        // rivelando informazioni sulla challenge corretta.
        return hash_equals($stored, $challenge);
    }
}
