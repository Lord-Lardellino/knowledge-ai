<?php

use Illuminate\Support\Facades\Route;
use SaaS\Core\Auth\Recovery\RecoveryController;

/**
 * Route di recupero accesso (device smarrito / passkey persa)
 *
 * FLUSSO:
 *   1. L'utente va su /recover e inserisce la sua email
 *   2. Il server invia un magic link firmato valido 15 minuti
 *   3. L'utente clicca il link → viene autenticato → /profile/passkeys?recovery=1
 *   4. L'utente registra un nuovo dispositivo
 *
 * RATE LIMITING:
 *   Il profilo 'recovery' (3 tentativi per 10 minuti per IP) previene
 *   l'abuso dell'endpoint di invio email (spam/enumerazione).
 */
Route::prefix('recover')
    ->controller(RecoveryController::class)
    ->group(function () {

        // Invia il magic link all'email fornita
        // Input:  { "email": "mario@example.com" }
        // Output: { "message": "Se l'email è registrata riceverai un link..." }
        Route::post('/', 'send')
            ->middleware('throttle:recovery')
            ->name('recover.send');

        // Verifica il magic link e autentica l'utente
        // Query params: ?email=...&expires=...&signature=...  (generati da URL::temporarySignedRoute)
        // Il middleware 'signed' di Laravel verifica firma e scadenza automaticamente
        Route::get('/verify', 'verify')
            ->middleware('signed')
            ->name('recover.verify');
    });
