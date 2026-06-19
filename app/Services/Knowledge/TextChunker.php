<?php

namespace App\Services\Knowledge;

/**
 * TextChunker — spezza il testo in chunk con overlap.
 *
 * Strategia: accumula paragrafi finché si resta sotto chunk_size (caratteri);
 * tra chunk consecutivi si ripetono ~chunk_overlap caratteri di coda per non
 * perdere il contesto a cavallo del taglio. Paragrafi più lunghi del limite
 * vengono spezzati duramente.
 */
class TextChunker
{
    public function __construct(
        private int $chunkSize = 1500,
        private int $overlap = 200,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (int) config('knowledge.chunk_size', 1500),
            (int) config('knowledge.chunk_overlap', 200),
        );
    }

    /** @return string[] lista di chunk non vuoti */
    public function chunk(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }

            // Paragrafo singolo più lungo del limite: spezzalo duramente.
            if (mb_strlen($para) > $this->chunkSize) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach ($this->hardSplit($para) as $piece) {
                    $chunks[] = $piece;
                }
                continue;
            }

            $candidate = $current === '' ? $para : $current . "\n\n" . $para;

            if (mb_strlen($candidate) > $this->chunkSize) {
                $chunks[] = $current;
                $current = $this->tail($current) . "\n\n" . $para;
            } else {
                $current = $candidate;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter(array_map('trim', $chunks), fn ($c) => $c !== ''));
    }

    /**
     * Coda di overlap: ~overlap caratteri finali ma allineati a un confine di
     * frase (o almeno di parola), così il chunk successivo non inizia a metà parola.
     */
    private function tail(string $text): string
    {
        if ($this->overlap <= 0 || mb_strlen($text) <= $this->overlap) {
            return $text;
        }

        $tail = mb_substr($text, -$this->overlap);

        // Riparti dopo il primo confine di frase contenuto nella coda...
        if (preg_match('/[.!?;:]\s+(.+)$/us', $tail, $m)) {
            return trim($m[1]);
        }

        // ...o almeno dopo il primo spazio (mai a metà parola).
        if (preg_match('/\S*\s+(.+)$/us', $tail, $m)) {
            return trim($m[1]);
        }

        return $tail;
    }

    /**
     * Spezza un paragrafo troppo lungo rispettando le frasi: accumula frasi fino
     * al limite; una frase più lunga del limite viene divisa per parole (mai a
     * metà parola). @return string[]
     */
    private function hardSplit(string $text): array
    {
        $pieces = [];
        $current = '';

        foreach ($this->sentences($text) as $sentence) {
            if (mb_strlen($sentence) > $this->chunkSize) {
                if ($current !== '') {
                    $pieces[] = $current;
                    $current = '';
                }
                foreach ($this->wordSplit($sentence) as $piece) {
                    $pieces[] = $piece;
                }
                continue;
            }

            $candidate = $current === '' ? $sentence : $current . ' ' . $sentence;

            if (mb_strlen($candidate) > $this->chunkSize) {
                $pieces[] = $current;
                $current = $this->tail($current) . ' ' . $sentence;
            } else {
                $current = $candidate;
            }
        }

        if (trim($current) !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * Divide in frasi: taglia dopo . ! ? seguiti da maiuscola, e dopo ; : —
     * evita di spezzare abbreviazioni/numeri di legge (es. "art. 2043"). @return string[]
     */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+(?=\p{Lu})|(?<=[;:])\s+/u', trim($text)) ?: [$text];

        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }

    /** Ultima risorsa per frasi lunghissime: divide per parole, mai a metà parola. @return string[] */
    private function wordSplit(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [$text];
        $pieces = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if (mb_strlen($candidate) > $this->chunkSize && $current !== '') {
                $pieces[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
