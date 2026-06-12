<?php

namespace SaaS\Core\Tests\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use SaaS\Core\Audit\Traits\Auditable;
use Spatie\Permission\Traits\HasRoles;

/**
 * User Model per i test.
 *
 * Include tutti i trait del package per testare l'integrazione completa:
 *   - HasApiTokens  → token Sanctum (mobile auth)
 *   - Notifiable    → invio notifiche (es. RecoveryLinkNotification)
 *   - Auditable     → logging automatico su activity_log
 *   - SoftDeletes   → cancellazione logica (richiesta da GdprEraser)
 *   - HasRoles      → ruoli spatie (richiesto da SaasPolicy e TotpMiddleware)
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use Notifiable;
    use Auditable;
    use SoftDeletes;
    use HasRoles;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'email_verified_at',
    ];

    protected $casts = [
        'email_verified_at'       => 'datetime',
        // Necessario per confronti null != null nei test TOTP
        'two_factor_confirmed_at' => 'datetime',
    ];
}
