<?php

use Illuminate\Support\Facades\Route;
use SaaS\Core\Auth\Totp\TotpChallengeController;
use SaaS\Core\Auth\Totp\TotpSetupController;

/**
 * Route TOTP (Two-Factor Authentication)
 *
 * Queste route vengono registrate SOLO se 'auth.totp' => true in saas-core.php.
 * Il ServiceProvider le carica condizionalmente.
 *
 * MIDDLEWARE 'web':
 * Come tutte le route del package, include esplicitamente 'web' per garantire
 * StartSession. Route caricate da loadRoutesFrom() non ricevono il gruppo 'web'
 * automaticamente (vedi CLAUDE.md per la spiegazione completa).
 *
 * RATE LIMITING:
 * Le route di challenge usano il profilo 'login' (5 tentativi/min per IP)
 * per prevenire brute force sui codici TOTP a 6 cifre.
 * I codici TOTP sono validi 30 secondi: con 5 tentativi/min, un attaccante
 * ha al massimo 5 guess per ogni finestra temporale — non abbastanza per forza bruta.
 *
 * SEPARAZIONE SETUP / CHALLENGE:
 *   /profile/totp/* — enrollment (utente autenticato, profilo)
 *   /auth/totp/*    — verifica durante il login (post-passkey)
 */

// --- CHALLENGE (verifica TOTP durante il login) ---
// L'utente si è già autenticato via passkey ma deve ancora verificare il TOTP.
Route::prefix('auth/totp')
    ->middleware(['web', 'auth', 'throttle:login'])
    ->controller(TotpChallengeController::class)
    ->group(function () {

        // Verifica il codice TOTP (o recovery code) e imposta totp_verified in sessione
        // Input:  { "code": "123456" }
        // Output: { "redirect": "/dashboard" }
        Route::post('challenge', 'verify')->name('totp.challenge');

        // Stato: il TOTP è richiesto per questo utente?
        // Output: { "totp_required": true, "totp_enabled": true }
        Route::get('status', 'status')->name('totp.status');
    });

// --- SETUP (gestione TOTP dal profilo) ---
// L'utente è autenticato e vuole configurare o disabilitare il TOTP.
Route::prefix('profile/totp')
    ->middleware(['web', 'auth', 'throttle:login'])
    ->controller(TotpSetupController::class)
    ->group(function () {

        // Genera un nuovo segreto TOTP e l'URL per il QR code
        // Output: { "secret": "JBSWY3DPEHPK3PXP", "qr_url": "otpauth://..." }
        Route::get('setup', 'setup')->name('totp.setup');

        // Conferma il segreto con il primo codice — attiva il TOTP
        // Input:  { "code": "123456" }
        // Output: { "message": "...", "recovery_codes": ["XXXXX-XXXXX", ...] }
        Route::post('confirm', 'confirm')->name('totp.confirm');

        // Disabilita il TOTP (richiede il codice corrente)
        // Input:  { "code": "123456" }
        // Output: { "message": "TOTP disabilitato." }
        Route::post('disable', 'disable')->name('totp.disable');
    });

// --- MOBILE (React Native, stateless via Sanctum) ---
// Niente middleware 'web': le app native non hanno sessione né cookie.

// Challenge post-passkey: richiede il PENDING token (ability totp-pending)
// emesso da PasskeyController::verifyMobile quando il TOTP è richiesto.
Route::post('auth/mobile/totp/verify', [\SaaS\Core\Auth\Totp\MobileTotpController::class, 'verify'])
    ->middleware(['auth:sanctum', 'abilities:totp-pending', 'throttle:login'])
    ->name('mobile.totp.verify');

// Setup/stato: richiedono un ACCESS token valido (utente già loggato).
// Riusa TotpSetupController: la sua logica è già stateless (solo $request->user()).
Route::prefix('auth/mobile/totp')
    ->middleware(['auth:sanctum', 'abilities:access', 'throttle:login'])
    ->group(function () {
        Route::get('status', [\SaaS\Core\Auth\Totp\MobileTotpController::class, 'status'])->name('mobile.totp.status');
        Route::get('setup', [TotpSetupController::class, 'setup'])->name('mobile.totp.setup');
        Route::post('confirm', [TotpSetupController::class, 'confirm'])->name('mobile.totp.confirm');
        Route::post('disable', [TotpSetupController::class, 'disable'])->name('mobile.totp.disable');
    });
