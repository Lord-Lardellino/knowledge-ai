<?php

namespace SaaS\Core\Tenancy;

use Illuminate\Support\Str;
use SaaS\Core\Tenancy\Models\Tenant;
use SaaS\Core\Tenancy\Models\TenantInvite;

/**
 * TenantOnboarding — creazione tenant e gestione inviti
 *
 * DUE MODI PER ENTRARE IN UN TENANT:
 *
 *   1. SELF-SIGNUP (il primo utente crea l'azienda):
 *      Registrazione con "company" → createTenant() genera il tenant,
 *      l'utente diventa owner. Il sottodominio deriva dallo slug:
 *      "Rossi SRL" → rossi-srl.tuosaas.com
 *
 *   2. INVITO (i colleghi entrano in un tenant esistente):
 *      Un admin/owner chiama invite() → email con token →
 *      l'invitato si registra passando il token → acceptInvite()
 *      lo aggancia al tenant con il ruolo stabilito dall'invito.
 *
 * Il token di invito è restituito in chiaro UNA SOLA VOLTA da invite()
 * (per spedirlo via email); nel DB resta solo l'hash SHA-256.
 */
class TenantOnboarding
{
    /**
     * Crea un nuovo tenant per self-signup.
     * Lo slug è derivato dal nome ed è garantito univoco ("rossi-srl",
     * "rossi-srl-2", ...). Parole riservate (www, api, ...) sono escluse.
     */
    public function createTenant(string $companyName): Tenant
    {
        $model = config('saas-core.tenancy.model', Tenant::class);

        return $model::create([
            'name' => $companyName,
            'slug' => $this->uniqueSlug($companyName),
        ]);
    }

    /**
     * Aggancia un utente al tenant come owner (primo utente / fondatore).
     * Assegna il ruolo configurato in saas-core.access.owner_role.
     */
    public function attachOwner($user, Tenant $tenant): void
    {
        $user->forceFill([config('saas-core.tenancy.column', 'tenant_id') => $tenant->id])->save();

        $role = config('saas-core.access.owner_role', 'owner');
        if (method_exists($user, 'assignRole')) {
            $user->assignRole($role);
        }
    }

    /**
     * Crea un invito e restituisce ['invite' => ..., 'token' => ...].
     * Il token in chiaro esiste solo nel valore di ritorno: va spedito
     * via email subito, non è più recuperabile.
     *
     * Un invito pendente precedente per la stessa email viene sostituito
     * (re-invio = nuovo token, vecchio link invalidato).
     */
    public function invite(Tenant $tenant, string $email, string $role, ?int $invitedById = null): array
    {
        $token = Str::random(48);

        // updateOrCreate: re-invitare la stessa email rigenera il token
        // invece di violare la unique(tenant_id, email).
        $invite = TenantInvite::updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $email],
            [
                'role'        => $role,
                'token'       => hash('sha256', $token),
                'invited_by'  => $invitedById,
                'expires_at'  => now()->addDays((int) config('saas-core.tenancy.invite_ttl_days', 7)),
                'accepted_at' => null,
            ]
        );

        return ['invite' => $invite, 'token' => $token];
    }

    /**
     * Trova un invito pendente a partire dal token in chiaro ricevuto dal client.
     * Restituisce null se il token non esiste, è scaduto o già usato.
     */
    public function findPendingInvite(string $token): ?TenantInvite
    {
        return TenantInvite::pending()
            ->where('token', hash('sha256', $token))
            ->first();
    }

    /**
     * Completa l'invito: aggancia l'utente al tenant, assegna il ruolo,
     * marca l'invito come accettato.
     */
    public function acceptInvite(TenantInvite $invite, $user): void
    {
        $user->forceFill([config('saas-core.tenancy.column', 'tenant_id') => $invite->tenant_id])->save();

        if (method_exists($user, 'assignRole')) {
            $user->assignRole($invite->role);
        }

        $invite->forceFill(['accepted_at' => now()])->save();
    }

    /**
     * Slug univoco e valido come sottodominio.
     */
    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        // Slug vuoto (es. nome solo simboli) o riservato → fallback neutro
        if ($base === '' || in_array($base, $this->reservedSlugs(), true)) {
            $base = 'azienda';
        }

        $model = config('saas-core.tenancy.model', Tenant::class);
        $slug = $base;
        $i = 2;
        while ($model::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /**
     * Sottodomini riservati all'infrastruttura: un tenant non può chiamarsi così.
     */
    protected function reservedSlugs(): array
    {
        return config('saas-core.tenancy.reserved_slugs', [
            'www', 'api', 'app', 'admin', 'mail', 'smtp', 'ftp', 'staging', 'dev', 'test',
        ]);
    }
}
