<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Matter — la PRATICA legale, oggetto centrale del verticale. Isolata per tenant.
 *
 * Stati: open → suspended → closed → archived.
 */
class Matter extends Model
{
    use HasTenant;

    public const STATUS_OPEN      = 'open';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CLOSED    = 'closed';
    public const STATUS_ARCHIVED  = 'archived';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'matter_type_id',
        'created_by',
        'reference',
        'title',
        'status',
        'outcome',
        'value_cents',
        'opened_at',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'value_cents' => 'integer',
        'opened_at'   => 'date',
        'closed_at'   => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(MatterType::class, 'matter_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parties(): BelongsToMany
    {
        return $this->belongsToMany(Party::class, 'matter_party')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
