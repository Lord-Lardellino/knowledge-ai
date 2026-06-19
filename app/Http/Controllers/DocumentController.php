<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ExtractDocumentMetadata;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\Knowledge\DocumentComparer;
use App\Services\Knowledge\DocumentSimilarity;
use App\Services\Knowledge\SemanticSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\Process\ExecutableFinder;

/**
 * DocumentController - gestione documenti della knowledge base.
 *
 * Tutte le query passano dal TenantScope (HasTenant sul model Document):
 * un tenant vede e tocca solo i propri documenti.
 *
 * Risposte JSON: il frontend verra collegato in un secondo momento.
 */
class DocumentController extends Controller
{
    /** Pagina Inertia della knowledge base con la lista iniziale dei documenti. */
    public function page(Request $request): InertiaResponse
    {
        $user   = $request->user();
        $tenant = \SaaS\Core\Tenancy\Models\Tenant::find($user->tenant_id, ['id', 'name']);

        return Inertia::render('Knowledge/Index', [
            'auth' => [
                'user'   => $user,
                'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name] : null,
            ],
            'initialDocuments' => $this->documentList(),
        ]);
    }

    /**
     * Vista "Da smistare": documenti non ancora assegnati a una pratica, con i
     * metadati estratti, per creare pratiche/clienti a partire dai documenti.
     */
    public function triage(Request $request): InertiaResponse
    {
        $documents = Document::query()
            ->whereNull('matter_id')
            ->latest()
            ->get([
                'id', 'title', 'extension', 'status', 'created_at',
                'metadata', 'metadata_status',
            ]);

        // L'AI ha gia estratto i metadati: qui la macchina raggruppa i documenti
        // per cliente rilevato e propone una pratica per gruppo (conferma umana).
        [$proposals, $unassigned] = $this->buildProposals($documents);

        $user   = $request->user();
        $tenant = $user->tenant_id
            ? \SaaS\Core\Tenancy\Models\Tenant::find($user->tenant_id, ['id', 'name'])
            : null;

        return Inertia::render('Documents/Triage', [
            'auth' => [
                'user'   => $user,
                'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name] : null,
            ],
            'proposals'   => $proposals,
            'unassigned'  => $unassigned,
            'matterTypes' => \App\Models\MatterType::query()
                ->orderBy('sort')->orderBy('label')
                ->get(['id', 'key', 'label', 'is_system'])->toArray(),
        ]);
    }

    /**
     * Raggruppa i documenti per cliente rilevato dai metadati.
     *
     * @return array{0: array<int,array>, 1: array<int,array>} [proposte, non assegnati]
     */
    private function buildProposals($documents): array
    {
        // Ruoli che indicano il "nostro" cliente nei metadati estratti.
        $clientRole = '/client|clien|assistit|mandant|ricorrent|attore|appellant|istante|creditor|locat|lavorator|datore/i';

        $groups = [];      // key (cliente lowercase) => proposta
        $unassigned = [];  // documenti senza cliente rilevato

        foreach ($documents as $doc) {
            $parties = data_get($doc->metadata, 'parties', []) ?: [];

            $clientName = null;
            foreach ($parties as $p) {
                $name = trim((string) ($p['name'] ?? ''));
                $role = (string) ($p['role'] ?? '');
                if ($name !== '' && preg_match($clientRole, $role)) {
                    $clientName = $name;
                    break;
                }
            }

            $docArr = [
                'id'              => $doc->id,
                'title'           => $doc->title,
                'extension'       => $doc->extension,
                'metadata'        => $doc->metadata,
                'metadata_status' => $doc->metadata_status,
            ];

            if ($clientName === null) {
                $unassigned[] = $docArr;
                continue;
            }

            $key = mb_strtolower($clientName);
            if (! isset($groups[$key])) {
                $groups[$key] = ['client_name' => $clientName, 'documents' => []];
            }
            $groups[$key]['documents'][] = $docArr;
        }

        return [array_values($groups), $unassigned];
    }

    /** Lista documenti del tenant corrente. */
    public function index(): JsonResponse
    {
        $documents = Document::query()
            ->latest()
            ->paginate(20, [
                'id', 'title', 'original_filename', 'extension', 'mime_type',
                'size_bytes', 'status', 'chunk_count', 'embedded_chunks',
                'metadata_status', 'indexed_at', 'created_at',
            ]);

        return response()->json($documents);
    }

    /** Lista documenti (non paginata) per il render iniziale della pagina Inertia. */
    private function documentList(): array
    {
        return Document::query()
            ->latest()
            ->get([
                'id', 'title', 'original_filename', 'extension', 'mime_type',
                'size_bytes', 'status', 'chunk_count', 'embedded_chunks',
                'metadata_status', 'indexed_at', 'created_at',
            ])
            ->toArray();
    }

    /** Upload di un nuovo documento -> salva file + record pending + avvia pipeline. */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $disk = config('knowledge.disk', 'local');

        $tenantId = $request->user()->tenant_id;
        $checksum = hash_file('sha256', $file->getRealPath());

