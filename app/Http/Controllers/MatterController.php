<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMatterRequest;
use App\Models\Client;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * MatterController — pratiche legali (oggetto centrale del verticale).
 *
 * Tutte le query passano dal TenantScope (HasTenant su Matter/Client/Party):
 * un tenant vede e tocca solo le proprie pratiche. Le materie (MatterType) sono
 * di sistema o del tenant (scope dedicato sul model).
 */
class MatterController extends Controller
{
    /** Pagina Inertia: elenco pratiche + dati per i filtri. */
    public function page(Request $request): InertiaResponse
    {
        return Inertia::render('Matters/Index', [
            'auth'           => $this->authProp($request),
            'initialMatters' => $this->matterList(),
            'matterTypes'    => $this->matterTypeList(),
        ]);
    }

    /** Scheda pratica: dettaglio + controparti + documenti collegati. */
    public function show(Request $request, Matter $matter): InertiaResponse
    {
        $matter->load([
            'client:id,name,type,email,phone',
            'type:id,key,label',
            'parties:id,name,type',
            'documents' => fn ($q) => $q->latest()->select([
                'id', 'matter_id', 'title', 'extension', 'status', 'chunk_count',
                'size_bytes', 'indexed_at', 'created_at',
                'metadata', 'metadata_status', 'metadata_reviewed_at',
            ]),
        ]);

        return Inertia::render('Matters/Show', [
            'auth'        => $this->authProp($request),
            'matter'      => $matter,
            'matterTypes' => $this->matterTypeList(),
        ]);
    }

    /** Crea una nuova pratica. */
    public function store(StoreMatterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->assertClientBelongsToTenant($data['client_id']);
        $this->assertMatterTypeAllowed($data['matter_type_id'] ?? null);

        $matter = Matter::create([
            'tenant_id'  => $request->user()->tenant_id,
            'created_by' => $request->user()->id,
            ...collect($data)->except('parties')->all(),
        ]);

        $this->syncParties($matter, $data['parties'] ?? []);

        return response()->json($matter->fresh(), 201);
    }

    /** Aggiorna una pratica esistente. */
    public function update(StoreMatterRequest $request, Matter $matter): JsonResponse
    {
        $data = $request->validated();

        $this->assertClientBelongsToTenant($data['client_id']);
        $this->assertMatterTypeAllowed($data['matter_type_id'] ?? null);

        $matter->update(collect($data)->except('parties')->all());

        $this->syncParties($matter, $data['parties'] ?? []);

        return response()->json($matter->fresh());
    }

    /** Aggancia documenti sciolti a una pratica esistente (smistamento). */
    public function attachDocuments(Request $request, Matter $matter): JsonResponse
    {
        $data = $request->validate([
            'document_ids'   => ['required', 'array', 'min:1'],
            'document_ids.*' => ['integer'],
        ]);

        Document::whereIn('id', $data['document_ids'])
            ->whereNull('matter_id')
            ->update(['matter_id' => $matter->id]);

        \App\Support\Realtime::triage((int) $request->user()->tenant_id);

        return response()->json(['id' => $matter->id]);
    }

