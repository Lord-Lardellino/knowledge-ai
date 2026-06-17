<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ExtractDocumentMetadata;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\Knowledge\SemanticSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * DocumentController — gestione documenti della knowledge base.
 *
 * Tutte le query passano dal TenantScope (HasTenant sul model Document):
 * un tenant vede e tocca solo i propri documenti.
 *
 * Risposte JSON: il frontend verrà collegato in un secondo momento.
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

        // L'AI ha già estratto i metadati: qui la macchina raggruppa i documenti
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
        $clientRole = '/client|clien|assistit|ricorrent|attore/i';

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
                'size_bytes', 'status', 'chunk_count', 'indexed_at', 'created_at',
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
                'size_bytes', 'status', 'chunk_count', 'indexed_at', 'created_at',
            ])
            ->toArray();
    }

    /** Upload di un nuovo documento → salva file + record pending + avvia pipeline. */
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

        // Avvia la pipeline asincrona (parsing → chunking → embedding).
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

    /** Dettaglio di un documento. */
    public function show(Document $document): JsonResponse
    {
        return response()->json($document);
    }

    /**
     * Salva i metadati legali rivisti dall'avvocato e li marca come confermati.
     * Conferma = fiducia: da qui il job di estrazione non li sovrascrive più.
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
