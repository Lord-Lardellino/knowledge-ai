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

    /*
    |--------------------------------------------------------------------------
    | Piani — metadati di visualizzazione per la pagina pricing
    |--------------------------------------------------------------------------
    | I POSTI e i Price ID Stripe stanno in config saas-core.billing.plans.
    | Qui solo l'aspetto: etichetta, prezzo mostrato, descrizione, feature.
    | La chiave deve combaciare con quella in saas-core.billing.plans.
    */
    'billing_plans' => [
        'base' => [
            'label'       => 'Base',
            'price_label' => '19€ / mese',
            'description' => 'Per iniziare. 14 giorni di prova gratuita.',
            'features'    => [
                'Fino a 3 utenti',
                'Documenti illimitati',
                'Ricerca semantica',
            ],
            'highlight'   => false,
        ],
        'pro' => [
            'label'       => 'Pro',
            'price_label' => '49€ / mese',
            'description' => 'Per team che crescono.',
            'features'    => [
                'Fino a 10 utenti',
                'Documenti illimitati',
                'Ricerca semantica',
                'Chat AI sui documenti',
            ],
            'highlight'   => true, // piano evidenziato nella pagina
        ],
        'enterprise' => [
            'label'       => 'Enterprise',
            'price_label' => '149€ / mese',
            'description' => 'Per aziende strutturate.',
            'features'    => [
                'Fino a 50 utenti',
                'Tutto del piano Pro',
                'Template AI',
                'Supporto prioritario',
            ],
            'highlight'   => false,
        ],
    ],

];
