<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use SaaS\Core\Tenancy\Scopes\TenantScope;

/**
 * HasTenant
 *
 * Aggancia il TenantScope di saas-core a un Model, così ogni query in LETTURA
 * filtra automaticamente per il tenant corrente (WHERE tenant_id = current.tenant.id).
 * Impossibile dimenticare il filtro → niente documenti di un'azienda visibili a un'altra.
 *
 * NB: la SCRITTURA (popolamento di tenant_id alla creazione) è già gestita
 * globalmente dal middleware SetTenant di saas-core — qui non la dupliquiamo.
 *
 * USO:
 *   class Document extends Model { use HasTenant; }
 *
 * Disabilitare il filtro (job di sistema, comandi cross-tenant):
 *   Document::withoutGlobalScope(TenantScope::class)->get()
 */
trait HasTenant
{
    public static function bootHasTenant(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    /** Nome della colonna tenant su questo model. */
    public function getTenantColumn(): string
    {
        return 'tenant_id';
    }

    /** Nome colonna qualificato (con tabella) — usato dal TenantScope nei JOIN. */
    public function getQualifiedTenantColumn(): string
    {
        return $this->getTable() . '.' . $this->getTenantColumn();
    }
}
