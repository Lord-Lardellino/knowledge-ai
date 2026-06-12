<?php

namespace SaaS\Core;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * SaasCoreServiceProvider
 *
 * Questo è il punto di ingresso del package.
 * Laravel lo scopre automaticamente grazie alla chiave "extra.laravel.providers"
 * nel composer.json — non serve registrarlo manualmente nell'app.
 *
 * Un ServiceProvider ha due metodi principali:
 *   - register(): lega le interfacce alle implementazioni nel container IoC.
 *                 Gira PRIMA che l'app sia pronta. Solo binding, niente altro.
 *   - boot():     configura tutto il resto (route, migration, middleware, ecc.).
 *                 Gira DOPO che tutti i provider sono stati registrati.
 */
class SaasCoreServiceProvider extends ServiceProvider
{
    /**
     * register()
     *
     * Qui diciamo al container di Laravel: "quando qualcuno chiede
     * TenantResolverInterface, dagli SubdomainResolver".
     *
     * Questo è il pattern Dependency Injection: il codice dipende
     * dall'interfaccia (contratto astratto), non dall'implementazione concreta.
     * Così ogni SaaS può sostituire un'implementazione senza toccare il package.
     */
    public function register(): void
    {
        // Merge della configurazione del package con quella dell'app.
        // Se l'app pubblica e modifica saas-core.php, le sue chiavi sovrascrivono
        // quelle default del package. Le chiavi non toccate restano quelle del package.
        $this->mergeConfigFrom(
            __DIR__ . '/../config/saas-core.php',
            'saas-core'
        );

        // Binding del TenantResolver: legge la config per decidere quale
        // implementazione usare (subdomain o header).
        // Viene registrato come singleton: una sola istanza per tutta la request.
        $this->app->singleton(
            \SaaS\Core\Kernel\Contracts\TenantResolverInterface::class,
            function () {
                // Legge 'tenancy.resolver' dalla config: 'subdomain' o 'header'
                $resolver = config('saas-core.tenancy.resolver', 'subdomain');

                return match ($resolver) {
                    'header'    => new \SaaS\Core\Tenancy\Resolvers\HeaderResolver(),
                    // Web (subdomain) + mobile (header) nella stessa app:
                    // prova prima l'header, poi il sottodominio.
                    'composite' => new \SaaS\Core\Tenancy\Resolvers\CompositeResolver(),
                    default     => new \SaaS\Core\Tenancy\Resolvers\SubdomainResolver(),
                };
            }
        );

        // Binding del TwoFactorAuthenticationProvider di Fortify.
        // TotpSetupController e TotpChallengeController lo ricevono via DI.
        // Fortify lo registra nel suo ServiceProvider, ma lo facciamo anche qui
        // per garantire che sia disponibile anche se Fortify non è stato bootato
        // (es. test che non caricano FortifyServiceProvider esplicitamente).
        if (! $this->app->bound(\Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider::class)) {
            $this->app->singleton(
                \Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider::class,
                \Laravel\Fortify\TwoFactorAuthenticationProvider::class
            );
        }

        // Disattiva le route native di Fortify (default).
        // Il package usa Fortify solo come libreria TOTP: le sue route /login,
        // /two-factor-challenge ecc. confliggono con quelle del package e su
        // Laravel fresh esplodono (LoginViewResponse non bindato).
        // register() di tutti i provider gira PRIMA di ogni boot(), quindi il
        // flag statico è impostato prima che Fortify registri le route.
        if (! config('saas-core.auth.fortify_routes', false)
            && class_exists(\Laravel\Fortify\Fortify::class)) {
            \Laravel\Fortify\Fortify::ignoreRoutes();
        }

        // --- ORIGIN WEBAUTHN PER APP NATIVE ---
        // Le passkey native Android/iOS inviano un origin non-HTTPS
        // (es. "android:apk-key-hash:..."): di default webauthn-lib lo rifiuta
        // con "Invalid scheme. HTTPS required.".
        // Se l'app definisce 'auth.passkey_origins', estendiamo la factory dei
        // ceremony step con la lista degli origin consentiti.
        // ATTENZIONE: la lista sostituisce il controllo di default, quindi
        // aggiungiamo sempre anche APP_URL per non rompere il login da browser.
        $this->app->extend(
            \Webauthn\CeremonyStep\CeremonyStepManagerFactory::class,
            function (\Webauthn\CeremonyStep\CeremonyStepManagerFactory $factory) {
                $extraOrigins = config('saas-core.auth.passkey_origins', []);

                if ($extraOrigins !== []) {
                    $factory->setAllowedOrigins(array_values(array_unique(array_merge(
                        [config('app.url')],
                        $extraOrigins
                    ))));
                }

                return $factory;
            }
        );
    }

