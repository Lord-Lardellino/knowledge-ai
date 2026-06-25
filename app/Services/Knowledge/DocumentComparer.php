<?php

namespace App\Services\Knowledge;

use App\Models\Document;
use Illuminate\Support\Facades\DB;

class DocumentComparer
{
    // Soglie di affinità semantica fra chunk (coseno) per la vista "Evidenzia".
    private const SIM_IDENTICAL = 0.93; // praticamente lo stesso passaggio
    private const SIM_SIMILAR   = 0.78; // stesso argomento, parole diverse

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
        // Evidenzia: allineamento SEMANTICO (per argomento) sugli embedding dei chunk.
        // Le parole identiche restano nella vista Git diff (rows/hunks).
        $alignment = $this->semanticAlignment($base, $target);

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
     * Allineamento SEMANTICO per la vista "Evidenzia": per ogni chunk del documento
     * base trova il chunk più affine del target tramite il coseno degli embedding
     * (già salvati: nessuna chiamata AI). Classifica identical|similar|unique e
     * collega i gemelli, etichettando l'argomento condiviso. Le parole identiche
     * restano alla vista Git diff.
     *
     * @return array{base:array<int,array>, target:array<int,array>}
     */
    private function semanticAlignment(Document $base, Document $target): array
    {
        $baseChunks = $this->chunksWithBestMatch($base->id, $target->id);

        $targetChunks = DB::table('document_chunks')
            ->where('document_id', $target->id)
            ->orderBy('chunk_index')
            ->pluck('content', 'chunk_index');

        // Abbinamento 1-a-1: ogni passaggio del target è usato una sola volta.
        // Si assegnano prima le coppie più affini (greedy su similarità decrescente),
        // così i conteggi restano simmetrici e ogni giallo ha un gemello esclusivo.
        $candidates = [];
        foreach ($baseChunks as $i => $row) {
            $sim = $row->sim !== null ? (float) $row->sim : null;
            if ($sim !== null && $sim >= self::SIM_SIMILAR) {
                $candidates[] = ['i' => $i, 't' => (int) $row->t_idx, 'sim' => $sim];
            }
        }
        usort($candidates, static fn ($a, $b) => $b['sim'] <=> $a['sim']);

        $pairOf = [];      // indice base => ['t','kind']
        $usedTarget = [];  // chunk_index target => indice base
        foreach ($candidates as $c) {
            if (isset($pairOf[$c['i']]) || isset($usedTarget[$c['t']])) {
                continue; // base o target già abbinati
            }
            $pairOf[$c['i']] = ['t' => $c['t'], 'kind' => $c['sim'] >= self::SIM_IDENTICAL ? 'identical' : 'similar'];
            $usedTarget[$c['t']] = $c['i'];
        }

        $baseOut = [];
        $targetMeta = []; // chunk_index target => ['kind','topic','from' => chiave base]
        foreach ($baseChunks as $i => $row) {
            $key = 'b' . $i;
            $pair = $pairOf[$i] ?? null;
            $kind = $pair['kind'] ?? 'unique';
            $to = $pair ? 't' . $pair['t'] : null;
            $topic = $pair ? $this->topicLabel((string) $row->b_content, (string) $row->t_content) : null;

            if ($pair) {
                $targetMeta[$pair['t']] = ['kind' => $kind, 'topic' => $topic, 'from' => $key];
            }

            $baseOut[] = ['text' => (string) $row->b_content, 'kind' => $kind, 'key' => $key, 'to' => $to, 'topic' => $topic];
        }

        $targetOut = [];
        foreach ($targetChunks as $idx => $content) {
            $m = $targetMeta[$idx] ?? null;
            $targetOut[] = [
                'text'  => (string) $content,
                'kind'  => $m['kind'] ?? 'unique',
                'key'   => 't' . $idx,
                'to'    => $m['from'] ?? null,
                'topic' => $m['topic'] ?? null,
            ];
        }

        return ['base' => $baseOut, 'target' => $targetOut];
    }

