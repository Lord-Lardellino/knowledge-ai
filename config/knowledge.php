<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    */
    'max_upload_mb' => env('KNOWLEDGE_MAX_UPLOAD_MB', 25),

    /*
    |--------------------------------------------------------------------------
    | Verticale legale (modulo Pratiche)
    |--------------------------------------------------------------------------
    | Modulo attivabile/disattivabile. Per ora flag globale locale; in futuro
    | potrà leggere una feature di piano dal Tenant (saas-core) senza cambiare
    | il middleware 'legal.enabled' che lo applica.
    */
    'legal' => [
        'enabled' => env('KNOWLEDGE_LEGAL_ENABLED', true),
    ],

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
        // null/0 = un'unica richiesta API per documento. Imposta un numero solo
        // se il provider dovesse imporre un limite massimo di input per batch.
        'batch_size' => env('KNOWLEDGE_EMBEDDING_BATCH_SIZE'),
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
    | Estrazione metadati legali (Fase 2 verticale legale)
    |--------------------------------------------------------------------------
    | Modello usato per estrarre metadati strutturati dai documenti e numero
    | massimo di caratteri di testo inviati al modello (controllo costi/token).
    */
    // Modello flash-lite: il più economico che onora responseMimeType/responseSchema
    // (i Gemma ignorano il JSON mode e producono testo non strutturato).
    'metadata' => [
        'model'         => env('KNOWLEDGE_METADATA_MODEL', 'gemini-2.5-flash-lite'),
        // 8k caratteri bastano per i metadati (tipo, parti, date, importi stanno nelle
        // prime pagine): meno token in input. Alzabile via .env se serve più contesto.
        'max_chars'     => (int) env('KNOWLEDGE_METADATA_MAX_CHARS', 8000),
        'temperature'   => (float) env('KNOWLEDGE_METADATA_TEMPERATURE', 0.2),
        // Tetto sull'output (i metadati JSON sono piccoli): blocca risposte lunghe.
        'max_output_tokens' => (int) env('KNOWLEDGE_METADATA_MAX_OUTPUT_TOKENS', 1200),
        // Token di "thinking" dei modelli 2.5 (fatturati come output): 0 = disattivati.
        // Per un'estrazione strutturata non servono e sono la voce di costo maggiore.
        'thinking_budget'   => (int) env('KNOWLEDGE_METADATA_THINKING_BUDGET', 0),
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
