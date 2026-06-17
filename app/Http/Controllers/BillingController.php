<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use SaaS\Core\Billing\Exceptions\PlanDowngradeBlockedException;
use SaaS\Core\Billing\TenantBilling;
use SaaS\Core\Tenancy\Models\Tenant;

/**
 * BillingController — pricing, checkout e gestione abbonamento (lato app).
 *
 * Il meccanismo (Cashier, posti, trial, swap) vive in saas-core; qui ci sono
 * solo le route/UI specifiche di Sapio: pagina pricing, avvio checkout Stripe
 * ospitato, portale di gestione e cambio piano.
 */
class BillingController extends Controller
{
    public function __construct(private TenantBilling $billing)
    {
    }

    /** Pagina pricing + stato abbonamento del tenant. */
    public function page(Request $request): InertiaResponse
    {
        $tenant = $this->tenant($request);

        // Allinea lo stato locale a Stripe: riflette i cambi fatti nel Customer Portal
        // (cambio piano, disdetta, metodo di pagamento) anche senza webhook in locale.
        $this->billing->syncFromStripe($tenant);

        return Inertia::render('Billing/Index', [
            'auth' => [
                'user'   => $request->user(),
                'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            ],
            'plans'        => $this->plansForDisplay(),
            'isOwner'      => $this->isOwner($request),
            'currentPlan'  => $this->billing->planFor($tenant),
            'onTrial'      => $this->billing->onTrial($tenant),
            'trialDaysLeft'=> $this->billing->trialDaysLeft($tenant),
            'seatsUsed'    => $this->billing->seatsUsed($tenant),
            'seatLimit'    => $this->billing->seatLimit($tenant),
        ]);
    }

    /** Avvia il checkout Stripe ospitato per il piano scelto. */
    public function checkout(Request $request, string $plan, bool $withTrial = false): Response
    {
        $this->authorizeOwner($request);
        $tenant = $this->tenant($request);

        if (empty(config("saas-core.billing.plans.{$plan}.price_id"))) {
            return back()->with('error', 'Piano non valido o non acquistabile.');
        }

        // Se ha già un abbonamento attivo, il cambio piano è uno swap (con guard
        // sul downgrade), non un nuovo checkout.
        if ($tenant->subscribed(config('saas-core.billing.subscription_name', 'default'))) {
            return $this->swap($request, $plan);
        }

        // Logica condivisa nel core: customer con email bloccata + trial opzionale.
        $checkout = $this->billing->newCheckout(
            tenant: $tenant,
            plan: $plan,
            email: $request->user()->email,
            withTrial: $withTrial,
            urls: [
                'success_url' => route('billing.success') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => route('billing'),
            ],
        );

        // Redirect verso Stripe. Da una richiesta Inertia (router.post sulla pagina
        // billing) serve Inertia::location (409 + header); da una navigazione normale
        // (window.location dopo la registrazione → /billing/start) serve un 302 away,
        // altrimenti il browser riceve un 409 e non redirige.
        return $request->header('X-Inertia')
            ? Inertia::location($checkout->url)
            : redirect()->away($checkout->url);
    }

    /**
     * Avvio post-registrazione: l'admin ha scelto un piano in fase di registrazione.
     *   - base           → checkout Stripe con trial (carta richiesta, email bloccata)
     *   - pro/enterprise → checkout Stripe immediato, attivo subito (nessun trial)
     */
    public function start(Request $request, string $plan): Response
    {
        $this->authorizeOwner($request);

        if (! array_key_exists($plan, (array) config('saas-core.billing.plans'))) {
            abort(404);
        }

        $tenant = $this->tenant($request);
        $tenant->forceFill(['plan' => $plan])->save();

        // Il piano di default (base) parte come trial Stripe; gli altri pagamento subito.
        $isDefault = $plan === config('saas-core.billing.default_plan', 'base');

        return $this->checkout($request, $plan, withTrial: $isDefault);
    }

    /**
     * Cambio piano su abbonamento esistente: porta alla pagina Stripe di conferma
     * (Customer Portal, flusso subscription_update_confirm). L'utente conferma lì,
     * Stripe gestisce proration e metodo di pagamento, poi torna alla pagina billing.
     */
    public function swap(Request $request, string $plan): Response
    {
        $this->authorizeOwner($request);
        $tenant = $this->tenant($request);

        try {
            $url = $this->billing->planChangeUrl($tenant, $plan, route('billing'));
        } catch (PlanDowngradeBlockedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Es. il Customer Portal non ha il cambio piano abilitato.
            return back()->with('error', 'Cambio piano non disponibile al momento. Riprova più tardi.');
        }

        return $request->header('X-Inertia')
            ? Inertia::location($url)
            : redirect()->away($url);
    }

    /** Ritorno dal checkout Stripe riuscito. */
    public function success(Request $request): RedirectResponse
    {
        // Sincronizza subito la subscription da Stripe: in locale non c'è il webhook,
        // così evitiamo che un upgrade successivo crei un secondo abbonamento.
        $this->billing->syncFromStripe($this->tenant($request));

        return redirect()->route('dashboard')->with('success', 'Abbonamento attivato. Benvenuto!');
    }

    /** Portale Stripe per gestire abbonamento, metodi di pagamento, fatture. */
    public function portal(Request $request): RedirectResponse
    {
        $this->authorizeOwner($request);
        $tenant = $this->tenant($request);

        // Senza customer Stripe (es. tenant ancora in trial locale, mai passato dal
        // checkout) il portale non esiste: torna alla pagina abbonamento con avviso.
        if (! $tenant->hasStripeId()) {
            return redirect()->route('billing')
                ->with('error', 'Nessun abbonamento da gestire: completa prima la sottoscrizione di un piano.');
        }

        return $tenant->redirectToBillingPortal(route('billing'));
    }

    /** Tenant dell'utente autenticato. */
    private function tenant(Request $request): Tenant
    {
        return Tenant::findOrFail($request->user()->tenant_id);
    }

    /** L'utente è l'owner del tenant? Solo lui può gestire l'abbonamento. */
    private function isOwner(Request $request): bool
    {
        return $request->user()->hasRole(config('saas-core.access.owner_role', 'owner'));
    }

    /** Solo l'owner può fare upgrade/checkout/gestione abbonamento. */
    private function authorizeOwner(Request $request): void
    {
        abort_unless($this->isOwner($request), 403, "Solo l'owner dell'azienda può gestire l'abbonamento.");
    }

    /**
     * Unisce i posti/price_id (config saas-core) con i metadati di visualizzazione
     * (config knowledge.billing_plans) per i piani acquistabili (price_id presente).
     */
    private function plansForDisplay(): array
    {
        $plans   = (array) config('saas-core.billing.plans', []);
        $display = (array) config('knowledge.billing_plans', []);

        $out = [];
        foreach ($display as $key => $meta) {
            $out[] = [
                'key'      => $key,
                'seats'    => (int) ($plans[$key]['seats'] ?? 0),
                'buyable'  => ! empty($plans[$key]['price_id']),
                ...$meta,
            ];
        }

        return $out;
    }
}
