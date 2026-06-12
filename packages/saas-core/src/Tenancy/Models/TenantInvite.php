<?php

namespace SaaS\Core\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantInvite Model
 *
 * Invito a unirsi a un tenant esistente.
 *
 * Il token nel DB è l'hash SHA-256 del token spedito via email:
 * confronto con hash('sha256', $tokenRicevuto) — mai salvare il token in chiaro.
 */
class TenantInvite extends Model
{
    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'token',       // SHA-256 — vedi TenantOnboarding::invite()
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Invito ancora valido: non accettato e non scaduto. */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** Scope: solo inviti pendenti (non accettati, non scaduti). */
    public function scopePending($query)
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }
}
