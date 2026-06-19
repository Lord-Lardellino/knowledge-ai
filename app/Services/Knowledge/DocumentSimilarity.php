<?php

namespace App\Services\Knowledge;

use App\Models\Document;
use Illuminate\Support\Facades\DB;

class DocumentSimilarity
{
    public function __construct(private GeminiEmbedder $embedder)
    {
    }

    /**
     * @return array<int, array{client_id:int|null, client_name:string, documents:array<int,array>}>
     */
    public function similarTo(Document $document, int $limit = 30, string $mode = 'semantic', ?string $query = null): array
    {
        $limit = max(1, min(80, $limit));
        $query = trim((string) $query);

        return match ($mode) {
            'exact' => $this->exactMatches($document, $limit),
            'client' => $this->clientMatches($document, $limit, $query),
            'semantic_query' => $this->semanticQuery($document, $limit, $query),
            default => $this->semanticDocument($document, $limit),
        };
    }

    private function semanticDocument(Document $document, int $limit): array
    {
        abort_if($document->chunk_count < 1, 422, 'Il documento base non ha chunk indicizzati.');

        // Centroide del documento (media embedding) + testo completo: confrontiamo
        // documento-vs-documento, NON il singolo chunk più vicino — così una sola
        // riga/intestazione condivisa non gonfia il punteggio.
        $similarity = $this->similarityExpression('1 - (docs.emb <=> base.emb)', 'similarity(base.txt, docs.txt)');

        $rows = DB::select(
            <<<SQL
            WITH base AS (
                SELECT avg(embedding) AS emb,
                       string_agg(content, ' ' ORDER BY chunk_index) AS txt
                FROM document_chunks
                WHERE tenant_id = ? AND document_id = ? AND embedding IS NOT NULL
            ), docs AS (
                SELECT
                    d.id AS document_id,
                    d.title AS document_title,
                    d.extension AS extension,
                    d.status AS status,
                    d.chunk_count AS chunk_count,
                    d.created_at AS created_at,
                    m.id AS matter_id,
                    m.title AS matter_title,
                    c.id AS client_id,
                    c.name AS client_name,
                    avg(dc.embedding) AS emb,
                    string_agg(dc.content, ' ' ORDER BY dc.chunk_index) AS txt,
                    (array_agg(dc.content ORDER BY dc.chunk_index))[1] AS preview
                FROM document_chunks dc
                JOIN documents d ON d.id = dc.document_id AND d.tenant_id = dc.tenant_id
                LEFT JOIN matters m ON m.id = d.matter_id
                LEFT JOIN clients c ON c.id = m.client_id
                WHERE dc.tenant_id = ?
                  AND dc.document_id <> ?
                  AND dc.embedding IS NOT NULL
                  AND d.status = 'indexed'
                GROUP BY d.id, d.title, d.extension, d.status, d.chunk_count, d.created_at,
                         m.id, m.title, c.id, c.name
            )
            SELECT docs.document_id, docs.document_title, docs.extension, docs.status,
                   docs.chunk_count, docs.created_at, docs.matter_id, docs.matter_title,
                   docs.client_id, docs.client_name, docs.preview,
                   {$similarity} AS similarity
            FROM docs, base
            WHERE base.emb IS NOT NULL
            ORDER BY similarity DESC
            LIMIT ?
            SQL,
            [$document->tenant_id, $document->id, $document->tenant_id, $document->id, $limit]
        );

        return $this->groupRows($rows);
    }

    /**
     * Espressione SQL del punteggio di similarità: media geometrica pesata fra
     * coseno semantico e similarità lessicale (trigram), oppure solo semantico
     * se il blend è disattivato. I pesi vengono da config knowledge.similarity.
     */
    private function similarityExpression(string $semantic, string $lexical): string
    {
        $cfg = (array) config('knowledge.similarity', []);

        $floor = (float) ($cfg['floor'] ?? 0.83);
        $ceil  = (float) ($cfg['ceil'] ?? 0.97);
        $span  = max(0.01, $ceil - $floor);

        // Calibrazione: rimappa il coseno [floor, ceil] su [0, 1] e azzera il
        // pavimento di dominio. %F = float locale-independente (no virgola).
        $calibrated = \sprintf('greatest(0, least(1, (%s - %F) / %F))', $semantic, $floor, $span);

        if (! ($cfg['lexical_blend'] ?? false)) {
            return $calibrated;
        }

        // Opzionale: media geometrica pesata col lessicale (caccia ai duplicati).
        $ws = (float) ($cfg['semantic_weight'] ?? 0.5);
        $wl = (float) ($cfg['lexical_weight'] ?? 0.5);

        return \sprintf(
            'power(greatest(%s, 0), %F) * power(greatest(%s, 0), %F)',
            $calibrated,
            $ws,
            $lexical,
            $wl
        );
    }

