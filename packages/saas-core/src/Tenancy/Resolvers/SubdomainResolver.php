<?php

namespace SaaS\Core\Tenancy\Resolvers;

use Illuminate\Http\Request;
use SaaS\Core\Kernel\Contracts\TenantResolverInterface;

/**
 * SubdomainResolver
 *
 * Risolve il tenant leggendo il sottodominio dell'URL.
 *
 * ESEMPIO:
 *   acme.tuosaas.com     → restituisce "acme"
 *   navarra.tuosaas.com  → restituisce "navarra"
 *   tuosaas.com          → restituisce null (landing page, nessun tenant)
 *
 * USATO DA: app web Vue (il browser ha sempre un hostname completo).
 *
 * COME FUNZIONA:
 *   1. Prende l'host dalla request: "acme.tuosaas.com"
 *   2. Divide per "." e conta i segmenti
 *   3. Se ci sono 3+ segmenti il primo è il sottodominio = tenant slug
 *   4. Se ci sono solo 2 segmenti siamo sul dominio root = nessun tenant
 */
class SubdomainResolver implements TenantResolverInterface
{
    /**
     * TLD "bare" che non hanno un dominio base → trattati come dev/test.
     * acme.localhost  = sottodominio valido (2 segmenti)
     * acme.test       = sottodominio valido (2 segmenti)
     * tuosaas.com     = dominio root (2 segmenti, ma non è .localhost/.test)
     */
    private const DEV_TLDS = ['localhost', 'test'];

    public function resolve(Request $request): ?string
    {
        // Prende l'host senza porta (es. "acme.tuosaas.com" senza ":443")
        $host = $request->getHost();

        // Divide l'host nei suoi segmenti: ["acme", "tuosaas", "com"]
        $segments = explode('.', $host);
        $count    = count($segments);

        // Dominio root a 2 segmenti (es. "tuosaas.com"): nessun tenant.
        // ECCEZIONE: se il TLD è uno dei DEV_TLDS (localhost, test), allora
        // "acme.localhost" o "acme.test" hanno un sottodominio valido.
        if ($count === 2 && ! in_array(strtolower($segments[1]), self::DEV_TLDS, true)) {
            return null;
        }

        // Dominio singolo senza punti (es. "localhost" da solo): nessun tenant.
        if ($count < 2) {
            return null;
        }

        // Il primo segmento è il sottodominio = slug del tenant.
        // strtolower per normalizzare: "ACME" e "acme" sono lo stesso tenant.
        return strtolower($segments[0]);
    }
}
