<?php

namespace SaaS\Core\Access\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * RoleSeeder
 *
 * Crea i ruoli base dell'applicazione nel database.
 * I ruoli vengono letti da saas-core.php — ogni SaaS li personalizza.
 *
 * COME SI ESEGUE:
 *   php artisan db:seed --class=SaaS\\Core\\Access\\Seeders\\RoleSeeder
 *
 * IDEMPOTENTE:
 *   firstOrCreate() garantisce che eseguire il seeder più volte
 *   non crei duplicati. Sicuro da eseguire in deploy e CI/CD.
 *
 * GUARD:
 *   'web'     → ruoli per sessioni browser (Vue)
 *   'sanctum' → ruoli per token API (React Native)
 *   Creiamo entrambi per ogni ruolo così funziona sia web che mobile.
 *
 * DIPENDE DA: spatie/laravel-permission
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Legge i ruoli dalla config — default: ['admin', 'user']
        $roles = config('saas-core.access.roles', ['admin', 'user']);

        foreach ($roles as $roleName) {
            // Guard 'web': per l'autenticazione via sessione (browser/Vue)
            Role::firstOrCreate([
                'name'       => $roleName,
                'guard_name' => 'web',
            ]);

            // Guard 'sanctum': per l'autenticazione via token (React Native / API)
            Role::firstOrCreate([
                'name'       => $roleName,
                'guard_name' => 'sanctum',
            ]);
        }
    }
}
