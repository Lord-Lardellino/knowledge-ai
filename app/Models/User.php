<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use SaaS\Core\Audit\Traits\Auditable;
use Spatie\Permission\Traits\HasRoles;

/**
 * User Model
 *
 * COME USARE QUESTO STUB:
 *   Sostituisci il file app/Models/User.php generato da Laravel con questo.
 *   Poi pubblica e lancia le migration del package:
 *
 *     php artisan vendor:publish --tag=saas-core-migrations
 *     php artisan migrate
 *
 * TRAIT INCLUSI:
 *
 *   HasApiTokens (Sanctum)
 *     Abilita i token API per l'autenticazione mobile/React Native.
 *     Metodi: $user->createToken(), $user->tokens(), $user->currentAccessToken()
 *
 *   HasRoles (Spatie Permission)
 *     Abilita ruoli e permessi granulari.
 *     Metodi: $user->assignRole('admin'), $user->hasRole('admin'),
 *              $user->can('edit articles'), $user->givePermissionTo('...')
 *
 *   Auditable (saas/core)
 *     Logga automaticamente create/update/delete nell'activity_log.
 *     Rispetta la config saas-core.audit.financial_models per la retention GDPR.
 *
 *   SoftDeletes
 *     Non cancella fisicamente — imposta deleted_at.
 *     Necessario per GdprEraser che anonimizza e poi soft-deletes.
 *
 * MULTI-TENANCY:
 *   Il campo tenant_id viene popolato automaticamente dal middleware SetTenant.
 *   Non devi impostarlo manualmente — ci pensa il package.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasRoles;
    use Auditable;
    use SoftDeletes;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id', // popolato automaticamente da SetTenant middleware
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
    ];
}