    private function semanticQuery(Document $document, int $limit, string $query): array
    {
        abort_if($query === '', 422, 'Inserisci un campo semantico da cercare.');

        $literal = $this->vectorLiteral($this->embedder->embedQuery($query));

        $rows = DB::select(
            <<<SQL
            WITH ranked AS (
                SELECT
                    d.id AS document_id,
                    d.title AS document_title,
                    d.extension AS extension,
                    d.status AS status,
                    d.chunk_count AS chunk_count,
                    d.created_at AS created_at,
                    m.id AS matter_id,
                    m.title AS matter_title,
                    c.id AS client_id,
                    c.name AS client_name,
                    dc.content AS preview,
                    1 - (dc.embedding <=> ?::vector) AS similarity,
                    row_number() OVER (
                        PARTITION BY d.id
                        ORDER BY dc.embedding <=> ?::vector
                    ) AS rn
                FROM document_chunks dc
                JOIN documents d ON d.id = dc.document_id AND d.tenant_id = dc.tenant_id
                LEFT JOIN matters m ON m.id = d.matter_id
                LEFT JOIN clients c ON c.id = m.client_id
                WHERE dc.tenant_id = ?
                  AND dc.document_id <> ?
                  AND dc.embedding IS NOT NULL
                  AND d.status = 'indexed'
            )
            SELECT *
            FROM ranked
            WHERE rn = 1
            ORDER BY similarity DESC
            LIMIT ?
            SQL,
            [$literal, $literal, $document->tenant_id, $document->id, $limit]
        );

        return $this->groupRows($rows);
    }

    private function exactMatches(Document $document, int $limit): array
    {
        $checksum = trim((string) $document->checksum);
        $title = mb_strtolower(trim((string) $document->title));

        if ($checksum === '' && $title === '') {
            return [];
        }

        $rows = DB::select(
            <<<SQL
            SELECT
                d.id AS document_id,
                d.title AS document_title,
                d.extension AS extension,
                d.status AS status,
                d.chunk_count AS chunk_count,
                d.created_at AS created_at,
                m.id AS matter_id,
                m.title AS matter_title,
                c.id AS client_id,
                c.name AS client_name,
                first_chunk.content AS preview,
                CASE
                    WHEN ? <> '' AND d.checksum = ? THEN 1.0
                    WHEN lower(d.title) = ? THEN 0.92
                    ELSE 0.0
                END AS similarity
            FROM documents d
            LEFT JOIN matters m ON m.id = d.matter_id
            LEFT JOIN clients c ON c.id = m.client_id
            LEFT JOIN LATERAL (
                SELECT content
                FROM document_chunks
                WHERE tenant_id = d.tenant_id AND document_id = d.id
                ORDER BY chunk_index ASC
                LIMIT 1
            ) first_chunk ON true
            WHERE d.tenant_id = ?
              AND d.id <> ?
              AND d.status = 'indexed'
              AND ((? <> '' AND d.checksum = ?) OR lower(d.title) = ?)
            ORDER BY similarity DESC, d.created_at DESC
            LIMIT ?
            SQL,
            [$checksum, $checksum, $title, $document->tenant_id, $document->id, $checksum, $checksum, $title, $limit]
        );

        return $this->groupRows($rows);
    }

    private function clientMatches(Document $document, int $limit, string $query): array
    {
        $document->loadMissing('matter.client');
        $clientId = $query === '' ? $document->matter?->client_id : null;
        $clientLike = $query !== '' ? '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%' : null;

        if ($clientId === null && $clientLike === null) {
            return [];
        }

        if ($document->chunk_count > 0) {
            return $this->clientMatchesRanked($document, $limit, $clientId, $clientLike);
        }

        $rows = DB::select(
            <<<SQL
            SELECT
                d.id AS document_id,
                d.title AS document_title,
                d.extension AS extension,
                d.status AS status,
                d.chunk_count AS chunk_count,
                d.created_at AS created_at,
                m.id AS matter_id,
                m.title AS matter_title,
                c.id AS client_id,
                c.name AS client_name,
                first_chunk.content AS preview,
                0.7 AS similarity
            FROM documents d
            JOIN matters m ON m.id = d.matter_id
            JOIN clients c ON c.id = m.client_id
            LEFT JOIN LATERAL (
                SELECT content
                FROM document_chunks
                WHERE tenant_id = d.tenant_id AND document_id = d.id
                ORDER BY chunk_index ASC
                LIMIT 1
            ) first_chunk ON true
            WHERE d.tenant_id = ?
              AND d.id <> ?
              AND d.status = 'indexed'
              AND (?::bigint IS NULL OR c.id = ?::bigint)
              AND (?::text IS NULL OR c.name ILIKE ? ESCAPE '\\')
            ORDER BY d.created_at DESC
            LIMIT ?
            SQL,
            [$document->tenant_id, $document->id, $clientId, $clientId, $clientLike, $clientLike, $limit]
        );

        return $this->groupRows($rows);
    }

