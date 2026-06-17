<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureLegalModule
 *
 * Cancello del verticale legale (modulo Pratiche). Se il modulo è disattivo,
 * le route legali non sono raggiungibili (404, per non rivelarne l'esistenza).
 *
 * Oggi legge un flag globale (config knowledge.legal.enabled). Punto unico in cui,
 * in futuro, agganciare una feature di piano per-tenant: cambierà solo la sorgente
 * del valore, non i punti d'uso (alias 'legal.enabled' sulle route).
 */
class EnsureLegalModule
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('knowledge.legal.enabled'), 404);

        return $next($request);
    }
}
