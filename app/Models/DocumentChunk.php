<?php

namespace App\Models;

use App\Casts\Vector;
use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentChunk — pezzo di testo di un documento, con il suo embedding.
 *
 * embedding: array<float> di 1536 elementi (Gemini Embedding 2 troncato).
 * La ricerca semantica non passa da qui ma da query raw con l'operatore <=>.
 */
class DocumentChunk extends Model
{
    use HasTenant;

    protected $fillable = [
        'tenant_id',
        'document_id',
        'chunk_index',
        'content',
        'token_count',
        'embedding',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'token_count' => 'integer',
        'embedding'   => Vector::class,
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
