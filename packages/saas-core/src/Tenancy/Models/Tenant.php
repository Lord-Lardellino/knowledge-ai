<?php

namespace SaaS\Core\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use SaaS\Core\Audit\Traits\Auditable;

/**
 * Tenant Model
 *
 * Rappresenta un tenant (cliente) nel sistema multi-tenant.
 *
 * COSA È UN TENANT?
 *   In un SaaS multi-tenant ogni cliente ha i propri dati isolati.
 *   Il Tenant è il "contenitore" che raggruppa tutti i dati di un cliente.
 *   Esempio: "Acme Corp" è un tenant — ha i suoi utenti, fatture, impostazioni.
 *
 * SLUG:
 *   Lo slug è l'identificatore URL-friendly del tenant.
 *   Viene usato dal SubdomainResolver: acme.tuosaas.com → slug "acme"
 *   e dall'HeaderResolver: X-Tenant-ID: acme
 *
 * RELAZIONE CON USER:
 *   Un tenant ha molti utenti (hasMany).
 *   Un utente appartiene a un solo tenant (belongsTo tramite tenant_id).
 */
class Tenant extends Model
{
    use Auditable;

    protected $fillable = [
        'name',   // nome display: "Acme Corporation"
        'slug',   // identificatore URL: "acme"
        'plan',   // piano abbonamento: "free", "pro", "enterprise"
        'active', // se false, tutte le request del tenant vengono bloccate
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Scope: solo tenant attivi.
     * Un tenant disattivato (es. abbonamento scaduto) non può accedere.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Restituisce il nome colonna qualificato per il TenantScope.
     * "users.tenant_id" invece di solo "tenant_id" — evita ambiguità nei JOIN.
     */
    public function getQualifiedTenantColumn(): string
    {
        $column = config('saas-core.tenancy.column', 'tenant_id');
        return $this->getTable() . '.' . $column;
    }
}
