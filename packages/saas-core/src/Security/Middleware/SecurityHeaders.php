<?php

namespace SaaS\Core\Security\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeaders Middleware
 *
 * Aggiunge header HTTP di sicurezza ad ogni response dell'applicazione.
 *
 * PERCHÉ QUESTI HEADER?
 *   I browser moderni li usano per proteggersi da attacchi comuni.
 *   Senza di loro, l'app è vulnerabile a clickjacking, MIME sniffing,
 *   downgrade da HTTPS a HTTP, e altri attacchi lato client.
 *
 * COME SI USA:
 *   Nel RouteServiceProvider dell'app, applicalo globalmente:
 *     Route::middleware(['web', 'security.headers'])->group(...)
 *   Oppure solo sulle route che restituiscono HTML (non necessario sulle API JSON).
 *
 * COSA FA OGNI HEADER:
 *   X-Frame-Options: DENY
 *     → Il browser non permette di caricare questa pagina in un iframe.
 *       Protegge da clickjacking (es. iframe invisibile sopra un pulsante).
 *
 *   X-Content-Type-Options: nosniff
 *     → Il browser non indovina il Content-Type — usa solo quello dichiarato.
 *       Evita che un file .txt venga eseguito come JavaScript.
 *
 *   Referrer-Policy: strict-origin-when-cross-origin
 *     → Limita le informazioni mandate nell'header Referer alle request cross-origin.
 *       Previene la fuga di URL interni verso siti terzi.
 *
 *   Permissions-Policy
 *     → Disabilita API del browser non necessarie (camera, mic, geolocation, ecc.)
 *       Riduce la superficie di attacco in caso di XSS.
 *
 *   Strict-Transport-Security (HSTS)
 *     → Dice al browser: "usa SEMPRE HTTPS per questo dominio per 1 anno".
 *       Protegge da downgrade attack (qualcuno che forza HTTP invece di HTTPS).
 *       ATTENZIONE: attivare solo in produzione con HTTPS funzionante.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // HSTS: attivato solo se HTTPS è configurato (non in local dev)
        // Il valore viene letto dalla config così ogni SaaS può personalizzare
        if ($request->secure()) {
            $maxAge = config('saas-core.security.hsts_max_age', 31536000);
            $response->headers->set(
                'Strict-Transport-Security',
                "max-age={$maxAge}; includeSubDomains"
            );
        }

        return $response;
    }
}
