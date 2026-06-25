<?php

namespace App\Services\Knowledge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * DocumentChat — risponde a domande in linguaggio naturale sui documenti del
 * tenant (RAG): recupera i chunk più pertinenti via pgvector e li passa come
 * contesto al modello, che risponde citando le fonti [n].
 *
 * Tutto è tenant-scoped in modo esplicito: la ricerca non passa dai model.
 */
class DocumentChat
{
    public function __construct(private GeminiEmbedder $embedder)
    {
    }

    /**
     * @param array<int,array{role:string,content:string}> $history turni precedenti
     * @return array{answer:string, sources:array<int,array{n:int,document_id:int,title:string,chunk_index:int,snippet:string,similarity:float}>}
     */
    public function ask(int $tenantId, string $question, ?int $matterId = null, array $history = []): array
    {
        $question = trim($question);
        abort_if($question === '', 422, 'Scrivi una domanda.');

        // Cache di OGNI risposta (anche multi-turno): chiave = tenant + pratica +
        // versione archivio + hash(domanda) + hash(storico). Stessa domanda, stesso
        // contesto, stesso filo → nessuna nuova chiamata al modello. La "versione
        // archivio" invalida da sola la cache quando i documenti cambiano.
        $historySig = sha1(json_encode(array_map(
            static fn ($t) => [$t['role'] ?? '', mb_strtolower(trim((string) ($t['content'] ?? '')))],
            $history
        )) ?: '');
        $cacheKey = 'chat:ans:' . $tenantId . ':' . ($matterId ?? 0) . ':'
            . $this->corpusVersion($tenantId, $matterId) . ':'
            . sha1(mb_strtolower($question)) . ':' . $historySig;

        if (($hit = Cache::get($cacheKey)) !== null) {
            return $hit;
        }

        $chunks = $this->retrieve($tenantId, $question, $matterId);

        if ($chunks === []) {
            return [
                'answer'  => 'Non ho trovato documenti pertinenti nell\'archivio per rispondere a questa domanda.',
                'sources' => [],
            ];
        }

        $answer = $this->generate($question, $chunks, $history);

        // Fallimento transitorio (es. 503 sovraccarico): messaggio gentile e NON in
        // cache, così il prossimo tentativo riprova davvero.
        if ($answer === null) {
            return [
                'answer'  => 'L\'assistente è momentaneamente sovraccarico. Riprova tra qualche secondo.',
                'sources' => [],
                'retry'   => true,
            ];
        }

        $result = [
            'answer'  => $answer,
            'sources' => array_map(static fn (array $c) => [
                'n'           => $c['n'],
                'document_id' => $c['document_id'],
                'title'       => $c['title'],
                'chunk_index' => $c['chunk_index'],
                'snippet'     => $c['snippet'],
                'similarity'  => $c['similarity'],
            ], $chunks),
        ];

        Cache::put($cacheKey, $result, (int) config('knowledge.chat.cache_ttl', 21600));

        return $result;
    }

    /**
     * "Versione" dell'archivio interrogabile: cambia quando si aggiungono/modificano/
     * eliminano documenti indicizzati → invalida la cache delle risposte. Query
     * leggerissima (conteggio + ultimo aggiornamento).
     */
    private function corpusVersion(int $tenantId, ?int $matterId): string
    {
        $row = DB::selectOne(
            <<<SQL
            SELECT count(*) AS c,
                   COALESCE(max(extract(epoch FROM updated_at))::bigint, 0) AS v
            FROM documents
            WHERE tenant_id = ? AND status = 'indexed'
              AND (?::bigint IS NULL OR matter_id = ?::bigint)
            SQL,
            [$tenantId, $matterId, $matterId]
        );

        return ($row->c ?? 0) . '-' . ($row->v ?? 0);
    }

    /**
     * Recupera i top-K chunk più vicini alla domanda (coseno pgvector),
     * opzionalmente ristretti a una pratica.
     *
     * @return array<int,array<string,mixed>>
     */
    private function retrieve(int $tenantId, string $question, ?int $matterId): array
    {
        // Cache embedding della domanda: le domande si ripetono, l'embedding no.
        $vector = Cache::remember(
            'chat:emb:' . sha1(mb_strtolower($question)),
            (int) config('knowledge.chat.embed_cache_ttl', 604800),
            fn () => $this->embedder->embedQuery($question),
        );
        $literal = '[' . implode(',', array_map(static fn ($v) => (float) $v, $vector)) . ']';
        $topK = max(1, (int) config('knowledge.chat.top_k', 6));
        $snippetChars = max(200, (int) config('knowledge.chat.snippet_chars', 1200));

        $rows = DB::select(
            <<<SQL
            SELECT
                dc.document_id AS document_id,
                dc.chunk_index AS chunk_index,
                dc.content     AS content,
                d.title        AS title,
                1 - (dc.embedding <=> ?::vector) AS similarity
            FROM document_chunks dc
            JOIN documents d ON d.id = dc.document_id AND d.tenant_id = dc.tenant_id
            WHERE dc.tenant_id = ?
              AND dc.embedding IS NOT NULL
              AND d.status = 'indexed'
              AND (?::bigint IS NULL OR d.matter_id = ?::bigint)
            ORDER BY dc.embedding <=> ?::vector
            LIMIT ?
            SQL,
            [$literal, $tenantId, $matterId, $matterId, $literal, $topK]
        );

        $out = [];
        foreach ($rows as $i => $r) {
            $content = trim(preg_replace('/\s+/u', ' ', (string) $r->content) ?? '');
            $out[] = [
                'n'           => $i + 1,
                'document_id' => (int) $r->document_id,
                'chunk_index' => (int) $r->chunk_index,
                'title'       => (string) $r->title,
                'snippet'     => mb_strlen($content) > $snippetChars ? mb_substr($content, 0, $snippetChars) . '…' : $content,
                'similarity'  => round((float) $r->similarity, 4),
            ];
        }

        return $out;
    }

