<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use SaaS\Core\Billing\Exceptions\SeatLimitExceededException;
use SaaS\Core\Billing\TenantBilling;
use SaaS\Core\Tenancy\Models\Tenant;
use SaaS\Core\Tenancy\Models\TenantInvite;
use SaaS\Core\Tenancy\TenantInviteNotification;
use SaaS\Core\Tenancy\TenantOnboarding;

/**
 * TeamController — gestione utenti del tenant (membri + inviti).
 *
 * Solo owner/admin possono invitare/revocare. Gli inviti rispettano il limite
 * di posti del piano (TenantOnboarding::invite lancia SeatLimitExceededException).
 *
 * NB: si usa il middleware app 'tenant.user' (tenant legato all'utente loggato),
 * non il 'tenant' di saas-core che risolve dal sottodominio.
 */
class TeamController extends Controller
{
    public function __construct(
        private TenantOnboarding $onboarding,
        private TenantBilling $billing,
    ) {
    }

    /** Pagina team: membri, inviti pendenti, stato posti. */
    public function page(Request $request): InertiaResponse
    {
        $tenant = $this->tenant($request);

        return Inertia::render('Team/Index', [
            'auth' => [
                'user'   => $request->user(),
                'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            ],
            'members'        => $this->members($tenant),
            'invites'        => $this->pendingInvites($tenant),
            'seatsUsed'      => $this->billing->seatsUsed($tenant),
            'seatLimit'      => $this->billing->seatLimit($tenant),
            'invitableRoles' => config('saas-core.access.invitable_roles', ['admin', 'user']),
        ]);
    }

    /** Crea un invito → email (best effort) + link da condividere. */
    public function invite(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $tenant = $this->tenant($request);

        $invitableRoles = config('saas-core.access.invitable_roles', ['admin', 'user']);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role'  => ['required', 'string', 'in:' . implode(',', $invitableRoles)],
        ]);

        // Già membro del tenant?
        $existing = User::where('email', $validated['email'])->first();
        if ($existing && $existing->tenant_id === $tenant->id) {
            return response()->json(['message' => 'Questo utente fa già parte del team.'], 422);
        }

        try {
            ['invite' => $invite, 'token' => $token] = $this->onboarding->invite(
                $tenant,
                $validated['email'],
                $validated['role'],
                $request->user()->id,
            );
        } catch (SeatLimitExceededException $e) {
            // Posti esauriti: invito bloccato, suggerisci l'upgrade.
            return response()->json([
                'message'      => $e->getMessage(),
                'seats_full'   => true,
            ], 422);
        }

        // Invio email (in produzione può essere su 'log': il link sotto è il fallback).
        Notification::route('mail', $invite->email)
            ->notify(new TenantInviteNotification($tenant, $token));

        // Link da condividere a mano (utile se l'email non è configurata).
        $link = rtrim(config('app.url'), '/') . '/register?invite=' . $token;

        return response()->json([
            'message' => "Invito creato per {$invite->email}.",
            'link'    => $link,
            'invite'  => $invite->only(['id', 'email', 'role', 'expires_at']),
        ], 201);
    }

    /** Revoca un invito pendente. */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        $tenant = $this->tenant($request);

        $deleted = TenantInvite::pending()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->delete();

        return response()->json(
            $deleted ? ['message' => 'Invito revocato.'] : ['message' => 'Invito non trovato.'],
            $deleted ? 200 : 404
        );
    }

    private function tenant(Request $request): Tenant
    {
        return Tenant::findOrFail($request->user()->tenant_id);
    }

    /** Solo owner/admin del tenant possono gestire il team. */
    private function authorizeAdmin(Request $request): void
    {
        $adminRoles = config('saas-core.access.admin_roles', ['admin', 'owner']);
        abort_unless($request->user()->hasAnyRole($adminRoles), 403, 'Operazione riservata agli amministratori.');
    }

    /** Membri attuali del tenant. */
    private function members(Tenant $tenant): array
    {
        return User::where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'role'  => $u->getRoleNames()->first() ?? 'user',
            ])
            ->all();
    }

    /** Inviti pendenti del tenant. */
    private function pendingInvites(Tenant $tenant): array
    {
        return TenantInvite::pending()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->get(['id', 'email', 'role', 'expires_at'])
            ->all();
    }
}
