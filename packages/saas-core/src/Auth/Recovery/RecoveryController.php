<?php

namespace SaaS\Core\Auth\Recovery;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * RecoveryController
 *
 * Gestisce il recupero accesso quando l'utente ha perso il dispositivo
 * con la passkey e non ha altri dispositivi registrati.
 *
 * FLUSSO MAGIC LINK (nessuna password, nessuna tabella extra):
 *
 *   1. POST /recover  { email }
 *      → trova l'utente per email
 *      → genera un URL firmato con scadenza 15 minuti usando
 *        URL::temporarySignedRoute() — Laravel verifica la firma
 *        crittograficamente, non serve salvarla nel database
 *      → invia email con il link
 *      → risponde con successo (anche se email non trovata, per non
 *        rivelare quali email sono registrate — security best practice)
 *
 *   2. GET /recover/verify?email=...&expires=...&signature=...
 *      → Laravel verifica automaticamente firma + scadenza
 *      → autentica l'utente
 *      → redirect a /profile/passkeys?recovery=1
 *      → l'utente può registrare un nuovo dispositivo
 *
 * SICUREZZA:
 *   - Il link scade in 15 minuti
 *   - La firma usa APP_KEY — non può essere falsificata
 *   - Rate limiting: 3 richieste ogni 10 minuti per email (throttle:recovery)
 *   - Non rivela se l'email è registrata (risposta identica per email nota/sconosciuta)
 */
class RecoveryController extends Controller
{
    /**
     * send() — invia il magic link all'email dell'utente
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $userModel = config('auth.providers.users.model');
        $user      = $userModel::where('email', $request->email)->first();

        // Se l'utente esiste, invia il link. Se non esiste, non facciamo nulla
        // ma rispondiamo con lo stesso messaggio per non rivelare le email registrate.
        if ($user) {
            // URL::temporarySignedRoute() genera un URL con:
            //   - firma HMAC con APP_KEY → impossibile falsificare
            //   - timestamp di scadenza → 15 minuti
            // Non serve nessuna tabella extra: la firma è autocontenuta.
            $link = URL::temporarySignedRoute(
                'recover.verify',
                now()->addMinutes(15),
                ['email' => $user->email]
            );

            $user->notify(new RecoveryLinkNotification($link));
        }

        return response()->json([
            'message' => 'Se l\'email è registrata riceverai un link di accesso entro pochi secondi.',
        ]);
    }

    /**
     * verify() — verifica il magic link e autentica l'utente
     *
     * Laravel valida automaticamente la firma e la scadenza grazie al
     * middleware 'signed' applicato sulla route.
     */
    public function verify(Request $request)
    {
        $userModel = config('auth.providers.users.model');
        $user      = $userModel::where('email', $request->query('email'))->first();

        if (! $user) {
            // Email non trovata — link valido ma utente eliminato nel frattempo
            return redirect('/login')->withErrors([
                'session' => 'Link non valido. Richiedi un nuovo link di recupero.',
            ]);
        }

        // Autentica l'utente nella sessione web
        Auth::login($user);

        // Previene session fixation: il vecchio session ID (pre-login) non deve
        // rimanere valido dopo l'autenticazione.
        // hasSession() guard: nei test senza middleware web la sessione non è inizializzata.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // Il click sul magic link dimostra che l'utente controlla l'email.
        // Segniamo l'email come verificata così il flusso di eliminazione passkey
        // (che richiede email verificata come fallback di recupero) funziona correttamente.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        // Redirect alla pagina di gestione passkey con parametro recovery=1
        // La pagina mostra un banner "Stai effettuando l'accesso da recovery"
        // e invita l'utente ad aggiungere subito un nuovo dispositivo.
        return redirect('/profile/passkeys?recovery=1');
    }
}