    /**
     * Crea una pratica a partire da documenti sciolti (vista "Da smistare").
     * Crea/usa il cliente, crea la pratica, collega controparti e i documenti.
     */
    public function storeFromDocuments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_ids'     => ['required', 'array', 'min:1'],
            'document_ids.*'   => ['integer'],
            'title'            => ['required', 'string', 'max:255'],
            'matter_type_id'   => ['nullable', 'integer'],
            'status'           => ['nullable', Rule::in([
                Matter::STATUS_OPEN, Matter::STATUS_SUSPENDED,
                Matter::STATUS_CLOSED, Matter::STATUS_ARCHIVED,
            ])],
            'client_id'        => ['nullable', 'integer'],
            'client_name'      => ['required_without:client_id', 'nullable', 'string', 'max:255'],
            'client_type'      => ['nullable', Rule::in([Client::TYPE_PERSON, Client::TYPE_COMPANY])],
            'parties'              => ['nullable', 'array'],
            'parties.*.name'       => ['required_with:parties', 'string', 'max:255'],
            'parties.*.role'       => ['nullable', 'string', 'max:30'],
        ]);

        $tenantId = $request->user()->tenant_id;
        $this->assertMatterTypeAllowed($data['matter_type_id'] ?? null);

        // 1. Cliente: esistente (validato per tenant) o find-or-create per nome.
        if (! empty($data['client_id'])) {
            abort_unless(Client::whereKey($data['client_id'])->exists(), 422, 'Cliente non valido.');
            $clientId = (int) $data['client_id'];
        } else {
            $clientId = Client::firstOrCreate(
                ['name' => $data['client_name']],
                ['tenant_id' => $tenantId, 'type' => $data['client_type'] ?? Client::TYPE_COMPANY],
            )->id;
        }

        // 2. Pratica.
        $matter = Matter::create([
            'tenant_id'      => $tenantId,
            'created_by'     => $request->user()->id,
            'client_id'      => $clientId,
            'matter_type_id' => $data['matter_type_id'] ?? null,
            'title'          => $data['title'],
            'status'         => $data['status'] ?? Matter::STATUS_OPEN,
        ]);

        // 3. Controparti (find-or-create per nome) collegate alla pratica.
        $sync = [];
        foreach ($data['parties'] ?? [] as $p) {
            $party = Party::firstOrCreate(
                ['name' => $p['name']],
                ['tenant_id' => $tenantId, 'type' => Party::TYPE_COMPANY],
            );
            $sync[$party->id] = ['role' => $p['role'] ?? null, 'tenant_id' => $tenantId];
        }
        if ($sync !== []) {
            $matter->parties()->sync($sync);
        }

        // 4. Aggancia i documenti (solo quelli del tenant ancora non assegnati).
        Document::whereIn('id', $data['document_ids'])
            ->whereNull('matter_id')
            ->update(['matter_id' => $matter->id]);

        \App\Support\Realtime::triage($tenantId);

        return response()->json(['id' => $matter->id], 201);
    }

    /**
     * Applica un suggerimento estratto dai metadati di un documento: crea (se serve)
     * un Cliente o una Controparte e la collega alla pratica. Conferma esplicita
     * dal frontend (auto-suggerimento con conferma): qui solo l'esecuzione.
     */
    public function applySuggestion(Request $request, Matter $matter): JsonResponse
    {
        $data = $request->validate([
            'as'   => ['required', 'in:client,party'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'in:person,company'],
        ]);

        $tenantId = $request->user()->tenant_id;
        $type     = $data['type'] ?? Client::TYPE_COMPANY;

        if ($data['as'] === 'client') {
            // Find-or-create per nome (scoped al tenant) e imposta come cliente della pratica.
            $client = Client::firstOrCreate(
                ['name' => $data['name']],
                ['tenant_id' => $tenantId, 'type' => $type],
            );
            $matter->update(['client_id' => $client->id]);

            return response()->json(['as' => 'client', 'client' => $client], 201);
        }

        $party = Party::firstOrCreate(
            ['name' => $data['name']],
            ['tenant_id' => $tenantId, 'type' => $type],
        );

        $matter->parties()->syncWithoutDetaching([
            $party->id => ['role' => $data['role'] ?? null, 'tenant_id' => $tenantId],
        ]);

        return response()->json(['as' => 'party', 'party' => $party], 201);
    }

    /** Elimina una pratica (i documenti restano, scollegati via nullOnDelete). */
    public function destroy(Matter $matter): JsonResponse
    {
        $matter->delete();

        return response()->json(status: 204);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function matterList(): array
    {
        return Matter::query()
            ->with(['client:id,name', 'type:id,label'])
            ->withCount('documents')
            ->latest()
            ->get([
                'id', 'client_id', 'matter_type_id', 'reference', 'title',
                'status', 'value_cents', 'opened_at', 'created_at',
            ])
            ->toArray();
    }

    private function matterTypeList(): array
    {
        return MatterType::query()
            ->orderBy('sort')
            ->orderBy('label')
            ->get(['id', 'key', 'label', 'is_system'])
            ->toArray();
    }

    /** Aggancia/riallinea le controparti, validandone l'appartenenza al tenant. */
    private function syncParties(Matter $matter, array $parties): void
    {
        if ($parties === []) {
            $matter->parties()->detach();
            return;
        }

        $partyIds = array_column($parties, 'party_id');

        // Solo controparti del tenant (TenantScope su Party): scarta ID estranei.
        $valid = Party::whereIn('id', $partyIds)->pluck('id')->all();

        $sync = [];
        foreach ($parties as $p) {
            if (in_array($p['party_id'], $valid, true)) {
                $sync[$p['party_id']] = [
                    'role'      => $p['role'] ?? null,
                    'tenant_id' => $matter->tenant_id,
                ];
            }
        }

        $matter->parties()->sync($sync);
    }

    private function assertClientBelongsToTenant(int $clientId): void
    {
        abort_unless(Client::whereKey($clientId)->exists(), 422, 'Cliente non valido.');
    }

    private function assertMatterTypeAllowed(?int $typeId): void
    {
        if ($typeId === null) {
            return;
        }

        abort_unless(MatterType::whereKey($typeId)->exists(), 422, 'Materia non valida.');
    }

    private function authProp(Request $request): array
    {
        $user   = $request->user();
        $tenant = $user->tenant_id
            ? \SaaS\Core\Tenancy\Models\Tenant::find($user->tenant_id, ['id', 'name'])
            : null;

        return [
            'user'   => $user,
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name] : null,
        ];
    }
}
