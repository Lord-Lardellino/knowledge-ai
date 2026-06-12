<?php

use Illuminate\Support\Facades\Route;
use SaaS\Core\Auth\Mobile\MobileAuthController;

/**
 * Route autenticazione mobile (React Native)
 *
 * Tutte protette da auth:sanctum — Sanctum verifica il Bearer token
 * prima di entrare nel controller.
 *
 * Il middleware 'ability' di Sanctum restringe quale tipo di token
 * è accettato su ogni endpoint:
 *   - refresh endpoint → accetta solo token con ability 'refresh'
 *   - logout endpoints → accetta entrambi (access o refresh)
 */
Route::prefix('auth/mobile')
    ->middleware(['throttle:api'])
    ->controller(MobileAuthController::class)
    ->group(function () {

        // Rinnova la coppia di token — richiede un refresh token
        // Middleware: auth:sanctum + ability:refresh
        Route::post('refresh', 'refresh')
            ->middleware(['auth:sanctum', 'abilities:refresh'])
            ->name('mobile.refresh');

        // Logout dal device corrente — richiede access token
        Route::post('logout', 'logout')
            ->middleware(['auth:sanctum'])
            ->name('mobile.logout');

        // Logout da tutti i device — richiede access token
        Route::post('logout-all', 'logoutAll')
            ->middleware(['auth:sanctum'])
            ->name('mobile.logout-all');
    });
