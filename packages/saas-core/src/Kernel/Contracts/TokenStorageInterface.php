<?php

namespace SaaS\Core\Kernel\Contracts;

/**
 * TokenStorageInterface
 *
 * Contratto per lo storage dei token di autenticazione mobile.
 *
 * PERCHÉ UN'INTERFACCIA?
 * Di default i token Sanctum vengono salvati nel database (tabella personal_access_tokens).
 * Un SaaS ad alto traffico potrebbe volerli salvare su Redis per performance.
 * Questa interfaccia permette di swappare lo storage senza toccare la logica
 * di creazione/rotazione dei token.
 */
interface TokenStorageInterface
{
    /**
     * Salva un token associato a un utente e un device.
     *
     * @param  int    $userId    ID dell'utente proprietario del token
     * @param  string $deviceId  Identificatore univoco del dispositivo mobile
     * @param  string $token     Il token da salvare (già hashato)
     * @param  int    $ttl       Durata in secondi
     */
    public function store(int $userId, string $deviceId, string $token, int $ttl): void;

    /**
     * Recupera il token per un dato utente e device.
     * Restituisce null se non esiste o è scaduto.
     */
    public function retrieve(int $userId, string $deviceId): ?string;

    /**
     * Revoca tutti i token di un utente su tutti i dispositivi.
     * Usato in caso di logout globale o compromissione dell'account.
     */
    public function revokeAll(int $userId): void;
}
