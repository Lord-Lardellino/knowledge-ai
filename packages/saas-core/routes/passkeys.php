<?php

use Illuminate\Support\Facades\Route;
use SaaS\Core\Auth\Passkeys\PasskeyController;
use SaaS\Core\Auth\Passkeys\PasskeyRegistrationController;
use SaaS\Core\Auth\Passkeys\PasskeyManagementController;

/**
 * Route WebAuthn / Passkeys
 *
 * Queste route vengono registrate SOLO se 'auth.passkeys' => true in saas-core.php.
 * Il ServiceProvider le carica condizionalmente — un SaaS che non usa passkey
 * non vede questi endpoint.
 *
 * MIDDLEWARE 'web':
 * Tutte le route includono il gruppo 'web' perché i controller usano session().
 * Le route caricate via loadRoutesFrom() da un ServiceProvider NON ottengono
 * automaticamente il gruppo 'web' (niente StartSession → session() scrive su
 * un driver non inizializzato → i dati vengono silenziosamente persi).
 * Il gruppo 'web' aggiunge: EncryptCookies, StartSession, ShareErrorsFromSession,
 * VerifyCsrfToken, SubstituteBindings. Il frontend deve inviare il token CSRF
 * nell'header X-CSRF-TOKEN (Inertia e axios lo fanno automaticamente).
 *
 * RATE LIMITING:
 * Applichiamo il profilo 'login' (5 tentativi/minuto per IP) definito in
 * Security/Presets/RateLimitProfiles.php — registrato nel ServiceProvider.
 *
 * PREFISSO:
 * Il prefisso /auth/passkey è fisso. Se un SaaS vuole /api/v1/passkey
 * può sovrascrivere le route pubblicandole con vendor:publish --tag=saas-core-routes.
 */
Route::prefix('auth/passkey')
    ->middleware(['web', 'throttle:login'])
    ->controller(PasskeyController::class)
    ->group(function () {

        // Step 1 — richiedi la challenge
        // Input:  { "email": "user@example.com" }
        // Output: { "challenge": "abc123...", "rpId": "tuosaas.com", "timeout": 60000 }
        Route::post('challenge', 'challenge')->name('passkey.challenge');

        // Step 2 — verifica la firma e autentica
        // Input:  { "email": "...", "response": { ...AuthenticatorAssertionResponse } }
        // Output web:    { "redirect": "/dashboard" }
        // Output mobile: { "access_token": "...", "token_type": "Bearer" }
        Route::post('verify', 'verify')->name('passkey.verify');
    });

// --- LOGIN MOBILE NATIVO ---
// React Native usa react-native-passkeys, quindi non passa dal middleware web
// e non richiede CSRF. Il device firma la challenge nativamente e riceve
// una coppia access/refresh token Sanctum.
Route::prefix('auth/mobile/passkey')
    ->middleware(['throttle:login'])
    ->controller(PasskeyController::class)
    ->group(function () {
        Route::post('challenge', 'challenge')->name('mobile.passkey.challenge');
        Route::post('verify', 'verifyMobile')->name('mobile.passkey.verify');
    });

Route::prefix('auth/mobile/passkey/register')
    ->middleware(['throttle:login'])
    ->controller(PasskeyRegistrationController::class)
    ->group(function () {
        Route::post('options', 'optionsMobile')->name('mobile.passkey.register.options');
        Route::post('/', 'registerMobile')->name('mobile.passkey.register');
    });

// --- REGISTRAZIONE ---
Route::prefix('auth/passkey/register')
    ->middleware(['web', 'throttle:login'])
    ->controller(PasskeyRegistrationController::class)
    ->group(function () {

        // Step 1 — crea utente + restituisce opzioni di attestazione WebAuthn
        // Input:  { "name": "Mario Rossi", "email": "mario@example.com" }
        // Output: PublicKeyCredentialCreationOptions (challenge, rp, user, pubKeyCredParams...)
        Route::post('options', 'options')->name('passkey.register.options');

        // Step 2 — verifica attestazione, salva chiave pubblica, autentica
        // Input:  { "response": { ...AuthenticatorAttestationResponse }, "key_name": "MacBook Pro" }
        // Output: { "redirect": "/dashboard" }
        Route::post('/', 'register')->name('passkey.register');
    });

// --- GESTIONE PASSKEY (utente autenticato) ---
// Queste route richiedono autenticazione: l'utente vuole aggiungere/rimuovere
// passkey dal proprio profilo senza dover fare logout.
Route::prefix('profile/passkeys')
    ->middleware(['web', 'auth', 'throttle:login'])
    ->controller(PasskeyManagementController::class)
    ->group(function () {

        // Lista tutte le passkey registrate dall'utente
        // Output: { "keys": [{ "id": 1, "name": "MacBook", "registered_at": "08/06/2026" }] }
        Route::get('/', 'index')->name('passkey.management.index');

        // Step 1 — prepara le opzioni per aggiungere una nuova passkey
        // Nessun input: l'utente è già autenticato, usiamo auth()->user()
        // Output: PublicKeyCredentialCreationOptions
        Route::get('/options', 'options')->name('passkey.management.options');

        // Step 2 — salva la nuova passkey
        // Input:  { "response": { ...AuthenticatorAttestationResponse }, "key_name": "iPhone 15" }
        // Output: { "message": "Passkey aggiunta con successo." }
        Route::post('/', 'store')->name('passkey.management.store');

        // Rinomina una passkey (il nome auto-rilevato dal browser può non corrispondere
        // al dispositivo fisico — es. "Windows" quando si usa iPhone via QR code)
        Route::patch('/{id}', 'rename')->name('passkey.management.rename');

        // Elimina una passkey (device smarrito o rubato)
        // Protezione: blocca eliminazione dell'ultima key se email non verificata
        Route::delete('/{id}', 'destroy')->name('passkey.management.destroy');
    });
