<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * document_chunks — pezzi di testo estratti da un documento, con embedding.
 *
 * Ogni documento viene spezzato in chunk; ogni chunk ha il suo vettore
 * (Gemini Embedding 2, troncato a 1536 dim per stare sotto il limite indici pgvector).
 * La ricerca semantica usa l'operatore di distanza coseno con indice HNSW.
 *
 * NB: il tipo `vector` e l'indice HNSW non sono supportati dallo Schema builder
 * di Laravel → si creano con DB::statement (richiede l'estensione pgvector).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();

            $table->unsignedInteger('chunk_index'); // ordine del chunk nel documento
            $table->text('content');                // testo del chunk
            $table->unsignedInteger('token_count')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'document_id']);
            $table->unique(['document_id', 'chunk_index']);
        });

        // Colonna vettoriale (1536 dim) + indice HNSW per distanza coseno.
        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(1536)');
        DB::statement('CREATE INDEX document_chunks_embedding_hnsw ON document_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
