<?php

namespace SaaS\Core\Auth\Totp;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TotpMiddleware — Blocca l'accesso a route protette finché il TOTP non è verificato.
 *
 * QUANDO USARLO:
 *   Applica questo middleware alle route che richiedono il secondo fattore.
 *   Non serve su TUTTE le route — solo su quelle critiche (dashboard, profilo, ecc.).
 *   Esempio in routes/web.php dell'app consumer:
 *
 *     Route::middleware(['auth', 'totp'])->group(function () {
 *         Route::get('/dashboard', ...)->name('dashboard');
 *         Route::get('/invoices', ...);
 *     });
 *
 * LOGICA:
 *   1. Se l'utente non è autenticato → passa (gestito da 'auth' middleware)
 *   2. Se il TOTP non è richiesto per questo utente → passa
 *      (utente senza TOTP configurato O ruolo che non richiede TOTP)
 *   3. Se la sessione ha `totp_verified = true` → passa
 *   4. Altrimenti → 403 JSON o redirect a /auth/totp (pagina challenge)
 *
 * RISPOSTA:
 *   - Richiesta JSON (API, Inertia, axios): 403 con `{ "totp_required": true }`
 *   - Richiesta web normale: redirect a /auth/totp (route 'totp.challenge.page')
 *
 * RESET SESSIONE:
 *   Il flag `totp_verified` viene pulito da SessionHardener alla scadenza
 *   della sessione (120 min di inattività). Non serve gestirlo qui.
 */
class TotpMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Utente non autenticato: lascia passare (il middleware 'auth' gestisce il redirect)
        if (! $user) {
            return $next($request);
        }

        // TOTP non richiesto per questo utente: lascia passare
        // (logica condivisa con web challenge e flusso mobile)
        if (! TotpRequirement::isRequiredFor($user)) {
            return $next($request);
        }

        // TOTP già verificato in questa sessione: lascia passare
        if (session('totp_verified')) {
            return $next($request);
        }

        // TOTP richiesto ma non ancora verificato
        if ($request->wantsJson()) {
            return response()->json([
                'message'       => 'Verifica richiesta: inserisci il codice TOTP per continuare.',
                'totp_required' => true,
            ], 403);
        }

        return redirect()->route('totp.challenge.page');
    }

}
