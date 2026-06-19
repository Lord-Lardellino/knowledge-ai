<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Numero di chunk già embeddati: alimenta la barra di avanzamento
     * dell'indicizzazione (embedded_chunks / chunk_count).
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('embedded_chunks')->default(0)->after('chunk_count');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('embedded_chunks');
        });
    }
};
