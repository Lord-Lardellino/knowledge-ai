<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Knowledge\MetadataExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ExtractDocumentMetadata — estrazione asincrona dei metadati legali di un documento.
 *
 * Gira dopo l'indicizzazione (ProcessDocument): ricompone il testo dai chunk già
 * salvati e chiede a Gemma i metadati strutturati. Asincrono e separato dalla
 * pipeline di indicizzazione per isolare i fallimenti AI e gestire il rate limit.
 *
 * Riceve l'ID (non il model) e bypassa il TenantScope: gira fuori dal contesto tenant.
 */
class ExtractDocumentMetadata implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    // Backoff progressivo: il primo retry è rapido, poi cresce per assorbire
    // i rate limit del free tier senza far sembrare la coda bloccata.
    /** @return int[] */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public int $documentId)
    {
    }

    public function handle(MetadataExtractor $extractor): void
    {
        $document = Document::withoutGlobalScopes()->find($this->documentId);

        if (! $document) {
            return; // documento eliminato prima dell'estrazione
        }

        // Mai estrarre due volte: se i metadati esistono già (estratti o confermati)
        // si salta, per non rispendere. La ri-estrazione esplicita passa dal bottone
        // "Ri-estrai", che riporta lo stato a 'pending' prima di rilanciare il job.
        if (in_array($document->metadata_status, [Document::META_READY, Document::META_CONFIRMED], true)) {
            return;
        }

        $document->update(['metadata_status' => Document::META_PROCESSING]);

        $text = $this->documentText($document);

        $metadata = $extractor->extract($text);

        $document->update([
            'metadata'        => $metadata,
            'metadata_status' => Document::META_READY,
            'metadata_error'  => null,
        ]);
        \App\Support\Realtime::document($document);
    }

    /** Ricompone il testo del documento dai chunk salvati, in ordine. */
    private function documentText(Document $document): string
    {
        return DocumentChunk::withoutGlobalScopes()
            ->where('document_id', $document->id)
            ->orderBy('chunk_index')
            ->pluck('content')
            ->implode("\n\n");
    }

    public function failed(\Throwable $e): void
    {
        Document::withoutGlobalScopes()
            ->where('id', $this->documentId)
            ->update([
                'metadata_status' => Document::META_FAILED,
                'metadata_error'  => $e->getMessage(),
            ]);

        if ($document = Document::withoutGlobalScopes()->find($this->documentId)) {
            \App\Support\Realtime::document($document);
        }
    }
}
