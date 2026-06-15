<?php

namespace App\Services\Knowledge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * GeminiEmbedder — genera embedding con Gemini Embedding 2 via Gemini API.
 *
 * - Output troncato a 1536 dim (outputDimensionality) per stare sotto il limite
 *   indici pgvector. Embedding 2 auto-normalizza i vettori troncati.
 * - taskType distingue documenti (RETRIEVAL_DOCUMENT) e query (RETRIEVAL_QUERY):
 *   migliora la qualità della ricerca semantica.
 * - Batch via :batchEmbedContents, con retry su rate limit (429) e errori 5xx.
 *
 * Endpoint:
 *   POST {base}/models/{model}:batchEmbedContents?key=API_KEY
 */
class GeminiEmbedder implements EmbeddingProvider
{
    public function __construct(
        private ?string $apiKey = null,
        private ?string $model = null,
        private ?string $baseUrl = null,
        private int $dimensions = 1536,
    ) {
        $this->apiKey   ??= (string) config('knowledge.gemini.api_key');
        $this->model    ??= (string) config('knowledge.embedding.model', 'gemini-embedding-2');
        $this->baseUrl  ??= rtrim((string) config('knowledge.gemini.base_url'), '/');
        $this->dimensions = (int) config('knowledge.embedding.dimensions', 1536);
    }

    /**
     * Genera gli embedding per i testi dei documenti (taskType RETRIEVAL_DOCUMENT).
     *
     * @param  string[] $texts
     * @return array<int, float[]> embedding allineati per indice ai $texts
     */
    public function embedDocuments(array $texts): array
    {
        return $this->embedBatch(array_values($texts), 'RETRIEVAL_DOCUMENT');
    }

    /** Genera l'embedding di una query di ricerca (taskType RETRIEVAL_QUERY). */
    public function embedQuery(string $query): array
    {
        return $this->embedBatch([$query], 'RETRIEVAL_QUERY')[0];
    }

    /**
     * @param  string[] $texts
     * @return array<int, float[]>
     */
    private function embedBatch(array $texts, string $taskType): array
    {
        if ($texts === []) {
            return [];
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY non configurata.');
        }

        $modelPath = 'models/' . $this->model;

        $requests = array_map(fn (string $text) => [
            'model'                => $modelPath,
            'content'              => ['parts' => [['text' => $text]]],
            'taskType'             => $taskType,
            'outputDimensionality' => $this->dimensions,
        ], $texts);

        $response = $this->client()
            ->post("{$this->baseUrl}/{$modelPath}:batchEmbedContents?key={$this->apiKey}", [
                'requests' => $requests,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Gemini embedding fallito ({$response->status()}): " . $response->body()
            );
        }

        $embeddings = $response->json('embeddings', []);

        if (count($embeddings) !== count($texts)) {
            throw new RuntimeException('Numero di embedding restituiti diverso dai testi inviati.');
        }

        return array_map(fn (array $e) => array_map('floatval', $e['values']), $embeddings);
    }

    private function client(): PendingRequest
    {
        return Http::timeout(60)
            ->retry(3, 2000, function ($exception, $request) {
                // Retry su rate limit (429) e errori server transitori (5xx).
                $status = $exception->response?->status();
                return in_array($status, [429, 500, 502, 503, 504], true);
            }, throw: false)
            ->acceptJson();
    }
}
