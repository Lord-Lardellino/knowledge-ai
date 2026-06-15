<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use SaaS\Core\Security\Csp\SaasCorePreset;
use Spatie\Csp\Policy;

/**
 * AppServiceProvider
 *
 * COME USARE QUESTO STUB:
 *   Sostituisci (o integra) il tuo app/Providers/AppServiceProvider.php con questo.
 *
 * COSA FA:
 *   1. Registra la CSP policy di default del package (estendila se necessario)
 *   2. Mostra come sovrascrivere il TenantResolver (es. passare da subdomain a header)
 *   3. Mostra come aggiungere domini extra alla CSP (Stripe, S3, ecc.)
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // --- TENANT CONTEXT DEFAULT ---
        // Il TenantScope di saas-core legge app('current.tenant.id'). In HTTP lo
        // bindano SetTenant / BindTenantFromUser, ma in job/comandi/test no:
        // senza un default l'accesso lancerebbe "Target class does not exist".
        // Bind a null = nessun filtro tenant fuori dal contesto richiesta.
        $this->app->bindIf('current.tenant.id', fn () => null);
        $this->app->bindIf('current.tenant', fn () => null);

        // --- CSP POLICY ---
        // Registra la policy CSP di default del package.
        // Se non hai bisogno di personalizzarla, basta questa riga.
        $this->app->bind(Policy::class, fn () => Policy::create([SaasCorePreset::class]));

        // --- CUSTOM CSP (opzionale) ---
        // Se il tuo SaaS usa servizi terzi (Stripe, AWS S3, Google Fonts, ecc.),
        // crea una policy custom che estende quella base:
        //
        // use Spatie\Csp\Directive;
        // use Spatie\Csp\Presets\Stripe;
        //
        // $this->app->bind(Policy::class, fn () =>
        //     Policy::create([SaasCorePreset::class, Stripe::class])
        //         ->add(Directive::IMG, 'https://your-bucket.s3.amazonaws.com')
        // );

        // --- TENANT RESOLVER (opzionale) ---
        // Di default il package usa SubdomainResolver (acme.tuosaas.com).
        // Per usare HeaderResolver (X-Tenant-ID) nelle API mobile, modifica
        // la config in saas-core.php oppure fai un override qui:
        //
        // use SaaS\Core\Kernel\Contracts\TenantResolverInterface;
        // use SaaS\Core\Tenancy\Resolvers\HeaderResolver;
        //
        // $this->app->singleton(TenantResolverInterface::class, HeaderResolver::class);
    }

    public function boot(): void
    {
        // --- FLASH MESSAGES CONDIVISI CON INERTIA ---
        // Rende i messaggi flash (success/error) disponibili in ogni pagina Inertia
        // come prop `flash`, così il frontend può mostrarli via Toast.
        \Inertia\Inertia::share('flash', fn () => [
            'success' => session('success'),
            'error'   => session('error'),
        ]);

        // --- GDPR: registra i model finanziari ---
        // I model finanziari hanno retention 7 anni — non vengono anonimizzati
        // immediatamente da GdprEraser. Aggiungili qui:
        //
        // config(['saas-core.audit.financial_models' => [
        //     \App\Models\Invoice::class,
        //     \App\Models\Payment::class,
        //     \App\Models\Subscription::class,
        // ]]);
    }
}
