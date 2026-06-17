<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadati legali estratti dall'AI (Fase 2 del verticale legale).
 *
 * metadata           → JSONB: tipo doc, parti, date, importi, citazioni, clausole, rischi.
 * metadata_status    → pending → processing → ready → confirmed | failed
 * metadata_reviewed_at → quando l'avvocato ha confermato/corretto (fiducia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->jsonb('metadata')->nullable()->after('chunk_count');
            $table->string('metadata_status', 20)->default('pending')->after('metadata');
            $table->text('metadata_error')->nullable()->after('metadata_status');
            $table->timestamp('metadata_reviewed_at')->nullable()->after('metadata_error');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['metadata', 'metadata_status', 'metadata_error', 'metadata_reviewed_at']);
        });
    }
};
