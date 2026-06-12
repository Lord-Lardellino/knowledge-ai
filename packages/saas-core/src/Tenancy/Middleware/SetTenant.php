<?php

namespace SaaS\Core\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use SaaS\Core\Kernel\Contracts\TenantResolverInterface;

/**
 * SetTenant Middleware
 *
 * Questo middleware gira all'inizio di ogni request e ha un solo compito:
 * trovare il tenant e renderlo disponibile al resto dell'applicazione.
 *
 * ORDINE DI ESECUZIONE:
 *   Request HTTP arriva
 *       → SetTenant legge il tenant e lo mette nel container
 *           → TenantScope lo legge e filtra le query
 *               → Controller esegue la logica
 *
 * DOVE SI APPLICA:
 *   Nelle route che richiedono un tenant, nel RouteServiceProvider dell'app:
 *     Route::middleware(['web', 'tenant'])->group(...)
 *     Route::middleware(['api', 'auth:sanctum', 'tenant'])->group(...)
 *
 * COSA SUCCEDE SE IL TENANT NON ESISTE?
 *   Se il resolver trova uno slug ma nel DB non esiste un tenant con quello slug,
 *   rispondiamo 404. Non 401 (non è un problema di autenticazione) né 403 (non
 *   è un problema di permessi) — il tenant semplicemente non esiste.
 */
class SetTenant
{
    public function __construct(
        // Laravel inietta automaticamente l'implementazione concreta
        // registrata nel ServiceProvider (SubdomainResolver o HeaderResolver).
        private readonly TenantResolverInterface $resolver
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Chiede al resolver di trovare il tenant slug nella request corrente
        $slug = $this->resolver->resolve($request);

        // Inizializza sempre le chiavi nel container — anche senza tenant.
        // IMPORTANTE: usiamo bind() con closure e NON instance(null).
        // instance(null) non funziona: Laravel usa isset() internamente che
        // restituisce false per null, quindi il container tenta di risolvere
        // la stringa "current.tenant.id" come nome di classe → ReflectionException.
        // bind() con closure garantisce che app('current.tenant.id') restituisca
        // sempre null senza tentare la risoluzione come classe.
        app()->bind('current.tenant', fn () => null);
        app()->bind('current.tenant.id', fn () => null);

        // Se non c'è slug (es. landing page pubblica) lasciamo passare la request
        // senza impostare nessun tenant. TenantScope non applicherà il filtro.
        if ($slug === null) {
            return $next($request);
        }

        // Cerca il tenant nel database tramite lo slug
        // Usiamo il Model Tenant dell'app, configurabile in saas-core.php
        $tenantModel = config('saas-core.tenancy.model', \SaaS\Core\Tenancy\Models\Tenant::class);
        $tenant = $tenantModel::where('slug', $slug)->first();

        // Tenant non trovato → 404
        if ($tenant === null) {
            abort(404, "Tenant [{$slug}] not found.");
        }

        // Registra il tenant nel container come singleton per questa request.
        // 'current.tenant'    → l'oggetto Tenant completo (per chi ne ha bisogno)
        // 'current.tenant.id' → solo l'ID intero (usato da TenantScope nelle query)
        app()->instance('current.tenant', $tenant);
        app()->instance('current.tenant.id', $tenant->id);

        // Aggiunge il tenant_id automaticamente a tutti i Model creati in questa request.
        // Così non devi mai scrivere $model->tenant_id = $tenant->id manualmente.
        \Illuminate\Database\Eloquent\Model::creating(function ($model) use ($tenant) {
            // Solo se il Model ha la colonna tenant_id e non è già impostata
            $column = config('saas-core.tenancy.column', 'tenant_id');
            if (isset($model->$column) === false && $model->isFillable($column)) {
                $model->$column = $tenant->id;
            }
        });

        return $next($request);
    }
}
