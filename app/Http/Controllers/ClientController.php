<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Models\Client;
use App\Models\Matter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * ClientController — anagrafica clienti dello studio (verticale legale).
 *
 * Query scoped per tenant (HasTenant su Client). Pagine Inertia per l'anagrafica,
 * endpoint JSON (options/store) per i select del dialog pratica.
 */
class ClientController extends Controller
{
    /** Pagina Inertia: elenco clienti con conteggio pratiche. */
    public function page(Request $request): InertiaResponse
    {
        return Inertia::render('Clients/Index', [
            'auth'           => $this->authProp($request),
            'initialClients' => Client::query()
                ->withCount('matters')
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'email', 'phone', 'created_at'])
                ->toArray(),
        ]);
    }

    /** Scheda cliente: dati + pratiche del cliente. */
    public function show(Request $request, Client $client): InertiaResponse
    {
        $client->load([
            'matters' => fn ($q) => $q->with('type:id,label')->latest()->select([
                'id', 'client_id', 'matter_type_id', 'title', 'status', 'value_cents', 'opened_at',
            ]),
        ]);

        return Inertia::render('Clients/Show', [
            'auth'   => $this->authProp($request),
            'client' => $client,
        ]);
    }

    /**
     * Lookup per nome: ritorna il cliente (se esiste) e le sue pratiche attive,
     * per offrire "aggiungi a pratica esistente" in fase di smistamento.
     */
    public function lookup(Request $request): JsonResponse
    {
        $name = trim((string) $request->query('name', ''));
        if ($name === '') {
            return response()->json(['client' => null, 'matters' => []]);
        }

        $client = Client::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first(['id', 'name']);
        if (! $client) {
            return response()->json(['client' => null, 'matters' => []]);
        }

        $matters = $client->matters()
            ->whereIn('status', [Matter::STATUS_OPEN, Matter::STATUS_SUSPENDED])
            ->latest()
            ->get(['id', 'title', 'status']);

        return response()->json(['client' => $client, 'matters' => $matters]);
    }

    /** Lista clienti del tenant corrente (JSON, per i select). */
    public function options(): JsonResponse
    {
        return response()->json(
            Client::query()->orderBy('name')->get(['id', 'name', 'type', 'email', 'phone'])
        );
    }

    /** Crea un nuovo cliente. */
    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = Client::create([
            'tenant_id' => $request->user()->tenant_id,
            ...$request->validated(),
        ]);

        return response()->json($client, 201);
    }

    /** Aggiorna un cliente. */
    public function update(StoreClientRequest $request, Client $client): JsonResponse
    {
        $client->update($request->validated());

        return response()->json($client);
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
