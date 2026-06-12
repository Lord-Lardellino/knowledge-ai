<?php

namespace SaaS\Core\Access\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;

/**
 * SaasPolicy — Base class per tutte le Laravel Policy del package e dei SaaS consumer.
 *
 * PERCHÉ ESISTE:
 *   Ogni SaaS ha decine di Policy (InvoicePolicy, UserPolicy, ecc.).
 *   Senza una base class, ogni Policy deve reimplementare da zero i check
 *   più comuni: "l'utente appartiene allo stesso tenant?", "è un super-admin?".
 *   Questa classe centralizza quei check e li rende riusabili in un'unica riga.
 *
 * COME USARLA:
 *   class InvoicePolicy extends SaasPolicy
 *   {
 *       public function view(User $user, Invoice $invoice): bool
 *       {
 *           return $this->sameTenant($user, $invoice)
 *               && $user->can('view invoices');
 *       }
 *   }
 *
 * MULTI-TENANCY:
 *   sameTenant() confronta $user->tenant_id con $model->tenant_id.
 *   Il nome della colonna si legge da 'saas-core.tenancy.column' (default: 'tenant_id').
 *   Se uno dei due non ha tenant_id (es. entità globali), restituisce false per sicurezza.
 *
 * SUPER-ADMIN:
 *   isSuperAdmin() usa spatie/laravel-permission: controlla se l'utente ha il ruolo
 *   definito in 'saas-core.access.super_admin_role' (default: 'super-admin').
 *   I super-admin bypassa tutti i check di tenant isolation — usare con cautela.
 */
abstract class SaasPolicy
{
    use HandlesAuthorization;

    /**
     * Verifica che l'utente appartenga allo stesso tenant del modello.
     *
     * SICUREZZA: senza questo check un utente potrebbe accedere ai dati
     * di un altro tenant se indovina o indovina l'ID della risorsa.
     */
    protected function sameTenant(mixed $user, Model $model): bool
    {
        $column = config('saas-core.tenancy.column', 'tenant_id');

        // Se uno dei due non ha il campo tenant_id, nega per default.
        // Evita falsi positivi su modelli globali non ancora configurati.
        if (! isset($user->{$column}) || ! isset($model->{$column})) {
            return false;
        }

        return (int) $user->{$column} === (int) $model->{$column};
    }

    /**
     * Verifica se l'utente è super-admin (bypass completo di tutti i check).
     *
     * ATTENZIONE: usare solo per operazioni di supporto/amministrazione globale.
     * I super-admin vedono i dati di TUTTI i tenant.
     */
    protected function isSuperAdmin(mixed $user): bool
    {
        $role = config('saas-core.access.super_admin_role', 'super-admin');

        return method_exists($user, 'hasRole') && $user->hasRole($role);
    }

    /**
     * Verifica che l'utente sia owner o admin del proprio tenant.
     *
     * Utile per operazioni riservate agli amministratori del SaaS
     * (es. gestione utenti, billing, configurazione tenant).
     */
    protected function isTenantAdmin(mixed $user): bool
    {
        if (! method_exists($user, 'hasAnyRole')) {
            return false;
        }

        $adminRoles = config('saas-core.access.admin_roles', ['admin', 'owner']);

        return $user->hasAnyRole($adminRoles);
    }
}
