<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * matter_types — materie/aree di una pratica legale (lavoro, civile, penale, ...).
 *
 * Enum-now / custom-later: le righe di SISTEMA hanno tenant_id NULL e is_system=true,
 * sono condivise da tutti gli studi. In futuro uno studio potrà aggiungere le proprie
 * materie inserendo righe con il proprio tenant_id, senza migrazioni di dati.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_types', function (Blueprint $table) {
            $table->id();
            // NULL = materia di sistema (condivisa da tutti i tenant).
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();

            $table->string('key');               // slug stabile, es. "recupero_crediti"
            $table->string('label');             // etichetta visualizzata
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort')->default(0);

            $table->timestamps();

            // Una materia è unica per (tenant, key); le righe di sistema (tenant NULL)
            // sono comunque uniche per key grazie al comportamento di Postgres su NULL.
            $table->unique(['tenant_id', 'key']);
        });

        $now = now();
        $systemTypes = [
            ['key' => 'lavoro',           'label' => 'Diritto del lavoro'],
            ['key' => 'civile',           'label' => 'Civile'],
            ['key' => 'societario',       'label' => 'Societario'],
            ['key' => 'recupero_crediti', 'label' => 'Recupero crediti'],
            ['key' => 'famiglia',         'label' => 'Famiglia'],
            ['key' => 'penale',           'label' => 'Penale'],
            ['key' => 'tributario',       'label' => 'Tributario'],
        ];

        DB::table('matter_types')->insert(
            array_map(fn ($t, $i) => [
                'tenant_id'  => null,
                'key'        => $t['key'],
                'label'      => $t['label'],
                'is_system'  => true,
                'sort'       => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ], $systemTypes, array_keys($systemTypes))
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_types');
    }
};
