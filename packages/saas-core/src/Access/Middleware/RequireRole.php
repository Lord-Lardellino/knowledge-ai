<?php

namespace SaaS\Core\Access\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireRole Middleware
 *
 * Blocca la request se l'utente autenticato non ha il ruolo richiesto.
 *
 * COME SI USA NELLE ROUTE:
 *   Route::middleware('role:admin')->group(...)
 *   Route::middleware('role:owner,admin')->group(...)  ← OR: basta uno dei due
 *
 * DIPENDE DA: spatie/laravel-permission (registrato nel ServiceProvider)
 *   Il package Spatie aggiunge hasRole() direttamente al Model User tramite trait.
 *   Basta aggiungere `use HasRoles` al Model User dell'app consumatrice.
 *
 * ORDINE CONSIGLIATO DEI MIDDLEWARE NELLE ROUTE:
 *   auth:sanctum → tenant → role:xxx → controller
 *   Prima autentichi, poi risolvi il tenant, poi controlli il ruolo.
 */
class RequireRole
{
    /**
     * @param  string $roles  Ruoli accettati separati da virgola (es. "owner,admin")
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Se l'utente non è autenticato lasciamo fare al middleware auth:sanctum —
        // non è responsabilità di questo middleware gestire utenti non loggati.
        if (! $request->user()) {
            abort(401, 'Non autenticato.');
        }

        // hasAnyRole() di Spatie: restituisce true se l'utente ha ALMENO UNO dei ruoli
        // Questo è un OR — per un AND usa hasAllRoles() o chain più middleware
        if (! $request->user()->hasAnyRole($roles)) {
            abort(403, 'Non hai i permessi per accedere a questa risorsa.');
        }

        return $next($request);
    }
}
