<?php

namespace SaaS\Core\Tenancy\Resolvers;

use Illuminate\Http\Request;
use SaaS\Core\Kernel\Contracts\TenantResolverInterface;

/**
 * HeaderResolver
 *
 * Risolve il tenant leggendo un header HTTP custom nella request.
 *
 * ESEMPIO:
 *   Header: X-Tenant-ID: acme  → restituisce "acme"
 *   Header assente              → restituisce null
 *
 * USATO DA: React Native. L'app mobile non ha sottodomini — manda il tenant
 *           come header ad ogni chiamata API.
 *
 * COME SI CONFIGURA IN REACT NATIVE:
 *   // Ogni chiamata API include l'header
 *   axios.defaults.headers.common['X-Tenant-ID'] = 'acme';
 *
 * SICUREZZA:
 *   L'header da solo non basta per autenticare il tenant — è solo un identificatore.
 *   La vera protezione è il TenantScope sul database: anche se un client mandasse
 *   un tenant_id sbagliato, vedrebbe solo i dati di quel tenant (e non è il suo).
 *   L'autenticazione utente (Sanctum token) garantisce che l'utente appartenga
 *   effettivamente al tenant che dichiara.
 */
class HeaderResolver implements TenantResolverInterface
{
    // Nome dell'header HTTP che contiene il tenant slug.
    // Costante per evitare typo sparsi nel codice.
    public const HEADER_NAME = 'X-Tenant-ID';

    public function resolve(Request $request): ?string
    {
        // Legge il valore dell'header. Restituisce null se l'header non è presente.
        $tenantId = $request->header(self::HEADER_NAME);

        if (empty($tenantId)) {
            return null;
        }

        // Normalizza a lowercase per consistenza con SubdomainResolver
        return strtolower($tenantId);
    }
}
