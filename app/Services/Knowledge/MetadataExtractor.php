<?php

namespace App\Services\Knowledge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MetadataExtractor — estrae metadati legali strutturati da un documento.
 *
 * Usa Gemma via Gemini API (:generateContent) con un prompt che impone JSON.
 * L'output è normalizzato in una struttura stabile (vedi normalize()) così la UI e
 * le fasi successive (pratiche simili, confronto) hanno sempre la stessa forma.
 *
 * Endpoint:
 *   POST {base}/models/{model}:generateContent?key=API_KEY
 */
class MetadataExtractor
{
    public function __construct(
        private ?string $apiKey = null,
        private ?string $model = null,
        private ?string $baseUrl = null,
        private ?int $maxChars = null,
        private ?float $temperature = null,
        private ?int $maxOutputTokens = null,
        private ?int $thinkingBudget = null,
    ) {
        $this->apiKey          ??= (string) config('knowledge.gemini.api_key');
        $this->model           ??= (string) config('knowledge.metadata.model');
        $this->baseUrl         ??= rtrim((string) config('knowledge.gemini.base_url'), '/');
        $this->maxChars        ??= (int) config('knowledge.metadata.max_chars', 14000);
        $this->temperature     ??= (float) config('knowledge.metadata.temperature', 0.2);
        $this->maxOutputTokens ??= (int) config('knowledge.metadata.max_output_tokens', 1800);
        $this->thinkingBudget  ??= (int) config('knowledge.metadata.thinking_budget', 0);
    }

