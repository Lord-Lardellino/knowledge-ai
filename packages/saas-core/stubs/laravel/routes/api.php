<?php

use Illuminate\Support\Facades\Route;

/**
 * Route API — stub per SaaS con saas/core (React Native / mobile)
 *
 * FLUSSO DI AUTENTICAZIONE MOBILE:
 *
 *   1. App chiama POST /auth/passkey/challenge con { email }
 *   2. App chiama POST /auth/passkey/verify con { email, response }
 *      → riceve { access_token, refresh_token, expires_in }
 *   3. App salva i token e li invia in ogni request:
 *      Authorization: Bearer {access_token}
 *      X-Device-ID: {uuid-del-dispositivo}
 *      X-Tenant-ID: {slug-tenant}   ← usato da HeaderResolver
 *
 *   4. Quando access_token scade, app chiama POST /auth/mobile/refresh
 *      con il refresh_token → riceve nuovi token (rotation automatica)
 *
 * MIDDLEWARE DISPONIBILI:
 *
 *   auth:sanctum     → verifica il Bearer token
 *   tenant           → legge X-Tenant-ID e filtra le query per tenant
 *   role:admin       → verifica il ruolo (funziona con guard sanctum)
 *   throttle:api     → rate limiting (100 req/min, configurabile)
 *   throttle:login   → rate limiting più stretto per login (5 req/min)
 *
 * NOTA: le route /auth/mobile/* e /auth/passkey/* sono già registrate
 * automaticamente dal ServiceProvider del package. Non devi aggiungerle.
 */

// ---------------------------------------------------------------------------
// Route protette — richiede token Sanctum valido + tenant header
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {

    // Profilo utente autenticato
    Route::get('/user', fn (\Illuminate\Http\Request $request) => $request->user());

    // Esempio: lista risorse filtrate automaticamente per tenant
    // Route::apiResource('projects', \App\Http\Controllers\Api\ProjectController::class);
    // Route::apiResource('invoices', \App\Http\Controllers\Api\InvoiceController::class);

    // GDPR: cancellazione account da mobile
    // Route::delete('/user', function (\Illuminate\Http\Request $request) {
    //     (new \SaaS\Core\Audit\GdprEraser)->erase($request->user());
    //     return response()->noContent();
    // });
});

// ---------------------------------------------------------------------------
// Route admin API — solo utenti con ruolo admin via token sanctum
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'tenant', 'role:admin', 'throttle:api'])
    ->prefix('admin')
    ->group(function () {
        // Route::apiResource('users', \App\Http\Controllers\Api\Admin\UserController::class);
    });
