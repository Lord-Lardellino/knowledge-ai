<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Client — cliente dello studio, isolato per tenant.
 */
class Client extends Model
{
    use HasTenant;

    public const TYPE_PERSON  = 'person';
    public const TYPE_COMPANY = 'company';

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'tax_code',
        'vat',
        'email',
        'phone',
        'notes',
    ];

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }
}
