<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * documents — file caricati nella knowledge base, isolati per tenant.
 *
 * Il file binario resta su disco (Storage::disk()); qui salviamo solo metadati,
 * percorso e stato di processing della pipeline di indicizzazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');                 // nome visualizzato (default: nome file)
            $table->string('original_filename');     // nome file originale caricato
            $table->string('disk')->default('local');// disco Storage usato
            $table->string('path');                  // percorso relativo sul disco
            $table->string('mime_type');             // es. application/pdf
            $table->string('extension', 16);         // pdf, docx, xlsx, txt
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64)->nullable(); // sha256 per dedup futura

            // Stato pipeline: pending → processing → indexed | failed
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();       // messaggio in caso di failed
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamp('indexed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
