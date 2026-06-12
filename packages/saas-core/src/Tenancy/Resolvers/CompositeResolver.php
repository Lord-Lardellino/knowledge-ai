<?php

namespace SaaS\Core\Tenancy\Resolvers;

use Illuminate\Http\Request;
use SaaS\Core\Kernel\Contracts\TenantResolverInterface;

/**
 * CompositeResolver
 *
 * Prova più strategie di risoluzione in ordine e restituisce la prima
 * che trova un tenant.
 *
 * PERCHÉ ESISTE:
 *   Un SaaS reale serve sia il web (tenant dal sottodominio: acme.tuosaas.com)
 *   sia l'app mobile (tenant dall'header X-Tenant-ID, perché l'app non ha
 *   sottodomini). I resolver singoli sono mutuamente esclusivi — questo li
 *   combina senza che l'app debba scrivere un resolver custom.
 *
 * ORDINE:
 *   1. HeaderResolver   → se la request ha X-Tenant-ID (mobile), vince quello
 *   2. SubdomainResolver → altrimenti prova il sottodominio (web)
 *
 *   L'header per primo è intenzionale: una request mobile può transitare da
 *   qualsiasi host, ma se dichiara X-Tenant-ID quella è la sua identità.
 *
 * COME SI ATTIVA (config/saas-core.php):
 *   'tenancy' => ['resolver' => 'composite']
 */
class CompositeResolver implements TenantResolverInterface
{
    /** @var TenantResolverInterface[] */
    private array $resolvers;

    public function __construct(TenantResolverInterface ...$resolvers)
    {
        $this->resolvers = $resolvers !== []
            ? $resolvers
            : [new HeaderResolver(), new SubdomainResolver()];
    }

    public function resolve(Request $request): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $tenant = $resolver->resolve($request);

            if ($tenant !== null) {
                return $tenant;
            }
        }

        return null;
    }
}
