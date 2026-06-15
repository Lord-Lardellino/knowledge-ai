<?php

namespace Tests\Unit;

use App\Services\Knowledge\BaaiEmbedder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BaaiEmbedderTest extends TestCase
{
    public function test_baai_embedder_chiama_endpoint_locale_e_adatta_dimensioni(): void
    {
        config()->set('knowledge.baai.endpoint', 'http://127.0.0.1:8765/embed');
        config()->set('knowledge.baai.batch_size', 4);
        config()->set('knowledge.embedding.dimensions', 1536);

        Http::fake([
            '127.0.0.1:8765/embed' => Http::response([
                'embeddings' => [
                    array_fill(0, 1024, 0.25),
                    array_fill(0, 1024, 0.5),
                ],
            ]),
        ]);

        $vectors = app(BaaiEmbedder::class)->embedDocuments(['primo testo', 'secondo testo']);

        Http::assertSent(fn ($request) =>
            $request->url() === 'http://127.0.0.1:8765/embed'
            && $request['texts'] === ['primo testo', 'secondo testo']
            && $request['batch_size'] === 4
        );

        $this->assertCount(2, $vectors);
        $this->assertCount(1536, $vectors[0]);
        $this->assertSame(0.25, $vectors[0][0]);
        $this->assertSame(0.0, $vectors[0][1535]);
    }
}
