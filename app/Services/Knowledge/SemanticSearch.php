<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\DB;

/**
 * SemanticSearch — ricerca per similarità sui chunk di un tenant.
 *
 * Trasforma la query in embedding (RETRIEVAL_QUERY) e cerca i chunk più vicini
 * con l'operatore di distanza coseno di pgvector (<=>), sfruttando l'indice HNSW.
 * Il filtro tenant_id è SEMPRE esplicito: la ricerca non passa dai model Eloquent.
 */
class SemanticSearch
{
    public function __construct(private GeminiEmbedder $embedder)
    {
    }

    /**
     * @return array<int, array{document_id:int, document_title:string, chunk_id:int, content:string, similarity:float}>
     */
    public function search(int $tenantId, string $query, int $limit = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $vector = $this->embedder->embedQuery($query);
        $literal = '[' . implode(',', array_map(static fn ($v) => (float) $v, $vector)) . ']';

        // distance = coseno [0..2]; similarity = 1 - distance per leggibilità.
        $rows = DB::select(
            <<<SQL
            SELECT
                dc.id          AS chunk_id,
                dc.document_id AS document_id,
                dc.content     AS content,
                d.title        AS document_title,
                1 - (dc.embedding <=> ?::vector) AS similarity
            FROM document_chunks dc
            JOIN documents d ON d.id = dc.document_id
            WHERE dc.tenant_id = ?
              AND dc.embedding IS NOT NULL
            ORDER BY dc.embedding <=> ?::vector
            LIMIT ?
            SQL,
            [$literal, $tenantId, $literal, $limit]
        );

        return array_map(fn ($r) => [
            'document_id'    => (int) $r->document_id,
            'document_title' => $r->document_title,
            'chunk_id'       => (int) $r->chunk_id,
            'content'        => $r->content,
            'similarity'     => round((float) $r->similarity, 4),
        ], $rows);
    }
}
