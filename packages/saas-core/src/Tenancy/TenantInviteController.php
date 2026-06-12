<?php

namespace SaaS\Core\Tenancy;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Notification;
use SaaS\Core\Tenancy\Models\TenantInvite;
use SaaS\Core\Tenancy\TenantInviteNotification;

/**
 * TenantInviteController
 *
 * Gestione inviti al tenant corrente. Tutte le route richiedono:
 *   - utente autenticato
 *   - tenant risolto (middleware 'tenant')
 *   - ruolo admin del tenant (middleware 'role:owner|admin')
 *
 * Route (vedi routes/tenancy.php):
 *   GET    /tenant/invites        → lista inviti pendenti
 *   POST   /tenant/invites        → crea invito + invia email
 *   DELETE /tenant/invites/{id}   → revoca invito pendente
 */
class TenantInviteController extends Controller
{
    public function __construct(
        private readonly TenantOnboarding $onboarding,
    ) {}

    public function index(): JsonResponse
    {
        $tenant = app('current.tenant');

        return response()->json(
            TenantInvite::pending()
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('created_at')
                ->get(['id', 'email', 'role', 'expires_at', 'created_at'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = app('current.tenant');

        // I ruoli invitabili sono quelli configurati, MAI owner/super-admin:
        // l'owner è solo chi crea il tenant.
        $invitableRoles = config('saas-core.access.invitable_roles', ['admin', 'user']);

        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role'  => ['required', 'string', 'in:' . implode(',', $invitableRoles)],
        ]);

        $userModel = config('auth.providers.users.model');
        $existing  = $userModel::where('email', $request->input('email'))->first();

        if ($existing && $existing->tenant_id === $tenant->id) {
            return response()->json(['message' => 'Questo utente fa già parte del tenant.'], 422);
        }

        ['invite' => $invite, 'token' => $token] = $this->onboarding->invite(
            $tenant,
            $request->input('email'),
            $request->input('role'),
            $request->user()?->id,
        );

        Notification::route('mail', $invite->email)
            ->notify(new TenantInviteNotification($tenant, $token));

        return response()->json([
            'message' => 'Invito inviato a ' . $invite->email . '.',
            'invite'  => $invite->only(['id', 'email', 'role', 'expires_at']),
        ], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $tenant = app('current.tenant');

        $deleted = TenantInvite::pending()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Invito non trovato.'], 404);
        }

        return response()->json(['message' => 'Invito revocato.']);
    }
}
