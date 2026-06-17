<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matter_party — pivot pratica ↔ controparte, con il ruolo nella pratica.
 *
 * tenant_id ridondante ma utile: tiene il filtro tenant coerente anche sul pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_party', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();

            $table->string('role', 30)->nullable(); // attore | convenuto | terzo | ...

            $table->timestamps();

            $table->unique(['matter_id', 'party_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_party');
    }
};
