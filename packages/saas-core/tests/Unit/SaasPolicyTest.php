<?php

use SaaS\Core\Access\Policies\SaasPolicy;

/**
 * Test unitari per SaasPolicy — base class per le Laravel Policy.
 *
 * Verifica i tre metodi helper protetti:
 *   sameTenant()     — stessa colonna tenant_id su user e model
 *   isSuperAdmin()   — ruolo 'super-admin' (bypassa tenant isolation)
 *   isTenantAdmin()  — ruolo in admin_roles (owner/admin del tenant)
 *
 * SETUP:
 *   Usiamo classi anonime per simulare user e model Eloquent senza DB.
 *   SaasPolicy è abstract, la testiamo con una sottoclasse anonima concreta.
 */

// Sottoclasse concreta di SaasPolicy che espone i metodi protected per i test
$concretePolicy = new class extends SaasPolicy {
    public function testSameTenant(mixed $user, \Illuminate\Database\Eloquent\Model $model): bool
    {
        return $this->sameTenant($user, $model);
    }

    public function testIsSuperAdmin(mixed $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function testIsTenantAdmin(mixed $user): bool
    {
        return $this->isTenantAdmin($user);
    }
};

// User stub con tenant_id e hasRole/hasAnyRole configurabili
function makeUserStub(int $tenantId, array $roles = []): object
{
    return new class($tenantId, $roles) {
        public function __construct(
            public readonly int $tenant_id,
            private readonly array $roles
        ) {}

        public function hasRole(string $role): bool
        {
            return in_array($role, $this->roles, true);
        }

        public function hasAnyRole(array $roles): bool
        {
            return ! empty(array_intersect($roles, $this->roles));
        }
    };
}

// Model stub con tenant_id configurabile
function makeModelStub(int $tenantId): \Illuminate\Database\Eloquent\Model
{
    $model = new class extends \Illuminate\Database\Eloquent\Model {};
    $model->tenant_id = $tenantId;
    return $model;
}

// User stub senza il campo tenant_id (entità globale non configurata)
function makeUserStubNoTenant(): object
{
    return new class {
        // Nessuna proprietà tenant_id
    };
}

// ---------------------------------------------------------------------------
// sameTenant()
// ---------------------------------------------------------------------------

it('sameTenant restituisce true se user e model hanno lo stesso tenant_id', function () use ($concretePolicy) {
    $user  = makeUserStub(tenantId: 42);
    $model = makeModelStub(tenantId: 42);

    expect($concretePolicy->testSameTenant($user, $model))->toBeTrue();
});

it('sameTenant restituisce false se i tenant_id sono diversi', function () use ($concretePolicy) {
    $user  = makeUserStub(tenantId: 1);
    $model = makeModelStub(tenantId: 99);

    expect($concretePolicy->testSameTenant($user, $model))->toBeFalse();
});

it('sameTenant restituisce false se user non ha tenant_id', function () use ($concretePolicy) {
    $user  = makeUserStubNoTenant();
    $model = makeModelStub(tenantId: 1);

    expect($concretePolicy->testSameTenant($user, $model))->toBeFalse();
});

it('sameTenant restituisce false se model non ha tenant_id', function () use ($concretePolicy) {
    $user  = makeUserStub(tenantId: 1);
    $model = new class extends \Illuminate\Database\Eloquent\Model {}; // nessun tenant_id

    expect($concretePolicy->testSameTenant($user, $model))->toBeFalse();
});

it('sameTenant usa il nome colonna da config tenancy.column', function () use ($concretePolicy) {
    config(['saas-core.tenancy.column' => 'tenant_id']); // default, ma esplicitiamo

    $user  = makeUserStub(tenantId: 7);
    $model = makeModelStub(tenantId: 7);

    expect($concretePolicy->testSameTenant($user, $model))->toBeTrue();
});

// ---------------------------------------------------------------------------
// isSuperAdmin()
// ---------------------------------------------------------------------------

it('isSuperAdmin restituisce true se utente ha il ruolo super-admin', function () use ($concretePolicy) {
    config(['saas-core.access.super_admin_role' => 'super-admin']);

    $user = makeUserStub(tenantId: 1, roles: ['super-admin']);

    expect($concretePolicy->testIsSuperAdmin($user))->toBeTrue();
});

it('isSuperAdmin restituisce false se utente ha un ruolo normale', function () use ($concretePolicy) {
    $user = makeUserStub(tenantId: 1, roles: ['admin']);

    expect($concretePolicy->testIsSuperAdmin($user))->toBeFalse();
});

it('isSuperAdmin restituisce false se user non ha il metodo hasRole', function () use ($concretePolicy) {
    // Oggetto senza hasRole() — non lancia eccezione, restituisce false
    $user = new stdClass();

    expect($concretePolicy->testIsSuperAdmin($user))->toBeFalse();
});

// ---------------------------------------------------------------------------
// isTenantAdmin()
// ---------------------------------------------------------------------------

it('isTenantAdmin restituisce true se utente ha il ruolo admin', function () use ($concretePolicy) {
    config(['saas-core.access.admin_roles' => ['admin', 'owner']]);

    $user = makeUserStub(tenantId: 1, roles: ['admin']);

    expect($concretePolicy->testIsTenantAdmin($user))->toBeTrue();
});

it('isTenantAdmin restituisce true se utente ha il ruolo owner', function () use ($concretePolicy) {
    config(['saas-core.access.admin_roles' => ['admin', 'owner']]);

    $user = makeUserStub(tenantId: 1, roles: ['owner']);

    expect($concretePolicy->testIsTenantAdmin($user))->toBeTrue();
});

it('isTenantAdmin restituisce false se utente ha solo il ruolo user', function () use ($concretePolicy) {
    config(['saas-core.access.admin_roles' => ['admin', 'owner']]);

    $user = makeUserStub(tenantId: 1, roles: ['user']);

    expect($concretePolicy->testIsTenantAdmin($user))->toBeFalse();
});
