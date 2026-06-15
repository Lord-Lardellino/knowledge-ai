<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Knowledge\EmbeddingProvider;
use App\Services\Knowledge\GeminiEmbedder;
use App\Services\Knowledge\TextChunker;
use App\Services\Knowledge\TextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\PhpWord;
use SaaS\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

class DocumentProcessingTest extends TestCase
{
    use RefreshDatabase;

    private function makeDocument(string $extension, string $path): Document
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);

        return Document::create([
            'tenant_id'         => $tenant->id,
            'title'             => "test.$extension",
            'original_filename' => "test.$extension",
            'disk'              => 'local',
            'path'              => $path,
            'mime_type'         => 'application/octet-stream',
            'extension'         => $extension,
            'size_bytes'        => 1,
            'status'            => Document::STATUS_PENDING,
        ]);
    }

    public function test_estrae_e_chunka_un_txt(): void
    {
        Storage::fake('local');
        $body = str_repeat("Procedura aziendale per le ferie. ", 200);
        Storage::disk('local')->put('documents/1/test.txt', $body);
        $doc = $this->makeDocument('txt', 'documents/1/test.txt');

        $text = app(TextExtractor::class)->extract($doc);
        $chunks = TextChunker::fromConfig()->chunk($text);

        $this->assertNotEmpty($chunks);
        $this->assertStringContainsString('ferie', $chunks[0]);
    }

    public function test_pipeline_invia_tutti_i_chunk_in_un_unico_batch_embedding(): void
    {
        config()->set('knowledge.chunk_size', 120);
        config()->set('knowledge.chunk_overlap', 0);
        config()->set('knowledge.embedding.batch_size', null);

        Storage::fake('local');

        $body = implode("\n\n", array_map(
            fn ($i) => str_repeat("Procedura reparto $i con contenuto operativo. ", 4),
            range(1, 10),
        ));

        Storage::disk('local')->put('documents/1/test.txt', $body);
        $doc = $this->makeDocument('txt', 'documents/1/test.txt');

        $embedder = new class extends GeminiEmbedder {
            public int $calls = 0;

            /** @var int[] */
            public array $batchSizes = [];

            public function __construct()
            {
            }

            public function embedDocuments(array $texts): array
            {
                $this->calls++;
                $this->batchSizes[] = count($texts);

                return array_map(
                    fn () => array_fill(0, 1536, 0.001),
                    $texts,
                );
            }
        };

        (new ProcessDocument($doc->id))->handle(
            app(TextExtractor::class),
            $embedder,
        );

        $doc->refresh();

        $this->assertSame(Document::STATUS_INDEXED, $doc->status);
        $this->assertGreaterThan(1, $doc->chunk_count);
        $this->assertSame(1, $embedder->calls);
        $this->assertSame([$doc->chunk_count], $embedder->batchSizes);
    }

    #[Group('gemini')]
    public function test_pipeline_completa_indicizza_e_genera_embedding(): void
    {
        if (! config('knowledge.gemini.api_key')) {
            $this->markTestSkipped('GEMINI_API_KEY non configurata: test di integrazione saltato.');
        }

        Storage::fake('local');
        Storage::disk('local')->put('documents/1/test.txt', "Le ferie si richiedono dal portale HR con almeno 15 giorni di preavviso.");
        $doc = $this->makeDocument('txt', 'documents/1/test.txt');

        (new ProcessDocument($doc->id))->handle(
            app(TextExtractor::class),
            app(\App\Services\Knowledge\GeminiEmbedder::class),
        );

        $doc->refresh();
        $this->assertSame(Document::STATUS_INDEXED, $doc->status);
        $this->assertGreaterThan(0, $doc->chunk_count);

        $chunk = DocumentChunk::withoutGlobalScopes()->where('document_id', $doc->id)->first();
        $this->assertNotNull($chunk->embedding);
        $this->assertCount(1536, $chunk->embedding);
    }

    #[Group('gemini')]
    public function test_ricerca_semantica_trova_il_documento_giusto(): void
    {
        if (! config('knowledge.gemini.api_key')) {
            $this->markTestSkipped('GEMINI_API_KEY non configurata: test di integrazione saltato.');
        }

        config()->set('knowledge.embedding.provider', 'gemini');
        app()->forgetInstance(EmbeddingProvider::class);

        Storage::fake('local');
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);

        $make = function (string $name, string $text) use ($tenant) {
            Storage::disk('local')->put("documents/{$tenant->id}/{$name}.txt", $text);
            $doc = Document::create([
                'tenant_id' => $tenant->id, 'title' => $name, 'original_filename' => "$name.txt",
                'disk' => 'local', 'path' => "documents/{$tenant->id}/{$name}.txt",
                'mime_type' => 'text/plain', 'extension' => 'txt', 'size_bytes' => 1,
                'status' => Document::STATUS_PENDING,
            ]);
            (new ProcessDocument($doc->id))->handle(
                app(TextExtractor::class),
                app(\App\Services\Knowledge\GeminiEmbedder::class),
            );
            return $doc;
        };

        $ferie = $make('ferie', 'Le ferie e i permessi si richiedono dal portale HR con preavviso.');
        $make('fatture', 'Le fatture ai clienti vanno emesse entro la fine del mese.');

        $results = app(\App\Services\Knowledge\SemanticSearch::class)
            ->search($tenant->id, 'come chiedo i giorni di vacanza?', 1);

        $this->assertNotEmpty($results);
        $this->assertSame($ferie->id, $results[0]['document_id']);
    }

    public function test_estrae_testo_da_docx(): void
    {
        Storage::fake('local');
        $word = new PhpWord();
        $section = $word->addSection();
        $section->addText('Politica rimborsi spese trasferta.');
        $section->addText('I rimborsi vanno richiesti entro 30 giorni.');
        $tmp = tempnam(sys_get_temp_dir(), 'docx') . '.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($tmp);
        Storage::disk('local')->put('documents/1/test.docx', file_get_contents($tmp));
        @unlink($tmp);

        $doc = $this->makeDocument('docx', 'documents/1/test.docx');
        $text = app(TextExtractor::class)->extract($doc);

        $this->assertStringContainsString('rimborsi', $text);
        $this->assertStringContainsString('30 giorni', $text);
    }

    public function test_estrae_testo_da_xlsx(): void
    {
        Storage::fake('local');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Listino');
        $sheet->fromArray([['Prodotto', 'Prezzo'], ['Licenza Pro', '299']]);
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($tmp);
        Storage::disk('local')->put('documents/1/test.xlsx', file_get_contents($tmp));
        @unlink($tmp);

        $doc = $this->makeDocument('xlsx', 'documents/1/test.xlsx');
        $text = app(TextExtractor::class)->extract($doc);

        $this->assertStringContainsString('Listino', $text);
        $this->assertStringContainsString('Licenza Pro', $text);
        $this->assertStringContainsString('299', $text);
    }

    public function test_chunker_rispetta_la_dimensione_con_overlap(): void
    {
        $chunker = new TextChunker(chunkSize: 100, overlap: 20);
        $text = implode("\n\n", array_map(fn ($i) => "Paragrafo numero $i con un po' di testo.", range(1, 30)));

        $chunks = $chunker->chunk($text);

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(120, mb_strlen($c)); // size + tolleranza overlap
        }
    }
}
