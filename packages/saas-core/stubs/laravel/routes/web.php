<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use LaravelWebauthn\Models\WebauthnKey;

/**
 * Route Web — saas/core starter
 *
 * MIDDLEWARE DISPONIBILI:
 *   security.headers  → X-Frame-Options, HSTS, Referrer-Policy, Permissions-Policy
 *   session.hardener  → scade sessione dopo inattività, protegge da session hijacking
 *   totp              → richiede TOTP verificato (se utente ha 2FA attivo)
 *   tenant            → SetTenant: filtra query per tenant_id (multi-tenancy)
 *   role:admin        → RequireRole: solo utenti con quel ruolo
 *
 * Le route passkey (challenge/verify/register) sono già registrate dal ServiceProvider.
 * Le route TOTP (profile/totp/*, auth/totp/*) sono già registrate dal ServiceProvider.
 * Le route recovery (recover, recover/verify) sono già registrate dal ServiceProvider.
 */

// ---------------------------------------------------------------------------
// Digital Asset Links (Android) — autorizza l'app a usare le passkey su
// questo dominio. Configura nel .env:
//   ANDROID_PACKAGE_NAME=com.tuaazienda.tuaapp
//   ANDROID_SHA256_FINGERPRINTS=AA:BB:...  (virgola per più chiavi)
// NOTA: config() e non env() — env() restituisce null con config:cache attivo.
// ---------------------------------------------------------------------------
Route::get('/.well-known/assetlinks.json', function () {
    return response()->json([[
        'relation' => [
            'delegate_permission/common.handle_all_urls',
            'delegate_permission/common.get_login_creds',
        ],
        'target' => [
            'namespace'                => 'android_app',
            'package_name'             => config('saas-core.mobile.android_package_name'),
            'sha256_cert_fingerprints' => config('saas-core.mobile.android_sha256_fingerprints', []),
        ],
    ]]);
})->withoutMiddleware(['security.headers']);

// ---------------------------------------------------------------------------
// Apple App Site Association (iOS) — equivalente di assetlinks per le passkey
// native iOS (associated domains "webcredentials:"). Configura nel .env:
//   IOS_APP_ID=TEAMID.com.tuaazienda.tuaapp
// Apple richiede Content-Type application/json e NESSUN redirect.
// ---------------------------------------------------------------------------
Route::get('/.well-known/apple-app-site-association', function () {
    $appId = config('saas-core.mobile.ios_app_id');

    return response()->json([
        'webcredentials' => [
            'apps' => $appId !== '' ? [$appId] : [],
        ],
    ]);
})->withoutMiddleware(['security.headers']);

// ---------------------------------------------------------------------------
// Route pubbliche (login, registrazione, recovery)
// ---------------------------------------------------------------------------
Route::middleware(['security.headers'])->group(function () {
    Route::get('/', fn () => redirect('/login'));
    Route::get('/login',    fn () => Inertia::render('Auth/Login'))->name('login');
    Route::get('/register', fn () => Inertia::render('Auth/Register'))->name('register');
    Route::get('/recover',  fn () => Inertia::render('Auth/Recover'))->name('recover');
});

// ---------------------------------------------------------------------------
// Pagina challenge TOTP (post-passkey, pre-dashboard)
// NON includere 'totp' middleware qui: creerebbe un loop infinito.
// ---------------------------------------------------------------------------
Route::middleware(['web', 'auth', 'security.headers'])
    ->get('/auth/totp', fn () => Inertia::render('Auth/TotpChallenge'))
    ->name('totp.challenge.page');

// ---------------------------------------------------------------------------
// Route autenticate (richiede passkey + TOTP se abilitato)
// ---------------------------------------------------------------------------
Route::middleware(['auth', 'security.headers', 'session.hardener', 'totp'])->group(function () {

    Route::get('/dashboard', fn () => Inertia::render('Dashboard', [
        'auth' => ['user' => auth()->user()],
    ]))->name('dashboard');

    // Profilo: gestione passkey (aggiunta / rimozione dispositivi)
    Route::get('/profile/passkeys', function () {
        $keys = WebauthnKey::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'type', 'created_at'])
            ->map(fn ($key) => [
                'id'            => $key->id,
                'name'          => $key->name,
                'type'          => $key->type,
                'registered_at' => $key->created_at->format('d/m/Y'),
            ]);

        return Inertia::render('Profile/Passkeys', [
            'auth'        => ['user' => auth()->user()],
            'initialKeys' => $keys,
        ]);
    })->name('profile.passkeys');

    // Profilo: gestione TOTP (attiva / disattiva 2FA)
    Route::get('/profile/totp', fn () => Inertia::render('Profile/Totp', [
        'auth'         => ['user' => auth()->user()],
        'totp_enabled' => auth()->user()->two_factor_confirmed_at !== null,
    ]))->name('profile.totp');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return redirect('/login');
    })->name('logout');

});
