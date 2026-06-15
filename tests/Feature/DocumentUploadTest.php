<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use SaaS\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('saas-core.billing.enabled', false);
    }

    private function userInTenant(string $tenantName): User
    {
        $tenant = Tenant::create(['name' => $tenantName, 'slug' => str()->slug($tenantName)]);

        return User::create([
            'name'      => 'Tester',
            'email'     => $tenantName . '@example.com',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_upload_crea_documento_pending_e_avvia_pipeline(): void
    {
        Bus::fake();
        Storage::fake('local');
        $user = $this->userInTenant('acme');

        $response = $this->actingAs($user)->postJson('/documents', [
            'file' => UploadedFile::fake()->create('manuale.pdf', 200, 'application/pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', Document::STATUS_PENDING)
            ->assertJsonPath('original_filename', 'manuale.pdf');

        $doc = Document::withoutGlobalScopes()->first();
        $this->assertSame($user->tenant_id, $doc->tenant_id);
        $this->assertSame($user->id, $doc->uploaded_by);
        Storage::disk('local')->assertExists($doc->path);
        Bus::assertDispatched(ProcessDocument::class, fn ($job) => $job->documentId === $doc->id);
    }

    public function test_rifiuta_formato_non_supportato(): void
    {
        Storage::fake('local');
        $user = $this->userInTenant('acme');

        $this->actingAs($user)->postJson('/documents', [
            'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_un_tenant_non_vede_i_documenti_di_un_altro(): void
    {
        Storage::fake('local');
        Bus::fake();

        $acme = $this->userInTenant('acme');
        $globex = $this->userInTenant('globex');

        $this->actingAs($acme)->postJson('/documents', [
            'file' => UploadedFile::fake()->create('acme-doc.pdf', 50, 'application/pdf'),
        ])->assertCreated();

        // globex non vede nulla
        $this->actingAs($globex)->getJson('/documents')
            ->assertOk()
            ->assertJsonPath('total', 0);

        // e non può accedere al documento di acme (404 per via del TenantScope)
        $acmeDoc = Document::withoutGlobalScopes()->first();
        $this->actingAs($globex)->getJson("/documents/{$acmeDoc->id}")->assertNotFound();
    }
}