        // Pratica opzionale: accetta solo una pratica del tenant corrente (TenantScope).
        $matterId = $request->input('matter_id');
        if ($matterId !== null && ! \App\Models\Matter::whereKey($matterId)->exists()) {
            $matterId = null;
        }

        // Path: documents/{tenant_id}/{uuid}.{ext}
        $path = $file->store("documents/{$tenantId}", $disk);

        $document = Document::create([
            'tenant_id'         => $tenantId,
            'matter_id'         => $matterId,
            'uploaded_by'       => $request->user()->id,
            'title'             => $request->input('title') ?: $file->getClientOriginalName(),
            'original_filename' => $file->getClientOriginalName(),
            'disk'              => $disk,
            'path'              => $path,
            'mime_type'         => $file->getMimeType(),
            'extension'         => strtolower($file->getClientOriginalExtension()),
            'size_bytes'        => $file->getSize(),
            'checksum'          => $checksum,
            'status'            => Document::STATUS_PENDING,
        ]);

        // Avvia la pipeline asincrona (parsing -> chunking -> embedding).
        ProcessDocument::dispatch($document->id);

        // Real-time: nuovo documento + badge "Da smistare" aggiornato.
        \App\Support\Realtime::document($document);
        if ($matterId === null) {
            \App\Support\Realtime::triage($tenantId);
        }

