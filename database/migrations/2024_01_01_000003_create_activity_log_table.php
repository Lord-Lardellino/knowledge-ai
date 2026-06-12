<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_activity_log_table
 *
 * Tabella richiesta da spatie/laravel-activitylog.
 * Ogni riga è un evento loggato dal trait Auditable.
 *
 * COLONNE CHIAVE:
 *   log_name       → tipo di Model loggato (es. "Invoice", "User")
 *   description    → evento: "created", "updated", "deleted", "user.gdpr_erased"
 *   subject_*      → il Model modificato (polimorfismo)
 *   causer_*       → chi ha fatto la modifica (utente o null per sistema)
 *   properties     → JSON con i valori before/after della modifica
 *   event          → alias di description, usato da Spatie v4+
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_log')) {
            return;
        }

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');    // subject_type + subject_id
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');      // causer_type + causer_id
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
