<?php

namespace App\Services\Knowledge;

use App\Models\Document;

class DocumentComparer
{
    private const MAX_BLOCKS = 800;
    private const MAX_BLOCK_CHARS = 2000;
    private const CONTEXT = 3;

    public function compare(Document $base, Document $target, string $mode = 'paragraphs'): array
    {
        $baseText = $this->documentText($base);
        $targetText = $this->documentText($target);

        abort_if($baseText === '', 422, 'Il primo documento non ha testo indicizzato.');
        abort_if($targetText === '', 422, 'Il secondo documento non ha testo indicizzato.');

        $baseBlocks = $this->blocks($baseText, $mode);
        $targetBlocks = $this->blocks($targetText, $mode);
        $baseTruncated = count($baseBlocks) > self::MAX_BLOCKS;
        $targetTruncated = count($targetBlocks) > self::MAX_BLOCKS;

        $baseBlocks = array_slice($baseBlocks, 0, self::MAX_BLOCKS);
        $targetBlocks = array_slice($targetBlocks, 0, self::MAX_BLOCKS);

        $rows = $this->diffRows($baseBlocks, $targetBlocks);
        $hunks = $this->hunks($rows);
        $alignment = $this->alignment($rows);

        return [
            'base' => [
                'id' => $base->id,
                'title' => $base->title,
            ],
            'target' => [
                'id' => $target->id,
                'title' => $target->title,
            ],
            'mode' => $mode === 'lines' ? 'lines' : 'paragraphs',
            'truncated' => $baseTruncated || $targetTruncated,
            'stats' => [
                'added' => count(array_filter($rows, fn (array $row) => $row['type'] === 'added')),
                'removed' => count(array_filter($rows, fn (array $row) => $row['type'] === 'removed')),
                'unchanged' => count(array_filter($rows, fn (array $row) => $row['type'] === 'context')),
                'similar' => count(array_filter($alignment['base'], fn (array $b) => $b['kind'] === 'similar')),
            ],
            'hunks' => $hunks,
            'alignment' => $alignment,
        ];
    }

    /**
     * Allineamento passaggio-per-passaggio per la vista "Evidenzia": ogni blocco
     * dei due documenti è classificato identical|similar|unique e i gemelli
     * condividono un `link` per la navigazione collegata.
     *
     * @param array<int,array{type:string,text:string}> $rows
     * @return array{base:array<int,array{text:string,kind:string,link:int|null}>, target:array<int,array{text:string,kind:string,link:int|null}>}
     */
    private function alignment(array $rows): array
    {
        $base = [];
        $target = [];
        $link = 0;

        // Identici dall'LCS; raccolgo gli indici dei blocchi non appaiati.
        $baseOnly = [];
        $targetOnly = [];

        foreach ($rows as $row) {
            if ($row['type'] === 'context') {
                $base[] = ['text' => $row['text'], 'kind' => 'identical', 'link' => $link];
                $target[] = ['text' => $row['text'], 'kind' => 'identical', 'link' => $link];
                $link++;
            } elseif ($row['type'] === 'removed') {
                $baseOnly[] = count($base);
                $base[] = ['text' => $row['text'], 'kind' => 'unique', 'link' => null];
            } else { // added
                $targetOnly[] = count($target);
                $target[] = ['text' => $row['text'], 'kind' => 'unique', 'link' => null];
            }
        }

        // Match "simile" fra i blocchi rimasti: Jaccard su bigrammi di parole.
        // Guardia di costo: salta se il prodotto degli avanzi è troppo grande.
        if ($baseOnly !== [] && $targetOnly !== [] && count($baseOnly) * count($targetOnly) <= 60000) {
            $baseSets = [];
            foreach ($baseOnly as $bi) {
                $baseSets[$bi] = $this->tokenSet($base[$bi]['text']);
            }

            $usedTarget = [];
            foreach ($baseOnly as $bi) {
                $bestTi = null;
                $bestScore = 0.45; // soglia minima per considerare due passaggi "simili"

                foreach ($targetOnly as $ti) {
                    if (isset($usedTarget[$ti])) {
                        continue;
                    }
                    $score = $this->jaccard($baseSets[$bi], $this->tokenSet($target[$ti]['text']));
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestTi = $ti;
                    }
                }

                if ($bestTi !== null) {
                    $usedTarget[$bestTi] = true;
                    $base[$bi]['kind'] = 'similar';
                    $base[$bi]['link'] = $link;
                    $target[$bestTi]['kind'] = 'similar';
                    $target[$bestTi]['link'] = $link;
                    $link++;
                }
            }
        }

