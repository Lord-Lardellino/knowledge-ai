<?php

namespace SaaS\Core\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SaaS\Core\SaasCoreServiceProvider;

/**
 * TestCase base per tutti i test del package.
 *
 * Orchestra\Testbench simula un'applicazione Laravel reale.
 * Usiamo PostgreSQL reale (saas_core_test) — identico all'ambiente di produzione.
 *
 * STRATEGIA DB:
 *   migrate:fresh svuota e ricrea tutte le tabelle prima di ogni test.
 *   Su PostgreSQL non possiamo usare SQLite in-memory né RefreshDatabase
 *   con transazioni, perché vogliamo testare comportamenti PostgreSQL-specifici
 *   (tipi, vincoli, RLS in futuro).
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * Registra i ServiceProvider del package nell'app di test.
     */
    protected function getPackageProviders($_app): array
    {
        return [
            // Il nostro ServiceProvider
            SaasCoreServiceProvider::class,

            // Sanctum: necessario per HasApiTokens e personal_access_tokens
            \Laravel\Sanctum\SanctumServiceProvider::class,

            // Spatie activitylog: necessario per il trait Auditable
            \Spatie\Activitylog\ActivitylogServiceProvider::class,

            // Spatie permission: necessario per RoleSeeder e RequireRole middleware
            \Spatie\Permission\PermissionServiceProvider::class,

            // WebAuthn: necessario per la tabella webauthn_keys e il servizio Webauthn
            \LaravelWebauthn\WebauthnServiceProvider::class,
        ];
    }

    /**
     * Eseguito prima di ogni test.
     * migrate:fresh droppa tutte le tabelle e riesegue le migration da zero.
     * Garantisce un DB pulito per ogni test senza stato residuo.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Droppa e ricrea tutte le tabelle ad ogni test
        $this->artisan('migrate:fresh');

        // asbiin/laravel-webauthn pubblica le migration ma non le carica automaticamente
        // (nessun loadMigrationsFrom nel ServiceProvider). migrate:fresh non le vede.
        // Le eseguiamo esplicitamente dopo il fresh con --realpath.
        $this->artisan('migrate', [
            '--path'     => __DIR__ . '/../vendor/asbiin/laravel-webauthn/database/migrations',
            '--realpath' => true,
        ]);
    }

    /**
     * Configura l'ambiente dell'app di test.
     */
    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        // Chiave di cifratura richiesta da sessioni e cookie cifrati
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // Cache in memoria: nessun Redis necessario nei test
        $config->set('cache.default', 'array');

        // PostgreSQL dedicato ai test — mai toccare il DB di sviluppo
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver'   => 'pgsql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'saas_core_test'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', 'Admin123!'),
            'charset'  => 'utf8',
            'prefix'   => '',
            'schema'   => 'public',
        ]);

        // rpId estratto da app.url nei test passkey
        $config->set('app.url', 'https://test.tuosaas.com');

        // Il modello utente del package di test — ha Notifiable, SoftDeletes, MustVerifyEmail.
        // Senza questa config, i controller userebbero Illuminate\Foundation\Auth\User
        // che non ha notify(), causando BadMethodCallException nelle route recovery.
        $config->set('auth.providers.users.model', \SaaS\Core\Tests\Models\User::class);

        $config->set('saas-core.auth.passkeys', true);
        $config->set('saas-core.tenancy.resolver', 'subdomain');
    }
}
