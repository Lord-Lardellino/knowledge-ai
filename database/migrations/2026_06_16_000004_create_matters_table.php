<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matters — la PRATICA: oggetto centrale del verticale legale, isolato per tenant.
 *
 * Stati (status): open | suspended | closed | archived.
 * La materia punta a matter_types (sistema o tenant). L'esito e il valore sono
 * popolati a pratica chiusa e servono alla funzione "pratiche simili" (fase 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('matter_type_id')->nullable()->constrained('matter_types')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reference')->nullable(); // codice interno pratica
            $table->string('title');
            $table->string('status', 20)->default('open');

            $table->text('outcome')->nullable();             // esito (a pratica chiusa)
            $table->unsignedBigInteger('value_cents')->nullable(); // valore economico

            $table->date('opened_at')->nullable();
            $table->date('closed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matters');
    }
};