        return response()->json($document, 201);
    }

    /** Ricerca semantica sui documenti del tenant corrente. */
    public function search(Request $request, SemanticSearch $search): JsonResponse
    {
        $validated = $request->validate([
            'q'     => ['required', 'string', 'min:2', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $results = $search->search(
            tenantId: $request->user()->tenant_id,
            query: $validated['q'],
            limit: (int) ($validated['limit'] ?? 5),
        );

        return response()->json(['query' => $validated['q'], 'results' => $results]);
    }

    /** Confronta due documenti gia indicizzati e restituisce un diff tipo git. */
    public function compare(Request $request, DocumentComparer $comparer): JsonResponse
    {
        $validated = $request->validate([
            'base_id'   => ['required', 'integer'],
            'target_id' => ['required', 'integer', 'different:base_id'],
            'mode'      => ['nullable', 'string', Rule::in(['paragraphs', 'lines'])],
        ]);

        $base = Document::query()->findOrFail($validated['base_id']);
        $target = Document::query()->findOrFail($validated['target_id']);

        return response()->json($comparer->compare(
            base: $base,
            target: $target,
            mode: (string) ($validated['mode'] ?? 'paragraphs'),
        ));
    }

    /** Trova documenti simili al documento base e li raggruppa per cliente. */
    public function similar(Request $request, Document $document, DocumentSimilarity $similarity): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:80'],
            'mode'  => ['nullable', 'string', Rule::in(['semantic', 'exact', 'client', 'semantic_query'])],
            'q'     => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'base' => [
                'id' => $document->id,
                'title' => $document->title,
            ],
            'mode' => (string) ($validated['mode'] ?? 'semantic'),
            'groups' => $similarity->similarTo(
                document: $document,
                limit: (int) ($validated['limit'] ?? 30),
                mode: (string) ($validated['mode'] ?? 'semantic'),
                query: $validated['q'] ?? null,
            ),
        ]);
    }

    /** Testo estratto indicizzato, usato per visualizzare documenti affiancati. */
    public function textPreview(Request $request, Document $document): JsonResponse
    {
        $maxChars = max(1000, min(60000, (int) $request->integer('max', 30000)));
        $content = '';
        $truncated = false;

        foreach ($document->chunks()->orderBy('chunk_index')->get(['content']) as $chunk) {
            $piece = trim((string) $chunk->content);
            if ($piece === '') {
                continue;
            }

            $next = $content === '' ? $piece : $content . "\n\n" . $piece;
            if (mb_strlen($next) > $maxChars) {
                $remaining = max(0, $maxChars - mb_strlen($content));
                $content .= ($content === '' ? '' : "\n\n") . mb_substr($piece, 0, $remaining);
                $truncated = true;
                break;
            }

            $content = $next;
        }

        return response()->json([
            'id' => $document->id,
            'title' => $document->title,
            'extension' => $document->extension,
            'content' => trim($content),
            'truncated' => $truncated,
        ]);
    }

    /** Viewer embeddabile: PDF/immagini diretti, Office convertito in PDF se possibile. */
    public function viewer(Document $document)
    {
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404, 'File non trovato.');

        $extension = strtolower((string) $document->extension);
        $headers = [
            'Content-Disposition' => 'inline; filename="' . $this->safeFilename($document) . '"',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ];

        if (in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt'], true)) {
            return Storage::disk($document->disk)->response($document->path, $this->safeFilename($document), $headers + [
                'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            ]);
        }

        $pdfPath = $this->viewerPdfPath($document);
        if ($pdfPath !== null && is_file($pdfPath)) {
            return response()->file($pdfPath, $headers + ['Content-Type' => 'application/pdf']);
        }

        abort(422, 'Viewer documento non disponibile: installa LibreOffice sul server per visualizzare DOCX/XLSX affiancati.');
    }

    /** Restituisce il file originale inline per apertura separata. */
    public function preview(Document $document)
    {
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404, 'File non trovato.');

        return Storage::disk($document->disk)->response($document->path, $this->safeFilename($document), [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . $this->safeFilename($document) . '"',
        ]);
    }

    private function viewerPdfPath(Document $document): ?string
    {
        if (! in_array(strtolower((string) $document->extension), ['doc', 'docx', 'xls', 'xlsx', 'csv', 'odt', 'ods', 'rtf'], true)) {
            return null;
        }

        $disk = Storage::disk($document->disk);
        if (! method_exists($disk, 'path')) {
            return null;
        }

        $sourcePath = $disk->path($document->path);
        if (! is_file($sourcePath)) {
            return null;
        }

        $outputDir = storage_path('app/document-viewer/' . $document->tenant_id . '/' . $document->id);
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $sourceBase = pathinfo($sourcePath, PATHINFO_FILENAME);
        $pdfPath = $outputDir . DIRECTORY_SEPARATOR . $sourceBase . '.pdf';
        if (is_file($pdfPath) && filemtime($pdfPath) >= filemtime($sourcePath)) {
            return $pdfPath;
        }

        $bin = $this->libreOfficeBinary();
        if ($bin === null) {
            return null;
        }

        $result = ProcessFacade::timeout(90)->run([
            $bin,
            '--headless',
            '--nologo',
            '--nofirststartwizard',
            '--norestore',
            '--nolockcheck',
            '--nodefault',
            '--convert-to',
            'pdf',
            '--outdir',
            $outputDir,
            $sourcePath,
        ]);

        return $result->successful() && is_file($pdfPath) ? $pdfPath : null;
    }

    private function libreOfficeBinary(): ?string
    {
        // Binario configurato esplicitamente: percorso assoluto (verifica esistenza)
        // oppure nome da risolvere nel PATH.
        $configured = trim((string) config('knowledge.viewer.libreoffice_bin', ''));
        if ($configured !== '') {
            if (str_contains($configured, DIRECTORY_SEPARATOR) || str_contains($configured, ':')) {
                return is_file($configured) ? $configured : null;
            }

            return (new ExecutableFinder())->find($configured);
        }

        // Nomi nudi: risolti SOLO se realmente presenti nel PATH (altrimenti su
        // Windows ritornavamo 'soffice' inesistente e la conversione falliva).
        $finder = new ExecutableFinder();
        foreach (['soffice', 'libreoffice'] as $name) {
            if (($found = $finder->find($name)) !== null) {
                return $found;
            }
        }

        // Percorsi d'installazione tipici (LibreOffice spesso non e' nel PATH).
        foreach ([
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/soffice',
            '/usr/bin/libreoffice',
            '/opt/libreoffice/program/soffice',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function safeFilename(Document $document): string
    {
        return str_replace(['"', "\r", "\n"], '', $document->original_filename ?: $document->title);
    }

    /** Dettaglio di un documento. */
    public function show(Document $document): JsonResponse
    {
        return response()->json($document);
    }

    /**
     * Salva i metadati legali rivisti dall'avvocato e li marca come confermati.
     * Conferma = fiducia: da qui il job di estrazione non li sovrascrive piu.
     */
    public function updateMetadata(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'metadata'                 => ['required', 'array'],
            'metadata.document_type'   => ['nullable', 'string', 'max:100'],
            'metadata.summary'         => ['nullable', 'string', 'max:5000'],
            'metadata.parties'         => ['nullable', 'array'],
            'metadata.dates'           => ['nullable', 'array'],
            'metadata.amounts'         => ['nullable', 'array'],
            'metadata.citations'       => ['nullable', 'array'],
            'metadata.clauses'         => ['nullable', 'array'],
            'metadata.risks'           => ['nullable', 'array'],
        ]);

        $document->update([
            'metadata'             => $validated['metadata'],
            'metadata_status'      => Document::META_CONFIRMED,
            'metadata_error'       => null,
            'metadata_reviewed_at' => now(),
        ]);

        return response()->json($document->only([
            'id', 'metadata', 'metadata_status', 'metadata_reviewed_at',
        ]));
    }

    /** Rilancia l'estrazione metadati (es. dopo un fallimento o per riprovare). */
    public function reextractMetadata(Document $document): JsonResponse
    {
        $document->update([
            'metadata_status' => Document::META_PENDING,
            'metadata_error'  => null,
        ]);

        ExtractDocumentMetadata::dispatch($document->id);

        return response()->json(['metadata_status' => Document::META_PENDING]);
    }

    /** Elimina documento: record, chunk (cascade) e file su disco. */
    public function destroy(Document $document): JsonResponse
    {
        Storage::disk($document->disk)->delete($document->path);

        // I document_chunks vengono eliminati in cascade dal vincolo FK.
        $document->delete();

        return response()->json(status: 204);
    }
}

