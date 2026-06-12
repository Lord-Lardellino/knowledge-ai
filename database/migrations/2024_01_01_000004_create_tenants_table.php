<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_tenants_table
 *
 * Tabella centrale della tenancy: ogni riga è un cliente del SaaS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenants')) {
            return;
        }

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');                    // "Acme Corporation"
            $table->string('slug')->unique();          // "acme" — usato nei sottodomini
            $table->string('plan')->default('free');   // piano abbonamento
            $table->boolean('active')->default(true);  // false = accesso bloccato
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