    /**
     * boot()
     *
     * Tutto ciò che richiede che il framework sia completamente avviato.
     * L'ordine qui è importante: prima config e migration (passive),
     * poi middleware (modificano le request), poi route (definiscono gli endpoint).
     */
    public function boot(Router $router): void
    {
        // Registra il comando Artisan solo quando la CLI è disponibile
        if ($this->app->runningInConsole()) {
            $this->commands([
                \SaaS\Core\Console\Commands\InstallCommand::class,
            ]);
        }

        // --- PUBBLICAZIONE ASSET ---
        // vendor:publish copia questi file nell'app consumatrice.
        // Il tag permette di pubblicare solo una categoria alla volta:
        //   php artisan vendor:publish --tag=saas-core-config
        //   php artisan vendor:publish --tag=saas-core-migrations
        //   php artisan vendor:publish --tag=saas-core-views
        //   php artisan vendor:publish --tag=saas-core-stubs

        $this->publishes([
            __DIR__ . '/../config/saas-core.php' => config_path('saas-core.php'),
        ], 'saas-core-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'saas-core-migrations');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/saas-core'),
        ], 'saas-core-views');

        $this->publishes([
            __DIR__ . '/../stubs' => base_path('stubs/saas-core'),
        ], 'saas-core-stubs');

        // --- MIGRATION ---
        // loadMigrationsFrom carica le migration direttamente dal package,
        // senza che l'app debba pubblicarle prima.
        // php artisan migrate le trova e le esegue automaticamente.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // --- VIEWS ---
        // Registra il namespace 'saas-core' per le Blade views.
        // Nell'app si usano come: @include('saas-core::auth.passkey-button')
        // Se l'app pubblica e sovrascrive la view, Laravel usa quella dell'app.
        // La directory può non esistere (il package al momento non spedisce view):
        // registrarla comunque rompe view:cache in produzione.
        if (is_dir(__DIR__ . '/../resources/views')) {
            $this->loadViewsFrom(__DIR__ . '/../resources/views', 'saas-core');
        }

        // --- MIDDLEWARE ---
        // Registra i middleware con un alias corto usabile nelle route:
        //   Route::middleware('tenant')->group(...)
        //   Route::middleware('role:admin')->group(...)
        //   Route::middleware('security.headers')->group(...)
        //   Route::middleware('totp')->group(...)   ← nuovo: richiede TOTP verificato
        $router->aliasMiddleware('tenant', \SaaS\Core\Tenancy\Middleware\SetTenant::class);
        $router->aliasMiddleware('role', \SaaS\Core\Access\Middleware\RequireRole::class);
        $router->aliasMiddleware('security.headers', \SaaS\Core\Security\Middleware\SecurityHeaders::class);
        $router->aliasMiddleware('session.hardener', \SaaS\Core\Security\Middleware\SessionHardener::class);
        $router->aliasMiddleware('totp', \SaaS\Core\Auth\Totp\TotpMiddleware::class);

        // Alias Sanctum per il controllo delle abilities sui token mobile.
        // Sanctum NON li registra da solo (vanno dichiarati dall'app): senza,
        // le route con 'abilities:refresh'/'abilities:totp-pending' lanciano
        // "Target class [abilities] does not exist" alla prima chiamata.
        // Il check evita di sovrascrivere un alias custom dell'app.
        if (! isset($router->getMiddleware()['abilities'])) {
            $router->aliasMiddleware('abilities', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class);
        }
        if (! isset($router->getMiddleware()['ability'])) {
            $router->aliasMiddleware('ability', \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class);
        }

        // --- RATE LIMITER ---
        // Registra i profili di rate limiting usati nelle route.
        // 'login': 5 tentativi per minuto per IP — blocca brute force sulle passkey.
        // 'api':   100 request per minuto per IP — limite globale per le API.
        // I valori si leggono da saas-core.php così ogni SaaS può personalizzarli.
        RateLimiter::for('login', function ($request) {
            [$attempts, $minutes] = config('saas-core.security.rate_limit.login', [5, 1]);

            return Limit::perMinutes($minutes, $attempts)
                ->by($request->ip());
        });

        RateLimiter::for('api', function ($request) {
            [$attempts, $minutes] = config('saas-core.security.rate_limit.api', [100, 1]);

            return Limit::perMinutes($minutes, $attempts)
                ->by($request->ip());
        });

        // 'recovery': 3 richieste ogni 10 minuti per IP
        // Previene lo spam sull'endpoint di invio magic link e l'enumerazione email.
        RateLimiter::for('recovery', function ($request) {
            [$attempts, $minutes] = config('saas-core.security.rate_limit.recovery', [3, 10]);

            return Limit::perMinutes($minutes, $attempts)
                ->by($request->ip());
        });

        // --- ROUTE ---
        // Le route passkey sono opzionali: si caricano solo se 'auth.passkeys'
        // è true nella config. Un SaaS che non usa passkey non vede questi endpoint.
        if (config('saas-core.auth.passkeys', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/passkeys.php');
        }

        // Le route TOTP sono opzionali: si caricano solo se 'auth.totp' è true.
        // Un SaaS che usa solo passkey senza secondo fattore non vede questi endpoint.
        if (config('saas-core.auth.totp', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/totp.php');
        }

        // Le route mobile (refresh, logout) si caricano sempre —
        // servono a qualsiasi SaaS con un'app React Native.
        $this->loadRoutesFrom(__DIR__ . '/../routes/mobile.php');

        // Le route di recovery si caricano sempre — chiunque può perdere un device.
        $this->loadRoutesFrom(__DIR__ . '/../routes/recovery.php');

        // Route tenancy (gestione inviti al tenant).
        $this->loadRoutesFrom(__DIR__ . '/../routes/tenancy.php');
    }
}