    /**
     * Estrae i metadati dal testo del documento.
     *
     * @return array{
     *   document_type:string, summary:string,
     *   parties:array<int,array{name:string,role:string}>,
     *   dates:array<int,array{label:string,date:string}>,
     *   amounts:array<int,array{label:string,amount:float,currency:string}>,
     *   citations:array<int,string>,
     *   clauses:array<int,array{title:string,summary:string}>,
     *   risks:array<int,string>
     * }
     */
    public function extract(string $text): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY non configurata.');
        }

        $text = $this->sampleText($text);
        if ($text === '') {
            return $this->blank();
        }

        $modelPath = 'models/' . $this->model;

        $response = $this->client()->post(
            "{$this->baseUrl}/{$modelPath}:generateContent?key={$this->apiKey}",
            [
                'contents' => [[
                    'parts' => [['text' => $this->prompt($text)]],
                ]],
                // NB: solo responseMimeType, NON responseSchema. Con lo schema vincolato
                // i modelli 2.5 vanno in stallo su testi reali (la richiesta resta appesa,
                // 0 byte in risposta). Il JSON è garantito dal prompt + parser robusto.
                //
                // Costi: thinkingBudget=0 disattiva i token di ragionamento (fatturati
                // come output, la voce più cara) e maxOutputTokens mette un tetto certo.
                'generationConfig' => [
                    'temperature'      => $this->temperature,
                    'responseMimeType' => 'application/json',
                    'maxOutputTokens'  => $this->maxOutputTokens,
                    'thinkingConfig'   => ['thinkingBudget' => $this->thinkingBudget],
                ],
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException(
                "Gemini metadata fallito ({$response->status()}): " . $response->body()
            );
        }

        $raw = (string) $response->json('candidates.0.content.parts.0.text', '');

        return $this->normalize($this->decode($raw));
    }

    /**
     * Usa il budget caratteri su inizio, centro e fine del documento.
     * Nei legali cliente/controparti spesso stanno in intestazione, corpo o firma:
     * solo i primi caratteri dopo l'ottimizzazione tagliavano troppo contesto utile.
     */
    private function sampleText(string $text): string
    {
        $text = trim(preg_replace("/\n{3,}/u", "\n\n", str_replace(["\r\n", "\r"], "\n", $text)) ?? $text);

        if ($text === '' || mb_strlen($text) <= $this->maxChars) {
            return $text;
        }

        $first = (int) floor($this->maxChars * 0.50);
        $middle = (int) floor($this->maxChars * 0.20);
        $last = $this->maxChars - $first - $middle;
        $length = mb_strlen($text);
        $middleStart = max(0, (int) floor(($length - $middle) / 2));

        return trim(implode("\n\n[... parte centrale del documento ...]\n\n", [
            mb_substr($text, 0, $first),
            mb_substr($text, $middleStart, $middle),
        ]) . "\n\n[... parte finale del documento ...]\n\n" . mb_substr($text, -$last));
    }
    /**
     * Prompt in italiano: compatto per minimizzare i token in input. Lo schema è
     * su una riga e le indicazioni per campo non duplicano la struttura.
     */
    private function prompt(string $text): string
    {
        $schema = '{"document_type":"","summary":"","parties":[{"name":"","role":""}],'
            . '"dates":[{"label":"","date":"YYYY-MM-DD"}],'
            . '"amounts":[{"label":"","amount":0,"currency":"EUR"}],'
            . '"citations":[""],"clauses":[{"title":"","summary":""}],"risks":[""]}';

        return <<<PROMPT
        Assistente legale IT. Estrai i metadati dal DOCUMENTO e restituisci SOLO questo JSON (niente testo/markdown attorno; array vuoti se mancano; date YYYY-MM-DD; importi numerici senza separatori, valuta ISO):
        {$schema}
        Regole: document_type ∈ {contratto,atto,diffida,sentenza,decreto,email,procura,visura,fattura,memoria,altro}; summary 1-3 frasi fattuali; parties = tutte le parti nominate con ruolo breve. Se dal testo è deducibile il cliente/assistito/mandante/ricorrente/attore/creditore dello studio usa ruolo "cliente" o "assistito"; per l altra parte usa "controparte", "convenuto", "debitore" ecc. citations = norme citate; risks = criticità per il cliente. Non inventare dati assenti.
        DOCUMENTO:
        {$text}
        PROMPT;
    }

    /** Decodifica robusta: fence, testo attorno e JSON troncato dal limite token. */
    private function decode(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        // Rimuove eventuali fence ```json ... ```
        $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw) ?? $raw;

        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: isola il primo oggetto { ... }.
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Ripara il JSON troncato (output tagliato dal limite di token): chiude
        // stringhe/parentesi rimaste aperte e ritenta. Recupera i metadati parziali.
        $repaired = $this->repairTruncatedJson($raw);
        if ($repaired !== null) {
            return $repaired;
        }

        // Ultima istanza: non far fallire il documento per un parsing andato male.
        // Metadati vuoti = ri-estraibili dal bottone, ma il documento resta usabile.
        return [];
    }

    /**
     * Tenta di rendere valido un JSON troncato: scandisce i caratteri tenendo conto
     * delle stringhe, taglia all'ultima posizione fuori-stringa e richiude le
     * parentesi ancora aperte. Ritorna l'array o null se irrecuperabile.
     */
    private function repairTruncatedJson(string $raw): ?array
    {
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }

        $s = substr($raw, $start);
        $len = strlen($s);
        $stack = [];
        $inString = false;
        $escaped = false;
        $lastSafe = 0;

        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    $inString = false;
                    $lastSafe = $i + 1;
                }
                continue;
            }

            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{' || $c === '[') {
                $stack[] = $c === '{' ? '}' : ']';
                $lastSafe = $i + 1;
            } elseif ($c === '}' || $c === ']') {
                array_pop($stack);
                $lastSafe = $i + 1;
            } elseif (! ctype_space($c)) {
                $lastSafe = $i + 1;
            }
        }

        // Taglia all'ultimo punto sicuro, rimuove separatori penzolanti e richiude.
        $candidate = rtrim(substr($s, 0, $lastSafe));
        $candidate = preg_replace('/[,:]\s*$/', '', $candidate) ?? $candidate;
        $candidate .= implode('', array_reverse($stack));

        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Forza la struttura stabile, scartando campi inattesi. */
    private function normalize(array $d): array
    {
        $str = static fn ($v): string => is_scalar($v) ? trim((string) $v) : '';

        return [
            'document_type' => $str($d['document_type'] ?? ''),
            'summary'       => $str($d['summary'] ?? ''),
            'parties' => array_values(array_map(fn ($p) => [
                'name' => $str($p['name'] ?? ''),
                'role' => $str($p['role'] ?? ''),
            ], $this->arr($d['parties'] ?? []))),
            'dates' => array_values(array_map(fn ($x) => [
                'label' => $str($x['label'] ?? ''),
                'date'  => $str($x['date'] ?? ''),
            ], $this->arr($d['dates'] ?? []))),
            'amounts' => array_values(array_map(fn ($x) => [
                'label'    => $str($x['label'] ?? ''),
                'amount'   => is_numeric($x['amount'] ?? null) ? (float) $x['amount'] : 0.0,
                'currency' => $str($x['currency'] ?? 'EUR') ?: 'EUR',
            ], $this->arr($d['amounts'] ?? []))),
            'citations' => array_values(array_filter(array_map($str, $this->arr($d['citations'] ?? [])))),
            'clauses' => array_values(array_map(fn ($x) => [
                'title'   => $str($x['title'] ?? ''),
                'summary' => $str($x['summary'] ?? ''),
            ], $this->arr($d['clauses'] ?? []))),
            'risks' => array_values(array_filter(array_map($str, $this->arr($d['risks'] ?? [])))),
        ];
    }

    /** @return array<int,mixed> */
    private function arr(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }

    private function blank(): array
    {
        return [
            'document_type' => '', 'summary' => '', 'parties' => [], 'dates' => [],
            'amounts' => [], 'citations' => [], 'clauses' => [], 'risks' => [],
        ];
    }

    private function client(): PendingRequest
    {
        return Http::connectTimeout(15)
            ->timeout(90)
            ->retry(3, 2000, function ($exception) {
                // Timeout/connessione: nessun ->response, ritenta.
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                    return true;
                }
                // Errori HTTP transitori (rate limit / 5xx).
                $status = $exception instanceof \Illuminate\Http\Client\RequestException
                    ? $exception->response->status()
                    : null;
                return in_array($status, [429, 500, 502, 503, 504], true);
            }, throw: false)
            ->acceptJson();
    }
}
