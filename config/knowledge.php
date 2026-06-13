<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    */
    'max_upload_mb' => env('KNOWLEDGE_MAX_UPLOAD_MB', 25),

    // Disco Storage dove salvare i file dei documenti (storage/app/private di default).
    'disk' => env('KNOWLEDGE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    | Dimensione approssimativa dei chunk (in caratteri) e overlap tra chunk
    | consecutivi per non spezzare il contesto a metà frase.
    */
    'chunk_size'    => env('KNOWLEDGE_CHUNK_SIZE', 1500),
    'chunk_overlap' => env('KNOWLEDGE_CHUNK_OVERLAP', 200),

    /*
    |--------------------------------------------------------------------------
    | Embedding (Gemini Embedding 2)
    |--------------------------------------------------------------------------
    | dimensions: troncato a 1536 per stare sotto il limite indici pgvector (2000).
    */
    'embedding' => [
        'model'      => env('KNOWLEDGE_EMBEDDING_MODEL', 'gemini-embedding-2'),
        'dimensions' => 1536,
        'batch_size' => 100, // chunk per richiesta API
    ],

    /*
    |--------------------------------------------------------------------------
    | Generazione (Gemma via Gemini API)
    |--------------------------------------------------------------------------
    */
    'generation' => [
        'simple'  => env('KNOWLEDGE_MODEL_SIMPLE', 'gemma-4-26b'),
        'complex' => env('KNOWLEDGE_MODEL_COMPLEX', 'gemma-4-31b'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gemini API
    |--------------------------------------------------------------------------
    */
    'gemini' => [
        'api_key'  => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

];
