<?php

namespace SaaS\Core\Kernel\Contracts;

/**
 * AuditDriverInterface
 *
 * Contratto per il driver di storage dei log di audit.
 *
 * PERCHÉ UN'INTERFACCIA?
 * Di default i log vanno nel database (tabella activity_log).
 * Un SaaS enterprise potrebbe volerli su Elasticsearch per ricerche full-text,
 * o su un sistema esterno di SIEM per compliance.
 * Questa interfaccia permette di aggiungere driver senza toccare il Trait Auditable.
 *
 * COME FUNZIONA IL FLUSSO:
 *   Model cambia → Trait Auditable intercetta → chiama AuditDriverInterface::log()
 *   Il driver concreto decide dove salvare il log.
 */
interface AuditDriverInterface
{
    /**
     * Registra un evento di audit.
     *
     * @param  string $event       Tipo di evento: 'created', 'updated', 'deleted'
     * @param  string $modelClass  Classe del Model che ha subito la modifica (es. "App\Models\Invoice")
     * @param  int    $modelId     ID del record modificato
     * @param  array  $changes     Array con i campi modificati: ['field' => ['old' => x, 'new' => y]]
     * @param  int|null $userId    ID dell'utente che ha fatto la modifica (null se sistema)
     */
    public function log(
        string $event,
        string $modelClass,
        int $modelId,
        array $changes,
        ?int $userId
    ): void;
}
