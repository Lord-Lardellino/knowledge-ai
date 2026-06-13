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

    /** Coda di overlap dall'ultimo chunk. */
    private function tail(string $text): string
    {
        if ($this->overlap <= 0 || mb_strlen($text) <= $this->overlap) {
            return $text;
        }

        return mb_substr($text, -$this->overlap);
    }

    /** @return string[] */
    private function hardSplit(string $text): array
    {
        $pieces = [];
        $step = max(1, $this->chunkSize - $this->overlap);

        for ($i = 0; $i < mb_strlen($text); $i += $step) {
            $pieces[] = mb_substr($text, $i, $this->chunkSize);
        }

        return $pieces;
    }
}
