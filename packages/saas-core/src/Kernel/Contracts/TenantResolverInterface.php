<?php

namespace SaaS\Core\Kernel\Contracts;

use Illuminate\Http\Request;

/**
 * TenantResolverInterface
 *
 * Contratto per la risoluzione del tenant dalla request HTTP.
 *
 * PERCHÉ UN'INTERFACCIA?
 * Il middleware SetTenant non sa e non deve sapere SE il tenant arriva
 * dal sottodominio, dall'header, dal path o da un JWT.
 * Dipende solo da questo contratto. Così puoi cambiare strategia
 * in config senza toccare il middleware.
 *
 * COME SI USA:
 * Nel AppServiceProvider di un SaaS puoi sovrascrivere il binding:
 *   $this->app->singleton(TenantResolverInterface::class, MyCustomResolver::class);
 */
interface TenantResolverInterface
{
    /**
     * Risolve il tenant identifier dalla request corrente.
     *
     * Restituisce il tenant slug/id (es. "acme") oppure null
     * se la request non appartiene a nessun tenant (es. landing page).
     */
    public function resolve(Request $request): ?string;
}
