<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use LaravelWebauthn\Models\WebauthnKey;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\BillingController;

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

    $authProp = function () {
        $user = auth()->user();
        $tenant = $user->tenant_id
            ? \SaaS\Core\Tenancy\Models\Tenant::find($user->tenant_id, ['id', 'name', 'slug'])
            : null;
        return [
            'user'   => $user,
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name] : null,
        ];
    };

    // Dashboard — richiede tenant attivo (trial o abbonamento) via 'subscribed'.
    Route::get('/dashboard', function () use ($authProp) {
        return Inertia::render('Dashboard', ['auth' => $authProp()]);
    })->middleware(['tenant.user', 'subscribed'])->name('dashboard');

    // -----------------------------------------------------------------------
    // Billing — pricing, checkout, gestione abbonamento.
    // NON sotto 'subscribed' (qui ci arriva chi NON ha accesso, niente loop).
    // -----------------------------------------------------------------------
    Route::middleware('tenant.user')->group(function () {
        Route::get('/billing',                  [BillingController::class, 'page'])->name('billing');
        Route::post('/billing/checkout/{plan}', [BillingController::class, 'checkout'])->name('billing.checkout');
        Route::post('/billing/swap/{plan}',     [BillingController::class, 'swap'])->name('billing.swap');
        Route::get('/billing/success',          [BillingController::class, 'success'])->name('billing.success');
        Route::get('/billing/portal',           [BillingController::class, 'portal'])->name('billing.portal');
    });

    // Profilo: gestione passkey (aggiunta / rimozione dispositivi)
    Route::get('/profile/passkeys', function () use ($authProp) {
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
            'auth'        => $authProp(),
            'initialKeys' => $keys,
        ]);
    })->name('profile.passkeys');

    // Profilo: gestione TOTP (attiva / disattiva 2FA)
    Route::get('/profile/totp', function () use ($authProp) {
        return Inertia::render('Profile/Totp', [
            'auth'         => $authProp(),
            'totp_enabled' => auth()->user()->two_factor_confirmed_at !== null,
        ]);
    })->name('profile.totp');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return redirect('/login');
    })->name('logout');

    // -----------------------------------------------------------------------
    // Knowledge base — documenti (tenant legato all'utente autenticato)
    // -----------------------------------------------------------------------
    Route::middleware(['tenant.user', 'subscribed'])->group(function () {
        Route::get('/knowledge',            [DocumentController::class, 'page'])->name('knowledge');
        Route::get('/documents',            [DocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/search',     [DocumentController::class, 'search'])->name('documents.search');
        Route::post('/documents',           [DocumentController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    });

});