        return ['base' => $base, 'target' => $target];
    }

    /** Insieme di parole + bigrammi normalizzati di un blocco (per Jaccard). */
    private function tokenSet(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $set = [];
        $count = count($words);
        for ($i = 0; $i < $count; $i++) {
            $set[$words[$i]] = true;
            if ($i + 1 < $count) {
                $set[$words[$i] . ' ' . $words[$i + 1]] = true;
            }
        }

        return $set;
    }

    /**
     * @param array<string,bool> $a
     * @param array<string,bool> $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($a, $b));
        $union = count($a + $b);

        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function documentText(Document $document): string
    {
        return $document->chunks()
            ->orderBy('chunk_index')
            ->pluck('content')
            ->filter(fn (?string $content) => trim((string) $content) !== '')
            ->implode("\n\n");
    }

    private function blocks(string $text, string $mode): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        if ($mode === 'lines') {
            $parts = preg_split('/\n/u', $text) ?: [];
        } else {
            $parts = preg_split('/\n\s*\n/u', $text) ?: [];

            if (count(array_filter($parts, fn ($part) => trim((string) $part) !== '')) < 8) {
                $parts = preg_split('/(?<=[.!?;:])\s+/u', $text) ?: [];
            }
        }

        return array_values(array_filter(array_map(function ($part) {
            $line = trim((string) $part);
            if ($line === '') {
                return null;
            }

            return mb_strlen($line) > self::MAX_BLOCK_CHARS
                ? mb_substr($line, 0, self::MAX_BLOCK_CHARS) . '...'
                : $line;
        }, $parts)));
    }

    private function diffRows(array $base, array $target): array
    {
        $m = count($base);
        $n = count($target);
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = $m - 1; $i >= 0; $i--) {
            for ($j = $n - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $base[$i] === $target[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $rows = [];
        $i = 0;
        $j = 0;
        $oldLine = 1;
        $newLine = 1;

        while ($i < $m && $j < $n) {
            if ($base[$i] === $target[$j]) {
                $rows[] = ['type' => 'context', 'old_line' => $oldLine++, 'new_line' => $newLine++, 'text' => $base[$i]];
                $i++;
                $j++;
                continue;
            }

            if ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $rows[] = ['type' => 'removed', 'old_line' => $oldLine++, 'new_line' => null, 'text' => $base[$i++]];
            } else {
                $rows[] = ['type' => 'added', 'old_line' => null, 'new_line' => $newLine++, 'text' => $target[$j++]];
            }
        }

        while ($i < $m) {
            $rows[] = ['type' => 'removed', 'old_line' => $oldLine++, 'new_line' => null, 'text' => $base[$i++]];
        }

        while ($j < $n) {
            $rows[] = ['type' => 'added', 'old_line' => null, 'new_line' => $newLine++, 'text' => $target[$j++]];
        }

        return $rows;
    }

    private function hunks(array $rows): array
    {
        $changed = [];
        foreach ($rows as $index => $row) {
            if ($row['type'] !== 'context') {
                $changed[] = $index;
            }
        }

        if ($changed === []) {
            return [[
                'old_start' => 1,
                'new_start' => 1,
                'rows' => array_slice($rows, 0, 20),
            ]];
        }

        $ranges = [];
        foreach ($changed as $index) {
            $start = max(0, $index - self::CONTEXT);
            $end = min(count($rows) - 1, $index + self::CONTEXT);

            if ($ranges !== [] && $start <= $ranges[array_key_last($ranges)][1] + 1) {
                $ranges[array_key_last($ranges)][1] = max($ranges[array_key_last($ranges)][1], $end);
            } else {
                $ranges[] = [$start, $end];
            }
        }

        return array_map(function (array $range) use ($rows) {
            $slice = array_slice($rows, $range[0], $range[1] - $range[0] + 1);
            $firstOld = collect($slice)->first(fn (array $row) => $row['old_line'] !== null)['old_line'] ?? 0;
            $firstNew = collect($slice)->first(fn (array $row) => $row['new_line'] !== null)['new_line'] ?? 0;

            return [
                'old_start' => $firstOld,
                'new_start' => $firstNew,
                'rows' => $slice,
            ];
        }, $ranges);
    }
}