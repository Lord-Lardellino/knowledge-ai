<?php

namespace App\Services\Knowledge;

interface EmbeddingProvider
{
    /**
     * @param string[] $texts
     * @return array<int, float[]>
     */
    public function embedDocuments(array $texts): array;

    /**
     * @return float[]
     */
    public function embedQuery(string $query): array;
}
