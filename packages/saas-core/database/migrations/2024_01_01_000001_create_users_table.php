<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_users_table
 *
 * Crea la tabella users con le colonne aggiuntive del package.
 * Se la tabella esiste già (progetto Laravel standard), aggiunge solo
 * le colonne mancanti tramite Schema::hasColumn() — compatibile con
 * MySQL 5.7/8.0, PostgreSQL e SQLite (ALTER TABLE IF NOT EXISTS è
 * sintassi PostgreSQL-only e non funziona sugli altri driver).
 *
 * Colonne aggiunte rispetto al default Laravel:
 *   - tenant_id:  isola gli utenti per tenant (usato da TenantScope)
 *   - deleted_at: soft delete per GDPR (GdprEraser anonimizza, non cancella)
 *   - password:   nullable — con le passkey non serve una password tradizionale
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            // Tabella non esiste: la creiamo da zero con tutte le colonne
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                // Nullable: con le passkey non serve una password tradizionale
                $table->string('password')->nullable();
                $table->rememberToken();
                $table->timestamps();
                // SoftDelete per GDPR
                $table->softDeletes();
            });

            return;
        }

        // Tabella già esistente: aggiunge le colonne mancanti in modo idempotente.
        // Schema::hasColumn() è cross-driver (MySQL, PostgreSQL, SQLite).
        if (! Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
            });
        }

        if (! Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // Rende password nullable: con le passkey non serve una password tradizionale.
        // Il default Laravel crea password NOT NULL — lo correggiamo qui.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
