<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
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

        // Path: documents/{tenant_id}/{uuid}.{ext}
        $path = $file->store("documents/{$tenantId}", $disk);

        $document = Document::create([
            'tenant_id'         => $tenantId,
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

    /** Elimina documento: record, chunk (cascade) e file su disco. */
    public function destroy(Document $document): JsonResponse
    {
        Storage::disk($document->disk)->delete($document->path);

        // I document_chunks vengono eliminati in cascade dal vincolo FK.
        $document->delete();

        return response()->json(status: 204);
    }
}
