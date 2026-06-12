<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_personal_access_tokens_table
 *
 * Tabella richiesta da Laravel Sanctum per memorizzare i token API.
 * Ogni riga rappresenta un token emesso: access token o refresh token.
 *
 * COLONNE CHIAVE:
 *   tokenable_type + tokenable_id → polimorfismo: il token appartiene a qualsiasi Model
 *   name     → identifica il device (es. "access:uuid-del-device")
 *   token    → hash SHA-256 del token (il valore originale non viene mai salvato)
 *   abilities → array JSON con le permission del token: ['access'] o ['refresh']
 *   expires_at → scadenza esplicita, controllata da Sanctum ad ogni request
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');           // tokenable_type + tokenable_id
            $table->string('name');                // es. "access:device-uuid"
            $table->string('token', 64)->unique(); // hash SHA-256
            $table->text('abilities')->nullable(); // JSON: ['access'] o ['refresh']
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