    private function clientMatchesRanked(Document $document, int $limit, ?int $clientId, ?string $clientLike): array
    {
        $similarity = $this->similarityExpression('1 - (docs.emb <=> base.emb)', 'similarity(base.txt, docs.txt)');

        $rows = DB::select(
            <<<SQL
            WITH base AS (
                SELECT avg(embedding) AS emb,
                       string_agg(content, ' ' ORDER BY chunk_index) AS txt
                FROM document_chunks
                WHERE tenant_id = ? AND document_id = ? AND embedding IS NOT NULL
            ), docs AS (
                SELECT
                    d.id AS document_id,
                    d.title AS document_title,
                    d.extension AS extension,
                    d.status AS status,
                    d.chunk_count AS chunk_count,
                    d.created_at AS created_at,
                    m.id AS matter_id,
                    m.title AS matter_title,
                    c.id AS client_id,
                    c.name AS client_name,
                    avg(dc.embedding) AS emb,
                    string_agg(dc.content, ' ' ORDER BY dc.chunk_index) AS txt,
                    (array_agg(dc.content ORDER BY dc.chunk_index))[1] AS preview
                FROM document_chunks dc
                JOIN documents d ON d.id = dc.document_id AND d.tenant_id = dc.tenant_id
                JOIN matters m ON m.id = d.matter_id
                JOIN clients c ON c.id = m.client_id
                WHERE dc.tenant_id = ?
                  AND dc.document_id <> ?
                  AND dc.embedding IS NOT NULL
                  AND d.status = 'indexed'
                  AND (?::bigint IS NULL OR c.id = ?::bigint)
                  AND (?::text IS NULL OR c.name ILIKE ? ESCAPE '\\')
                GROUP BY d.id, d.title, d.extension, d.status, d.chunk_count, d.created_at,
                         m.id, m.title, c.id, c.name
            )
            SELECT docs.document_id, docs.document_title, docs.extension, docs.status,
                   docs.chunk_count, docs.created_at, docs.matter_id, docs.matter_title,
                   docs.client_id, docs.client_name, docs.preview,
                   {$similarity} AS similarity
            FROM docs, base
            WHERE base.emb IS NOT NULL
            ORDER BY similarity DESC
            LIMIT ?
            SQL,
            [$document->tenant_id, $document->id, $document->tenant_id, $document->id, $clientId, $clientId, $clientLike, $clientLike, $limit]
        );

        return $this->groupRows($rows);
    }

    /**
     * @param array<int, object> $rows
     * @return array<int, array{client_id:int|null, client_name:string, documents:array<int,array>}>
     */
    private function groupRows(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $clientId = $row->client_id !== null ? (int) $row->client_id : null;
            $groupKey = $clientId !== null ? 'client:' . $clientId : 'none';
            $similarity = round((float) $row->similarity, 4);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'client_id' => $clientId,
                    'client_name' => $row->client_name ?: 'Senza cliente',
                    'best_similarity' => $similarity,
                    'documents' => [],
                ];
            }

            $groups[$groupKey]['best_similarity'] = max($groups[$groupKey]['best_similarity'], $similarity);
            $groups[$groupKey]['documents'][] = [
                'id' => (int) $row->document_id,
                'title' => $row->document_title,
                'extension' => $row->extension,
                'status' => $row->status,
                'chunk_count' => (int) $row->chunk_count,
                'created_at' => $row->created_at,
                'matter' => $row->matter_id ? [
                    'id' => (int) $row->matter_id,
                    'title' => $row->matter_title,
                ] : null,
                'similarity' => $similarity,
                'preview' => $this->preview((string) ($row->preview ?? '')),
            ];
        }

        foreach ($groups as &$group) {
            usort($group['documents'], fn (array $a, array $b) => $b['similarity'] <=> $a['similarity']);
            unset($group['best_similarity']);
        }
        unset($group);

        uasort($groups, function (array $a, array $b): int {
            $aBest = max(array_column($a['documents'], 'similarity') ?: [0]);
            $bBest = max(array_column($b['documents'], 'similarity') ?: [0]);

            return $bBest <=> $aBest;
        });

        return array_values($groups);
    }

    /** @param array<int, float|int> $vector */
    private function vectorLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(static fn ($value) => (float) $value, $vector)) . ']';
    }

    private function preview(string $content): string
    {
        $content = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);

        return mb_strlen($content) > 420
            ? mb_substr($content, 0, 420) . '...'
            : $content;
    }
}
