<?php

namespace App\Services\Knowledge;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * TextExtractor — estrae testo grezzo da un documento della knowledge base.
 *
 * Formati: PDF (smalot/pdfparser), DOCX (phpword), XLSX (phpspreadsheet), TXT.
 * Lavora su un file temporaneo locale (i dischi remoti non danno un path reale).
 */
class TextExtractor
{
    public function extract(Document $document): string
    {
        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->path)) {
            throw new RuntimeException("File non trovato: {$document->path}");
        }

        // Copia in un file temporaneo locale: le librerie vogliono un path reale.
        $tmp = tempnam(sys_get_temp_dir(), 'kb_');
        file_put_contents($tmp, $disk->get($document->path));

        try {
            $text = match ($document->extension) {
                'pdf'  => $this->fromPdf($tmp),
                'docx' => $this->fromDocx($tmp),
                'xlsx' => $this->fromXlsx($tmp),
                'txt'  => (string) file_get_contents($tmp),
                default => throw new RuntimeException("Formato non supportato: {$document->extension}"),
            };
        } finally {
            @unlink($tmp);
        }

        return $this->normalize($text);
    }

    private function fromPdf(string $path): string
    {
        return (new PdfParser())->parseFile($path)->getText();
    }

    private function fromDocx(string $path): string
    {
        $phpWord = WordIOFactory::load($path);
        $lines = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $lines[] = $this->wordElementText($element);
            }
        }

        return implode("\n", array_filter($lines));
    }

    /** Estrae ricorsivamente il testo da un elemento PhpWord. */
    private function wordElementText(object $element): string
    {
        if (method_exists($element, 'getText')) {
            $text = $element->getText();
            return is_string($text) ? $text : '';
        }

        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $parts[] = $this->wordElementText($child);
            }
            return implode('', $parts);
        }

        return '';
    }

    private function fromXlsx(string $path): string
    {
        $reader = SpreadsheetIOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $lines = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $lines[] = "# {$sheet->getTitle()}";
            foreach ($sheet->toArray(null, true, false, false) as $row) {
                $cells = array_filter(array_map(fn ($c) => trim((string) $c), $row), fn ($c) => $c !== '');
                if ($cells) {
                    $lines[] = implode(' | ', $cells);
                }
            }
        }

        return implode("\n", $lines);
    }

    /** Normalizza spazi e righe vuote multiple. */
    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
