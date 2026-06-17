<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggancia i documenti alla pratica (nullable: un documento può non essere
 * ancora assegnato a una pratica). Cancellando la pratica i documenti restano,
 * ma vengono scollegati (nullOnDelete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('matter_id')->nullable()->after('tenant_id')
                ->constrained('matters')->nullOnDelete();

            $table->index(['tenant_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'matter_id']);
            $table->dropConstrainedForeignId('matter_id');
        });
    }
};
