<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SaaS\Core\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * BindTenantFromUser
 *
 * Per le route web autenticate il tenant segue l'utente loggato (non il
 * sottodominio): lega nel container il tenant dell'utente, così il TenantScope
 * di saas-core filtra le query in lettura e l'auto-fill di SetTenant timbra
 * tenant_id in scrittura — anche in locale dove non c'è sottodominio.
 *
 * Va DOPO il middleware 'auth'. Se l'utente non ha tenant_id non bind nulla
 * (current.tenant.id resta null → nessun filtro, comportamento neutro).
 */
class BindTenantFromUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->tenant_id) {
            $tenant = Tenant::find($user->tenant_id);

            if ($tenant) {
                app()->instance('current.tenant', $tenant);
                app()->instance('current.tenant.id', $tenant->id);
            }
        }

        return $next($request);
    }
}