    /**
     * Genera la risposta. Ritorna null su fallimento transitorio (es. 503
     * sovraccarico) dopo aver provato modello primario ed eventuale fallback,
     * così il chiamante mostra un messaggio gentile senza cachare l'errore.
     *
     * @param array<int,array<string,mixed>> $chunks
     */
    private function generate(string $question, array $chunks, array $history): ?string
    {
        $apiKey = (string) config('knowledge.gemini.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY non configurata.');
        }

        $baseUrl = rtrim((string) config('knowledge.gemini.base_url'), '/');
        $contents = $this->buildContents($question, $chunks, $history);
        $generationConfig = [
            'temperature'     => (float) config('knowledge.chat.temperature', 0.2),
            'maxOutputTokens' => (int) config('knowledge.chat.max_output_tokens', 600),
            'thinkingConfig'  => ['thinkingBudget' => (int) config('knowledge.chat.thinking_budget', 0)],
        ];

        // Primario + eventuale fallback (un altro modello quando il primo è sovraccarico).
        $models = array_values(array_filter([
            (string) config('knowledge.chat.model'),
            (string) config('knowledge.chat.fallback_model', ''),
        ]));

        foreach ($models as $model) {
            $response = $this->client()->post(
                "{$baseUrl}/models/{$model}:generateContent?key={$apiKey}",
                ['contents' => $contents, 'generationConfig' => $generationConfig],
            );

            if ($response->successful()) {
                $answer = trim((string) $response->json('candidates.0.content.parts.0.text', ''));

                return $answer !== '' ? $answer : 'Non sono riuscito a formulare una risposta dal contesto disponibile.';
            }

            // 503/429/5xx: il client ha già ritentato; prova il fallback se c'è.
            \Illuminate\Support\Facades\Log::warning('DocumentChat: modello non disponibile', [
                'model' => $model, 'status' => $response->status(),
            ]);
        }

        return null;
    }

    /**
     * Costruisce i `contents` per Gemini: istruzioni + contesto + storico + domanda.
     *
     * @param array<int,array<string,mixed>> $chunks
     * @param array<int,array{role:string,content:string}> $history
     * @return array<int,array<string,mixed>>
     */
    private function buildContents(string $question, array $chunks, array $history): array
    {
        $context = '';
        foreach ($chunks as $c) {
            $context .= "[{$c['n']}] ({$c['title']})\n{$c['snippet']}\n\n";
        }

        $system = <<<PROMPT
        Assistente legale dello studio. Rispondi alla DOMANDA usando SOLO il CONTESTO (estratti dai documenti). Cita le fonti con [n]. Se il contesto non basta, dillo senza inventare. Italiano, conciso.
        CONTESTO:
        {$context}
        PROMPT;

        $contents = [
            ['role' => 'user', 'parts' => [['text' => $system]]],
            ['role' => 'model', 'parts' => [['text' => 'Va bene, rispondo basandomi sul contesto e citando le fonti.']]],
        ];

        // Storico recente per mantenere il filo (limitato per contenere i token).
        $turns = max(0, (int) config('knowledge.chat.history_turns', 4));
        foreach (array_slice($history, -$turns) as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'model' : 'user';
            $text = trim((string) ($turn['content'] ?? ''));
            if ($text !== '') {
                $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
            }
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];

        return $contents;
    }

    private function client(): PendingRequest
    {
        return Http::connectTimeout(15)
            ->timeout(90)
            // Backoff esponenziale (1.5s, 3s, 4.5s) per assorbire i picchi 503 del free tier.
            ->retry(4, fn (int $attempt) => $attempt * 1500, function ($exception) {
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                    return true;
                }
                $status = $exception instanceof \Illuminate\Http\Client\RequestException
                    ? $exception->response->status()
                    : null;

                return in_array($status, [429, 500, 502, 503, 504], true);
            }, throw: false)
            ->acceptJson();
    }
}
