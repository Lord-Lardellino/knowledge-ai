<?php

use Illuminate\Support\Facades\Route;
use SaaS\Core\Tenancy\TenantInviteController;

/**
 * Route Tenancy — gestione inviti al tenant
 *
 * Richiedono utente autenticato, tenant risolto e ruolo admin del tenant.
 * Il gruppo 'web' è esplicito: le route caricate da un ServiceProvider
 * NON lo ricevono automaticamente (vedi CLAUDE.md del package).
 */
Route::prefix('tenant/invites')
    ->middleware(['web', 'auth', 'tenant', 'role:owner|admin'])
    ->group(function () {
        Route::get('/', [TenantInviteController::class, 'index'])->name('tenant.invites.index');
        Route::post('/', [TenantInviteController::class, 'store'])->name('tenant.invites.store');
        Route::delete('/{id}', [TenantInviteController::class, 'destroy'])->name('tenant.invites.destroy');
    });

/**
 * Variante mobile (token Sanctum invece della sessione web).
 */
Route::prefix('api/tenant/invites')
    ->middleware(['api', 'auth:sanctum', 'tenant', 'role:owner|admin'])
    ->group(function () {
        Route::get('/', [TenantInviteController::class, 'index'])->name('mobile.tenant.invites.index');
        Route::post('/', [TenantInviteController::class, 'store'])->name('mobile.tenant.invites.store');
        Route::delete('/{id}', [TenantInviteController::class, 'destroy'])->name('mobile.tenant.invites.destroy');
    });
