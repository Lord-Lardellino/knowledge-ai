<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class BaaiEmbedder implements EmbeddingProvider
{
    public function embedDocuments(array $texts): array
    {
        return $this->embed(array_values($texts));
    }

    public function embedQuery(string $query): array
    {
        return $this->embed([$query])[0];
    }

    /**
     * @param string[] $texts
     * @return array<int, float[]>
     */
    private function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $endpoint = (string) config('knowledge.baai.endpoint');
        $targetDimensions = (int) config('knowledge.embedding.dimensions', 1536);

        if ($endpoint === '') {
            throw new RuntimeException('Endpoint BAAI non configurato.');
        }

        $response = Http::timeout((int) config('knowledge.baai.timeout', 900))
            ->acceptJson()
            ->post($endpoint, [
                'texts' => array_values($texts),
                'batch_size' => (int) config('knowledge.baai.batch_size', 8),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Embedding BAAI fallito ({$response->status()}): " . $response->body()
            );
        }

        $vectors = $response->json('embeddings', []);

        if (! is_array($vectors) || count($vectors) !== count($texts)) {
            throw new RuntimeException('Numero di embedding BAAI diverso dai testi inviati.');
        }

        return array_map(
            fn (array $vector) => $this->fitDimensions(array_map('floatval', $vector), $targetDimensions),
            $vectors,
        );
    }

    /**
     * @param float[] $vector
     * @return float[]
     */
    private function fitDimensions(array $vector, int $dimensions): array
    {
        $count = count($vector);

        if ($count === $dimensions) {
            return $vector;
        }

        if ($count > $dimensions) {
            return array_slice($vector, 0, $dimensions);
        }

        return array_pad($vector, $dimensions, 0.0);
    }
}