    /**
     * Per ogni chunk del base, il chunk più vicino del target (coseno pgvector).
     *
     * @return array<int,object>
     */
    private function chunksWithBestMatch(int $baseId, int $targetId): array
    {
        return DB::select(
            <<<SQL
            SELECT b.chunk_index AS b_idx, b.content AS b_content,
                   t.t_idx, t.t_content, t.sim
            FROM document_chunks b
            LEFT JOIN LATERAL (
                SELECT tc.chunk_index AS t_idx, tc.content AS t_content,
                       1 - (tc.embedding <=> b.embedding) AS sim
                FROM document_chunks tc
                WHERE tc.document_id = ? AND tc.embedding IS NOT NULL
                ORDER BY tc.embedding <=> b.embedding
                LIMIT 1
            ) t ON true
            WHERE b.document_id = ? AND b.embedding IS NOT NULL
            ORDER BY b.chunk_index
            SQL,
            [$targetId, $baseId]
        );
    }

    /** Etichetta dell'argomento condiviso: parole significative comuni ai due passaggi. */
    private function topicLabel(string $a, string $b): ?string
    {
        $wa = $this->significantWords($a);
        $wb = $this->significantWords($b);
        $common = array_intersect_key($wa, $wb);

        if ($common === []) {
            return null;
        }

        // Ordina per frequenza combinata, poi per lunghezza (parole più specifiche).
        uksort($common, function (string $x, string $y) use ($wa, $wb): int {
            return ($wb[$y] + $wa[$y]) <=> ($wb[$x] + $wa[$x])
                ?: mb_strlen($y) <=> mb_strlen($x);
        });

        $top = array_slice(array_keys($common), 0, 3);

        return ucfirst(implode(', ', $top));
    }

    /**
     * Parole "di contenuto" (>=4 lettere, non stopword né termine giuridico
     * generico) con frequenza, per dedurre l'argomento.
     *
     * @return array<string,int>
     */
    private function significantWords(string $text): array
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $freq = [];

        foreach ($words as $w) {
            if (mb_strlen($w) < 4 || isset(self::STOPWORDS[$w])) {
                continue;
            }
            $freq[$w] = ($freq[$w] ?? 0) + 1;
        }

        return $freq;
    }

    /** Stopword italiane + termini giuridici troppo generici per fare "argomento". */
    private const STOPWORDS = [
        'della' => true, 'delle' => true, 'degli' => true, 'dello' => true, 'dell' => true,
        'sono' => true, 'come' => true, 'anche' => true, 'questo' => true, 'questa' => true,
        'quello' => true, 'quella' => true, 'essere' => true, 'stato' => true, 'stata' => true,
        'nella' => true, 'nelle' => true, 'negli' => true, 'nello' => true, 'dopo' => true,
        'prima' => true, 'secondo' => true, 'ogni' => true, 'tutti' => true, 'tutto' => true,
        'tutte' => true, 'quale' => true, 'quali' => true, 'sulla' => true, 'sullo' => true,
        'sugli' => true, 'sulle' => true, 'dalla' => true, 'dalle' => true, 'dagli' => true,
        'fatto' => true, 'parte' => true, 'parti' => true, 'altri' => true, 'altre' => true,
        'mentre' => true, 'perche' => true, 'quindi' => true, 'inoltre' => true, 'ossia' => true,
        // generici giuridici
        'corte' => true, 'cassazione' => true, 'sentenza' => true, 'articolo' => true,
        'comma' => true, 'legge' => true, 'ricorso' => true, 'giudice' => true, 'tribunale' => true,
        'sezione' => true, 'numero' => true, 'pubblica' => true, 'italiano' => true, 'popolo' => true,
        // firma digitale / intestazioni PDF (rumore, non sono "argomenti")
        'trustpro' => true, 'qualified' => true, 'serial' => true, 'firmato' => true,
        'emesso' => true, 'data' => true, 'pubblicazione' => true, 'registro' => true,
        'sezionale' => true, 'raccolta' => true, 'generale' => true, 'composta' => true,
        'magistrati' => true, 'pronunciato' => true, 'seguente' => true,
    ];

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