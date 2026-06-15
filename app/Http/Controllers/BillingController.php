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

        return Inertia::render('Billing/Index', [
            'auth' => [
                'user'   => $request->user(),
                'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            ],
            'plans'        => $this->plansForDisplay(),
            'currentPlan'  => $this->billing->planFor($tenant),
            'onTrial'      => $this->billing->onTrial($tenant),
            'trialDaysLeft'=> $this->billing->trialDaysLeft($tenant),
            'seatsUsed'    => $this->billing->seatsUsed($tenant),
            'seatLimit'    => $this->billing->seatLimit($tenant),
        ]);
    }

    /** Avvia il checkout Stripe ospitato per il piano scelto. */
    public function checkout(Request $request, string $plan): Response
    {
        $tenant  = $this->tenant($request);
        $priceId = config("saas-core.billing.plans.{$plan}.price_id");

        if (empty($priceId)) {
            return back()->with('error', 'Piano non valido o non acquistabile.');
        }

        $name = config('saas-core.billing.subscription_name', 'default');

        // Se ha già un abbonamento attivo, il cambio piano è uno swap (con guard
        // sul downgrade), non un nuovo checkout.
        if ($tenant->subscribed($name)) {
            return $this->swap($request, $plan);
        }

        $checkout = $tenant->newSubscription($name, $priceId)->checkout([
            'success_url' => route('billing.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => route('billing'),
        ]);

        // Redirect full-page verso Stripe (necessario in contesto Inertia).
        return Inertia::location($checkout->url);
    }

    /** Cambia piano su un abbonamento esistente (con guard downgrade). */
    public function swap(Request $request, string $plan): RedirectResponse
    {
        $tenant = $this->tenant($request);

        try {
            $this->billing->swapTo($tenant, $plan);
        } catch (PlanDowngradeBlockedException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing')->with('success', 'Piano aggiornato.');
    }

    /** Ritorno dal checkout Stripe riuscito. */
    public function success(): RedirectResponse
    {
        return redirect()->route('dashboard')->with('success', 'Abbonamento attivato. Benvenuto!');
    }

    /** Portale Stripe per gestire abbonamento, metodi di pagamento, fatture. */
    public function portal(Request $request): RedirectResponse
    {
        return $this->tenant($request)->redirectToBillingPortal(route('billing'));
    }

    /** Tenant dell'utente autenticato. */
    private function tenant(Request $request): Tenant
    {
        return Tenant::findOrFail($request->user()->tenant_id);
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
