<?php

use SaaS\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Route;

/**
 * Test di feature per il middleware SetTenant.
 *
 * Registra route di test con il middleware 'tenant' applicato,
 * poi verifica che il tenant venga risolto correttamente dal sottodominio.
 */

beforeEach(function () {
    // Registra una route di test che usa il middleware tenant
    // e restituisce il tenant_id corrente in JSON
    Route::middleware('tenant')->get('/test-tenant', function () {
        $tenantId = app('current.tenant.id');
        return response()->json(['tenant_id' => $tenantId]);
    });
});

it('risolve il tenant dal sottodominio e lo mette nel container', function () {
    $tenant = Tenant::create([
        'name'   => 'Acme Corp',
        'slug'   => 'acme',
        'active' => true,
    ]);

    $response = $this->get('http://acme.localhost/test-tenant');

    $response->assertStatus(200)
        ->assertJson(['tenant_id' => $tenant->id]);
});

it('restituisce 404 per un tenant non esistente', function () {
    // Nessun tenant con slug "ghost" nel DB
    $this->get('http://ghost.localhost/test-tenant')
        ->assertStatus(404);
});

it('lascia passare la request senza tenant sul dominio root', function () {
    // Nessun sottodominio → resolver restituisce null → middleware lascia passare
    $response = $this->get('http://localhost/test-tenant');

    $response->assertStatus(200)
        ->assertJson(['tenant_id' => null]);
});
