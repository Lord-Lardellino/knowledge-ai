<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MatterType — materia/area di una pratica (lavoro, civile, penale, ...).
 *
 * Visibilità: le materie di SISTEMA (tenant_id NULL, is_system=true) sono condivise
 * da tutti gli studi; ogni studio vede inoltre le proprie materie custom.
 *
 * Non usa HasTenant: il TenantScope filtrerebbe via le righe di sistema (tenant_id NULL).
 * Applichiamo invece uno scope "sistema OR tenant corrente".
 */
class MatterType extends Model
{
    protected $fillable = [
        'tenant_id',
        'key',
        'label',
        'is_system',
        'sort',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'sort'      => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('systemOrTenant', function (Builder $builder) {
            $tenantId = app('current.tenant.id');

            if (is_null($tenantId)) {
                return; // contesto senza tenant (comandi, job): nessun filtro
            }

            $builder->where(function (Builder $q) use ($tenantId) {
                $q->whereNull('matter_types.tenant_id')
                    ->orWhere('matter_types.tenant_id', $tenantId);
            });
        });
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }
}
