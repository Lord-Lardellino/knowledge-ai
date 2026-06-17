<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Party — controparte, riusabile tra più pratiche, isolata per tenant.
 */
class Party extends Model
{
    use HasTenant;

    public const TYPE_PERSON  = 'person';
    public const TYPE_COMPANY = 'company';

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'notes',
    ];

    public function matters(): BelongsToMany
    {
        return $this->belongsToMany(Matter::class, 'matter_party')
            ->withPivot('role')
            ->withTimestamps();
    }
}
