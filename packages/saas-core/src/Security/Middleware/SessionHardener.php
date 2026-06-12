<?php

namespace SaaS\Core\Security\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SessionHardener Middleware
 *
 * Protegge la sessione web da due classi di attacchi:
 *
 * 1. SESSION FIXATION
 *    Un attaccante imposta un session ID noto alla vittima prima del login.
 *    Dopo il login, la vittima usa quel session ID e l'attaccante può
 *    accedere alla sua sessione. Soluzione: rigenerare il session ID al login.
 *    Laravel lo fa automaticamente nel LoginController di Fortify, ma questo
 *    middleware lo forza anche in flussi custom (es. passkeys, OAuth).
 *
 * 2. SESSION HIJACKING DA INATTIVITÀ
 *    Se una sessione rimane aperta troppo a lungo senza attività
 *    (es. utente lascia PC incustodito), un attaccante con accesso fisico
 *    o cookie rubato può usarla. Soluzione: timeout di inattività.
 *    Ogni request aggiorna 'last_activity'. Se il gap supera session_lifetime,
 *    la sessione viene invalidata e l'utente deve riloggarsi.
 *
 * COME SI USA:
 *   Nel RouteServiceProvider, aggiungi alle route web autenticate:
 *     Route::middleware(['web', 'auth', 'session.hardener'])->group(...)
 *
 * NOTA: Usare solo con driver sessione 'database' o 'redis', non 'cookie'.
 * Con driver 'cookie' la sessione vive lato client e non è invalidabile.
 */
class SessionHardener
{
    public function handle(Request $request, Closure $next): Response
    {
        // Agisce solo se la sessione è attiva (route web, non API stateless)
        if (! $request->hasSession()) {
            return $next($request);
        }

        $session  = $request->session();
        $lifetime = config('saas-core.auth.session_lifetime', 120) * 60; // minuti → secondi

        // Controlla timeout inattività solo per utenti autenticati
        if (Auth::check()) {
            $lastActivity = $session->get('last_activity');

            if ($lastActivity !== null && time() - $lastActivity > $lifetime) {
                // Sessione scaduta per inattività: logout e redirect al login
                Auth::logout();
                $session->invalidate();
                $session->regenerateToken();

                // URL di login configurabile — ogni SaaS può personalizzarlo.
                // Fallback a '/login' per compatibilità con Laravel Fortify.
                $loginUrl = config('saas-core.auth.login_url', '/login');

                return redirect($loginUrl)->withErrors([
                    'session' => 'La sessione è scaduta per inattività. Accedi di nuovo.',
                ]);
            }

            // Aggiorna il timestamp di ultima attività ad ogni request
            $session->put('last_activity', time());
        }

        return $next($request);
    }
}
