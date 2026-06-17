<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Document — file caricato nella knowledge base, isolato per tenant.
 *
 * Stati (status):
 *   pending    → caricato, in attesa di processing
 *   processing → parsing/chunking/embedding in corso (job in coda)
 *   indexed    → testo estratto e chunk indicizzati: ricercabile
 *   failed     → errore in pipeline (vedi colonna error)
 */
class Document extends Model
{
    use HasTenant;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_INDEXED    = 'indexed';
    public const STATUS_FAILED     = 'failed';

    // Stato estrazione metadati legali (Fase 2 verticale legale).
    public const META_PENDING    = 'pending';
    public const META_PROCESSING = 'processing';
    public const META_READY      = 'ready';      // estratti, in attesa di revisione
    public const META_CONFIRMED  = 'confirmed';  // confermati/corretti dall'avvocato
    public const META_FAILED     = 'failed';

    protected $fillable = [
        'tenant_id',
        'matter_id',
        'uploaded_by',
        'title',
        'original_filename',
        'disk',
        'path',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum',
        'status',
        'error',
        'chunk_count',
        'indexed_at',
        'metadata',
        'metadata_status',
        'metadata_error',
        'metadata_reviewed_at',
    ];

    protected $casts = [
        'size_bytes'           => 'integer',
        'chunk_count'          => 'integer',
        'indexed_at'           => 'datetime',
        'metadata'             => 'array',
        'metadata_reviewed_at' => 'datetime',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
