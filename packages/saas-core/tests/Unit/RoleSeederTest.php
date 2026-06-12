<?php

use SaaS\Core\Access\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

/**
 * Test per RoleSeeder.
 *
 * Verifica che i ruoli vengano creati correttamente nel DB
 * per entrambi i guard (web e sanctum).
 */

it('crea i ruoli di default per entrambi i guard', function () {
    (new RoleSeeder())->run();

    // Guard web (sessioni browser)
    expect(Role::where('name', 'admin')->where('guard_name', 'web')->exists())->toBeTrue();
    expect(Role::where('name', 'user')->where('guard_name', 'web')->exists())->toBeTrue();

    // Guard sanctum (token API / React Native)
    expect(Role::where('name', 'admin')->where('guard_name', 'sanctum')->exists())->toBeTrue();
    expect(Role::where('name', 'user')->where('guard_name', 'sanctum')->exists())->toBeTrue();
});

it('è idempotente: eseguire il seeder più volte non crea duplicati', function () {
    (new RoleSeeder())->run();
    (new RoleSeeder())->run();
    (new RoleSeeder())->run();

    // 2 ruoli × 2 guard = 4 righe totali, non 12
    expect(Role::count())->toBe(4);
});

it('crea i ruoli configurati in saas-core.php', function () {
    // Sovrascrive la config per questo test
    config(['saas-core.access.roles' => ['owner', 'captain', 'crew']]);

    (new RoleSeeder())->run();

    foreach (['owner', 'captain', 'crew'] as $role) {
        expect(Role::where('name', $role)->where('guard_name', 'web')->exists())->toBeTrue();
        expect(Role::where('name', $role)->where('guard_name', 'sanctum')->exists())->toBeTrue();
    }
});
