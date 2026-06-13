<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Knowledge\GeminiEmbedder;
use App\Services\Knowledge\TextChunker;
use App\Services\Knowledge\TextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * ProcessDocument — pipeline asincrona di indicizzazione di un documento.
 *
 * Flusso (verrà completato nei prossimi step):
 *   1. estrazione testo (PDF/DOCX/XLSX/TXT)
 *   2. chunking
 *   3. embedding (Gemini Embedding 2, batch) → salvataggio in pgvector
 *
 * Riceve l'ID (non il model) per evitare problemi di serializzazione e perché
 * il job gira fuori dal contesto tenant: carichiamo il documento bypassando
 * il TenantScope.
 */
class ProcessDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $documentId)
    {
    }

    public function handle(TextExtractor $extractor, GeminiEmbedder $embedder): void
    {
        $document = Document::withoutGlobalScopes()->find($this->documentId);

        if (! $document) {
            return; // documento eliminato prima del processing
        }

        $document->update(['status' => Document::STATUS_PROCESSING]);

        // 1. Estrazione testo dal file (PDF/DOCX/XLSX/TXT)
        $text = $extractor->extract($document);

        // 2. Chunking
        $chunks = TextChunker::fromConfig()->chunk($text);

        // 3. Persistenza chunk (senza embedding) — idempotente sui retry
        $ids = DB::transaction(function () use ($document, $chunks) {
            $document->chunks()->delete();

            $ids = [];
            foreach ($chunks as $index => $content) {
                $ids[] = DocumentChunk::create([
                    'tenant_id'   => $document->tenant_id,
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'content'     => $content,
                    'token_count' => (int) ceil(mb_strlen($content) / 4), // stima grezza
                ])->id;
            }

            $document->update(['chunk_count' => \count($chunks)]);

            return $ids;
        });

        // 4. Embedding in batch (Gemini Embedding 2) → salva i vettori
        $batchSize = (int) config('knowledge.embedding.batch_size', 100);

        foreach (array_chunk(array_combine($ids, $chunks), $batchSize, true) as $batch) {
            $vectors = $embedder->embedDocuments(array_values($batch));

            $i = 0;
            foreach (array_keys($batch) as $chunkId) {
                // Query builder bypassa il cast Vector → formattiamo il literal pgvector a mano.
                $literal = '[' . implode(',', array_map(static fn ($v) => (float) $v, $vectors[$i])) . ']';

                DocumentChunk::withoutGlobalScopes()
                    ->where('id', $chunkId)
                    ->update(['embedding' => $literal]);
                $i++;
            }
        }

        // 5. Indicizzato
        $document->update([
            'status'     => Document::STATUS_INDEXED,
            'indexed_at' => now(),
            'error'      => null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Document::withoutGlobalScopes()
            ->where('id', $this->documentId)
            ->update([
                'status' => Document::STATUS_FAILED,
                'error'  => $e->getMessage(),
            ]);
    }
}
