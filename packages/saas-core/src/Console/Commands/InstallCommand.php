<?php

namespace SaaS\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * InstallCommand
 *
 * Automatizza l'installazione del package saas/core in un nuovo progetto.
 *
 * UTILIZZO:
 *   php artisan saas-core:install
 *   php artisan saas-core:install --with-vue
 *   php artisan saas-core:install --with-react-native
 *
 * COSA FA IN ORDINE:
 *   1. Pubblica config/saas-core.php
 *   2. Pubblica migration saas/core + migration WebAuthn
 *   3. Esegue le migration
 *   4. Copia app/Models/User.php
 *   5. Copia app/Providers/AppServiceProvider.php
 *   6. Copia stub route di riferimento in stubs/saas-core/
 *   7. Esegue RoleSeeder
 *   8. [--with-vue] Copia tutti gli stub Vue 3 + Inertia + PrimeVue
 *   9. [--with-react-native] Copia stub React Native/Expo in mobile/
 */
class InstallCommand extends Command
{
    protected $signature   = 'saas-core:install
                                {--force : Sovrascrive i file esistenti senza chiedere conferma}
                                {--no-migrate : Salta le migration}
                                {--no-seed : Salta il seeder dei ruoli}
                                {--with-vue : Copia gli stub Vue 3 + Inertia + PrimeVue}
                                {--with-react-native : Copia gli stub React Native/Expo in mobile/}';

    protected $description = 'Installa e configura il package saas/core nel progetto Laravel';

    public function handle(): int
    {
        $this->components->info('Installazione saas/core...');
        $this->newLine();

        $this->publishConfig();
        $this->publishMigrations();
        $this->publishWebauthnMigrations();

        if (! $this->option('no-migrate')) {
            $this->runMigrations();
        }

        $this->copyUserModel();
        $this->copyAppServiceProvider();
        $this->copyRouteStubs();
        $this->scaffoldEnv();

        if (! $this->option('no-seed')) {
            $this->seedRoles();
        }

        if ($this->option('with-vue')) {
            $this->copyVueStubs();
        }

        if ($this->option('with-react-native')) {
            $this->copyReactNativeStubs();
        }

        $this->printSummary();

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $this->components->task('Pubblica config/saas-core.php', function () {
            $this->callSilent('vendor:publish', [
                '--tag'   => 'saas-core-config',
                '--force' => $this->option('force'),
            ]);
        });
    }

    private function publishMigrations(): void
    {
        $this->components->task('Pubblica migration saas/core', function () {
            $this->callSilent('vendor:publish', [
                '--tag'   => 'saas-core-migrations',
                '--force' => $this->option('force'),
            ]);
        });
    }

    private function publishWebauthnMigrations(): void
    {
        $this->components->task('Pubblica migration WebAuthn (webauthn_keys)', function () {
            $this->callSilent('vendor:publish', [
                '--provider' => 'LaravelWebauthn\WebauthnServiceProvider',
                '--tag'      => 'webauthn-migrations',
                '--force'    => $this->option('force'),
            ]);
        });
    }

    private function runMigrations(): void
    {
        $this->components->task('Esegue migration', function () {
            $this->callSilent('migrate');
        });
    }

    private function copyUserModel(): void
    {
        $destination = app_path('Models/User.php');
        $stub        = __DIR__ . '/../../../stubs/laravel/app/Models/User.php';

        if (! File::exists($stub)) {
            return;
        }

        if (File::exists($destination) && ! $this->option('force')) {
            if (! $this->components->confirm('app/Models/User.php esiste già. Sovrascrivere?')) {
                $this->components->warn('User.php saltato — aggiungilo manualmente (vedi stubs/laravel/app/Models/User.php)');
                return;
            }
        }

        $this->components->task('Copia app/Models/User.php', function () use ($stub, $destination) {
            File::ensureDirectoryExists(dirname($destination));
            File::copy($stub, $destination);
        });
    }

    private function copyAppServiceProvider(): void
    {
        $destination = app_path('Providers/AppServiceProvider.php');
        $stub        = __DIR__ . '/../../../stubs/laravel/app/Providers/AppServiceProvider.php';

        if (! File::exists($stub)) {
            return;
        }

        if (File::exists($destination) && ! $this->option('force')) {
            $this->components->warn('AppServiceProvider.php esistente non toccato — copia manualmente da stubs/laravel/app/Providers/');
            return;
        }

        $this->components->task('Copia app/Providers/AppServiceProvider.php', function () use ($stub, $destination) {
            File::ensureDirectoryExists(dirname($destination));
            File::copy($stub, $destination);
        });
    }

