<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_tenant_invites_table
 *
 * Inviti per aggiungere utenti a un tenant esistente.
 *
 * FLUSSO:
 *   1. Un admin/owner del tenant crea l'invito (email + ruolo)
 *   2. L'invitato riceve una email con un link contenente il token
 *   3. L'invitato si registra con passkey passando il token
 *   4. Alla registrazione: utente agganciato al tenant, ruolo assegnato,
 *      invito marcato come accettato
 *
 * SICUREZZA:
 *   - Il token è salvato hashato (SHA-256): un dump del DB non permette
 *     di impersonare gli invitati.
 *   - expires_at: gli inviti scadono (default 7 giorni, configurabile).
 *   - unique(tenant_id, email): un solo invito pendente per email per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_invites')) {
            return;
        }

        Schema::create('tenant_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email');
            $table->string('role')->default('user');     // ruolo assegnato all'accettazione
            $table->string('token', 64)->unique();        // SHA-256 del token inviato via email
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invites');
    }
};
