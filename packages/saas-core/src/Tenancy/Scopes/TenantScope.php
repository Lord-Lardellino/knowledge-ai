<?php

namespace SaaS\Core\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * TenantScope
 *
 * Global Scope Eloquent che isola automaticamente i dati per tenant.
 *
 * COSA È UN GLOBAL SCOPE?
 * È un filtro che Eloquent applica automaticamente ad OGNI query su un Model.
 * Non devi scrivere ->where('tenant_id', $id) ogni volta: lo fa lui per te.
 *
 * ESEMPIO SENZA TenantScope:
 *   User::all()  →  SELECT * FROM users
 *                   ⚠️ restituisce utenti di TUTTI i tenant!
 *
 * ESEMPIO CON TenantScope:
 *   User::all()  →  SELECT * FROM users WHERE tenant_id = 42
 *                   ✅ solo gli utenti del tenant corrente
 *
 * COME SI REGISTRA SU UN MODEL:
 *   Il Trait HasTenant (che creeremo dopo) lo aggiunge automaticamente nel boot().
 *   Basta aggiungere `use HasTenant` al Model.
 *
 * COME SI DISABILITA (quando serve):
 *   User::withoutGlobalScope(TenantScope::class)->all()
 *   → utile per comandi artisan, job di sistema, o query cross-tenant degli admin.
 */
class TenantScope implements Scope
{
    /**
     * apply()
     *
     * Laravel chiama questo metodo su ogni query che riguarda un Model
     * che ha questo scope registrato.
     *
     * $builder → il query builder Eloquent (ci aggiungiamo il WHERE)
     * $model   → l'istanza del Model (ci leggiamo il nome della colonna tenant)
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Legge il tenant_id corrente dal container.
        // Viene impostato dal middleware SetTenant all'inizio della request.
        $tenantId = app('current.tenant.id');

        // Se non c'è un tenant attivo (es. route pubblica, comando artisan)
        // non aggiungiamo il filtro — la query gira senza restrizioni.
        if (is_null($tenantId)) {
            return;
        }

        // Aggiunge WHERE tenant_id = ? alla query.
        // getQualifiedTenantColumn() restituisce "users.tenant_id" (con il nome tabella)
        // per evitare ambiguità nelle query con JOIN.
        $builder->where(
            $model->getQualifiedTenantColumn(),
            $tenantId
        );
    }
}
