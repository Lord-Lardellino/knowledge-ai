<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: add_totp_to_users_table
 *
 * Aggiunge le colonne necessarie per il TOTP (Time-based One-Time Password)
 * alla tabella users.
 *
 * COLONNE:
 *   two_factor_secret        — Segreto TOTP cifrato con APP_KEY (base32, 16 byte).
 *                              Null = TOTP non configurato per questo utente.
 *   two_factor_confirmed_at  — Timestamp di quando l'utente ha verificato il TOTP
 *                              per la prima volta. Null = TOTP non ancora confermato
 *                              (segreto generato ma non validato dall'utente).
 *   two_factor_recovery_codes — Codici di recovery cifrati (JSON array).
 *                              Usati se l'utente perde l'accesso all'app TOTP.
 *
 * PERCHÉ 'confirmed_at' E NON UN BOOLEANO:
 *   Il timestamp permette di sapere DA QUANDO l'utente ha il TOTP attivo.
 *   Utile per audit, compliance, e per mostrare "attivato il gg/mm/aaaa" nell'UI.
 *
 * COMPATIBILITÀ:
 *   Schema::hasColumn() è cross-driver — funziona su MySQL, PostgreSQL, SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                // TEXT perché il segreto cifrato è più lungo di 255 char
                $table->text('two_factor_secret')->nullable()->after('password');
            }

            if (! Schema::hasColumn('users', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            }

            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