    private function copyRouteStubs(): void
    {
        $stubsDir = base_path('stubs/saas-core');

        $this->components->task('Copia stubs route in stubs/saas-core/', function () use ($stubsDir) {
            File::ensureDirectoryExists($stubsDir);

            $sources = [
                __DIR__ . '/../../../stubs/laravel/routes/web.php' => $stubsDir . '/routes-web.php.stub',
                __DIR__ . '/../../../stubs/laravel/routes/api.php' => $stubsDir . '/routes-api.php.stub',
            ];

            foreach ($sources as $source => $dest) {
                if (File::exists($source)) {
                    File::copy($source, $dest);
                }
            }
        });
    }

    private function seedRoles(): void
    {
        $this->components->task('Crea ruoli (admin, user) per guard web e sanctum', function () {
            $this->callSilent('db:seed', [
                '--class' => \SaaS\Core\Access\Seeders\RoleSeeder::class,
            ]);
        });
    }

    /**
     * Aggiunge al .env le variabili richieste dal package, commentate dove
     * il valore dipende dall'app. Senza WEBAUTHN_ID/ORIGIN le passkey
     * falliscono in modi non ovvi — meglio rendere visibile cosa configurare.
     * Idempotente: usa un marker, non duplica mai il blocco.
     */
    private function scaffoldEnv(): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            return;
        }

        $marker = '# --- saas/core ---';

        if (str_contains(File::get($envPath), $marker)) {
            return;
        }

        $this->components->task('Aggiunge blocco saas/core al .env', function () use ($envPath, $marker) {
            $appUrl  = config('app.url', 'http://localhost');
            $appHost = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';

            $block = <<<ENV


            {$marker}
            # WebAuthn / passkey — l'ID deve essere il dominio (senza schema)
            WEBAUTHN_NAME="\${APP_NAME}"
            WEBAUTHN_ID={$appHost}
            WEBAUTHN_ORIGIN={$appUrl}

            # Frontend SPA sullo stesso dominio (cookie di sessione Sanctum)
            SANCTUM_STATEFUL_DOMAINS={$appHost}
            SESSION_DRIVER=database

            # App native (decommentare e configurare se usi --with-react-native)
            # WEBAUTHN_ALLOWED_ORIGINS=android:apk-key-hash:METTI_QUI_BASE64URL
            # ANDROID_PACKAGE_NAME=com.tuaazienda.tuaapp
            # ANDROID_SHA256_FINGERPRINTS=AA:BB:CC:...
            # IOS_APP_ID=TEAMID.com.tuaazienda.tuaapp
            ENV;

            File::append($envPath, $block . "\n");
        });
    }

    private function copyVueStubs(): void
    {
        $stubBase = __DIR__ . '/../../../stubs/laravel';

        // package.json e app.css NON sono in questa lista: su Laravel fresh
        // esistono SEMPRE, quindi la copia skip-if-exists li salterebbe e la
        // build Vite fallirebbe per dipendenze/stili mancanti.
        // Vengono gestiti con merge dedicato più sotto.
        $files = [
            // Layout Blade (Inertia entry point)
            'resources/views/app.blade.php'                    => resource_path('views/app.blade.php'),
            // Configurazione progetto
            'vite.config.ts'                                   => base_path('vite.config.ts'),
            'tsconfig.json'                                    => base_path('tsconfig.json'),
            // Bootstrap Inertia + PrimeVue
            'resources/js/app.ts'                              => resource_path('js/app.ts'),
            // Composables
            'resources/js/composables/usePasskey.ts'           => resource_path('js/composables/usePasskey.ts'),
            'resources/js/composables/usePasskeyRegister.ts'   => resource_path('js/composables/usePasskeyRegister.ts'),
            'resources/js/composables/usePasskeyManagement.ts' => resource_path('js/composables/usePasskeyManagement.ts'),
            'resources/js/composables/useTotp.ts'              => resource_path('js/composables/useTotp.ts'),
            // Layout principale
            'resources/js/Layouts/AppLayout.vue'               => resource_path('js/Layouts/AppLayout.vue'),
            // Pagine autenticazione
            'resources/js/Pages/Auth/Login.vue'                => resource_path('js/Pages/Auth/Login.vue'),
            'resources/js/Pages/Auth/Register.vue'             => resource_path('js/Pages/Auth/Register.vue'),
            'resources/js/Pages/Auth/Recover.vue'              => resource_path('js/Pages/Auth/Recover.vue'),
            'resources/js/Pages/Auth/TotpChallenge.vue'        => resource_path('js/Pages/Auth/TotpChallenge.vue'),
            // Pagine dashboard e profilo
            'resources/js/Pages/Dashboard.vue'                 => resource_path('js/Pages/Dashboard.vue'),
            'resources/js/Pages/Profile/Passkeys.vue'          => resource_path('js/Pages/Profile/Passkeys.vue'),
            'resources/js/Pages/Profile/Totp.vue'              => resource_path('js/Pages/Profile/Totp.vue'),
        ];

        $this->components->task('Copia stub Vue 3 + Inertia + PrimeVue', function () use ($stubBase, $files) {
            foreach ($files as $stub => $destination) {
                $source = $stubBase . '/' . $stub;

                if (! File::exists($source)) {
                    continue;
                }

                // Salta i file già esistenti (a meno che --force)
                if (File::exists($destination) && ! $this->option('force')) {
                    continue;
                }

                File::ensureDirectoryExists(dirname($destination));
                File::copy($source, $destination);
            }
        });

        // Merge (non copia) di package.json e app.css: su Laravel fresh esistono già
        $this->mergePackageJson($stubBase);
        $this->mergeAppCss($stubBase);

        // Copia routes/web.php con conferma (esiste quasi sempre su Laravel fresh)
        $this->copyWebRoutes($stubBase);

        // Rimuove vite.config.js se presente (Laravel lo crea di default, .ts ha la precedenza)
        $this->removeViteJsConfig();

        $this->newLine();
        $this->components->warn('Ricorda: npm install && npm run build');
    }

    /**
     * Fonde le dipendenze e gli script dello stub package.json in quello dell'app.
     *
     * Laravel fresh ha SEMPRE un package.json: una copia skip-if-exists lo
     * salterebbe e la build Vite fallirebbe (mancano vue, primevue, qrcode...).
     * Strategia: le chiavi dello stub vincono solo se assenti nell'app —
     * le versioni già scelte dallo sviluppatore non vengono toccate.
     */
    private function mergePackageJson(string $stubBase): void
    {
        $stubPath = $stubBase . '/package.json';
        $appPath  = base_path('package.json');

        if (! File::exists($stubPath)) {
            return;
        }

        if (! File::exists($appPath)) {
            File::copy($stubPath, $appPath);
            return;
        }

        $this->components->task('Merge dipendenze in package.json', function () use ($stubPath, $appPath) {
            $stub = json_decode(File::get($stubPath), true);
            $app  = json_decode(File::get($appPath), true);

            if (! is_array($stub) || ! is_array($app)) {
                throw new \RuntimeException('package.json non valido: merge annullato.');
            }

            foreach (['dependencies', 'devDependencies', 'scripts'] as $section) {
                // L'app vince sulle chiavi in comune ("+" preserva la sinistra)
                $app[$section] = ($app[$section] ?? []) + ($stub[$section] ?? []);
            }

            $app['type'] = $app['type'] ?? 'module';

            File::put($appPath, json_encode(
                $app,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . "\n");
        });
    }

    /**
     * Integra il CSS del tema (Tailwind + PrimeVue) in resources/css/app.css.
     *
     * Laravel fresh ha già un app.css: invece di sovrascriverlo, lo sostituiamo
     * solo se è ancora quello di default (poche righe), altrimenti accodiamo
     * il blocco del tema con un marker per evitare doppi append.
     */
    private function mergeAppCss(string $stubBase): void
    {
        $stubPath = $stubBase . '/resources/css/app.css';
        $appPath  = resource_path('css/app.css');

        if (! File::exists($stubPath)) {
            return;
        }

        if (! File::exists($appPath)) {
            File::ensureDirectoryExists(dirname($appPath));
            File::copy($stubPath, $appPath);
            return;
        }

        $marker = '/* saas-core theme */';
        $current = File::get($appPath);

        // Già integrato in una run precedente: non duplicare
        if (str_contains($current, $marker)) {
            return;
        }

        $this->components->task('Integra tema PrimeVue/Tailwind in app.css', function () use ($stubPath, $appPath, $current, $marker) {
            $stubCss = File::get($stubPath);

            // app.css di default Laravel (solo @import/@source): lo sostituiamo.
            // File più corposo = personalizzato dallo sviluppatore: accodiamo.
            if (strlen(trim($current)) < 400) {
                File::put($appPath, $marker . "\n" . $stubCss);
            } else {
                File::append($appPath, "\n\n" . $marker . "\n" . $stubCss);
            }
        });
    }

    private function copyWebRoutes(string $stubBase): void
    {
        $stub        = $stubBase . '/routes/web.php';
        $destination = base_path('routes/web.php');

        if (! File::exists($stub)) {
            return;
        }

        if (File::exists($destination) && ! $this->option('force')) {
            if (! $this->components->confirm('routes/web.php esiste già. Sovrascrivere con il template saas/core (consigliato su fresh install)?')) {
                $this->components->warn('routes/web.php saltato — aggiungi manualmente le route da stubs/saas-core/routes-web.php.stub');
                return;
            }
        }

        $this->components->task('Copia routes/web.php', function () use ($stub, $destination) {
            File::copy($stub, $destination);
        });
    }

    private function removeViteJsConfig(): void
    {
        $jsConfig = base_path('vite.config.js');

        if (! File::exists($jsConfig)) {
            return;
        }

        $this->components->task('Rimuove vite.config.js (rimpiazzato da vite.config.ts)', function () use ($jsConfig) {
            File::delete($jsConfig);
        });
    }

    private function copyReactNativeStubs(): void
    {
        $stubBase  = __DIR__ . '/../../../stubs/react-native';
        $mobileDir = base_path('mobile');

        if (! File::exists($stubBase)) {
            return;
        }

        $this->components->task('Copia stub React Native/Expo in mobile/', function () use ($stubBase, $mobileDir) {
            foreach (File::allFiles($stubBase) as $file) {
                $relativePath = $file->getRelativePathname();

                // node_modules e android/ non vanno copiati: il primo si genera con
                // npm install, il secondo con npx expo prebuild --platform android
                $topLevel = explode(DIRECTORY_SEPARATOR, $relativePath)[0];
                if (in_array($topLevel, ['node_modules', 'android', 'ios'])) {
                    continue;
                }

                $destination = $mobileDir . DIRECTORY_SEPARATOR . $relativePath;

                if (File::exists($destination) && ! $this->option('force')) {
                    continue;
                }

                File::ensureDirectoryExists(dirname($destination));
                File::copy($file->getPathname(), $destination);
            }
        });

        $this->newLine();
        $this->components->warn('React Native: aggiorna mobile/app.json con il tuo dominio, poi: cd mobile && npm install && npx expo prebuild --platform android');
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->components->info('saas/core installato con successo!');
        $this->newLine();

        $bullets = [
            '<fg=green>config/saas-core.php</> → personalizza tenancy, auth, audit, security',
            '<fg=green>app/Models/User.php</> → già configurato con tutti i trait',
            '<fg=green>stubs/saas-core/</> → esempi di route con i middleware del package',
        ];

        if ($this->option('with-vue')) {
            $bullets[] = '<fg=green>resources/views/app.blade.php</> → Inertia entry point';
            $bullets[] = '<fg=green>resources/js/</> → Vue 3 + Inertia + PrimeVue pronti';
            $bullets[] = '<fg=green>routes/web.php</> → route complete con passkey, TOTP, dashboard';
        }

        if ($this->option('with-react-native')) {
            $bullets[] = '<fg=green>mobile/</> → React Native/Expo stub pronto';
        }

        $this->components->bulletList($bullets);

        $this->newLine();
        $this->line('  <fg=yellow>Prossimi passi:</>');
        $this->newLine();
        $this->line('  1. Configura <fg=yellow>.env</>: APP_URL, DB_*, WEBAUTHN_ID, WEBAUTHN_ORIGIN, SANCTUM_STATEFUL_DOMAINS');
        $this->line('  2. Aggiungi i middleware alle tue route (vedi stubs/saas-core/routes-web.php.stub)');

        if ($this->option('with-vue')) {
            $this->line('  3. <fg=yellow>npm install && npm run build</> per compilare il frontend');
        }

        if ($this->option('with-react-native')) {
            $this->line('  3. Aggiorna <fg=yellow>mobile/app.json</> con il tuo dominio e bundle ID');
            $this->line('  4. <fg=yellow>cd mobile && npm install && npx expo prebuild --platform android</>');
            $this->line('  5. Build: <fg=yellow>cd android && ./gradlew assembleDebug</> (APK in app/build/outputs/apk/debug/)');
            $this->line('  6. Pubblica <fg=yellow>/.well-known/assetlinks.json</> con lo SHA-256 del keystore (keytool -list -v)');
        }

        $this->newLine();
    }
}
