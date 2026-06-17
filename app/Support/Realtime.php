<?php

namespace App\Support;

use App\Models\Document;
use SaaS\Core\Broadcasting\TenantBroadcast;

/**
 * Realtime — scorciatoie per gli eventi broadcast dell'app sul canale del tenant.
 *
 * Usa l'evento generico del Core (SaaS\Core\Broadcasting\TenantBroadcast): nessuna
 * classe evento dedicata. I nomi evento combaciano con i listener Echo del frontend.
 */
class Realtime
{
    /** Stato di un documento cambiato (indicizzazione / metadati). */
    public static function document(Document $document): void
    {
        TenantBroadcast::dispatch((int) $document->tenant_id, 'document.updated', [
            'id'              => $document->id,
            'status'          => $document->status,
            'chunk_count'     => (int) $document->chunk_count,
            'metadata_status' => $document->metadata_status,
            'matter_id'       => $document->matter_id,
        ]);
    }

    /** Numero aggiornato di documenti "da smistare" (matter_id null) del tenant. */
    public static function triage(int $tenantId): void
    {
        $count = Document::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('matter_id')
            ->count();

        TenantBroadcast::dispatch($tenantId, 'triage.updated', ['count' => $count]);
    }
}
