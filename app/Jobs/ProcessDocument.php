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
        \App\Support\Realtime::document($document);

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

        // 4. Embedding in batch (Gemini Embedding 2) → salva i vettori.
        //    Avanzamento: aggiorniamo embedded_chunks dopo ogni batch così la barra
        //    di progressione sale (e con Reverb attivo si vede in tempo reale).
        $batchSize = $this->embeddingBatchSize(\count($chunks));

        $chunkMap = array_combine($ids, $chunks);

        if ($chunkMap === false) {
            throw new \RuntimeException('Errore durante la preparazione dei chunk del documento.');
        }

        $document->update(['embedded_chunks' => 0]);
        $done = 0;

        foreach (array_chunk($chunkMap, $batchSize, true) as $batch) {
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

            $done += \count($batch);
            $document->update(['embedded_chunks' => $done]);
            \App\Support\Realtime::document($document);
        }

        // 5. Indicizzato
        $document->update([
            'status'     => Document::STATUS_INDEXED,
            'indexed_at' => now(),
            'error'      => null,
        ]);
        \App\Support\Realtime::document($document);

        // 6. Estrazione metadati legali (verticale legale) — solo se il modulo
        //    è attivo. Job separato per isolare i fallimenti AI dalla pipeline.
        if (config('knowledge.legal.enabled')) {
            ExtractDocumentMetadata::dispatch($document->id);
        }
    }

    // Batch di embedding: mai "tutti in una richiesta" (un documento da centinaia
    // di chunk satura il modello → 503/timeout). Default prudente a 100, override via config.
    private const DEFAULT_EMBED_BATCH = 100;

    private function embeddingBatchSize(int $chunkCount): int
    {
        $configured = config('knowledge.embedding.batch_size');

        if ($configured === null || $configured === '' || (int) $configured <= 0) {
            return max(1, min($chunkCount, self::DEFAULT_EMBED_BATCH));
        }

        return max(1, (int) $configured);
    }

    public function failed(\Throwable $e): void
    {
        Document::withoutGlobalScopes()
            ->where('id', $this->documentId)
            ->update([
                'status' => Document::STATUS_FAILED,
                'error'  => $e->getMessage(),
            ]);

        if ($document = Document::withoutGlobalScopes()->find($this->documentId)) {
            \App\Support\Realtime::document($document);
        }
    }
}
