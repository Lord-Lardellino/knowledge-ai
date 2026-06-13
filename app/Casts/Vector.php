<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Vector — cast per la colonna pgvector.
 *
 * In DB il vettore è una stringa "[0.1,0.2,...]"; in PHP lo vogliamo come array
 * di float. Converte nei due sensi.
 *
 * USO:  protected $casts = ['embedding' => Vector::class];
 */
class Vector implements CastsAttributes
{
    /** DB string → array<float> */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return array_map('floatval', explode(',', trim($value, '[]')));
    }

    /** array<float> → DB string "[...]" */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return '[' . implode(',', array_map(static fn ($v) => (float) $v, $value)) . ']';
    }
}
