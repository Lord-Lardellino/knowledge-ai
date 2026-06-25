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

    'viewer' => [
        'libreoffice_bin' => env('KNOWLEDGE_LIBREOFFICE_BIN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Similarità documenti (confronto "Simili")
    |--------------------------------------------------------------------------
    | Il coseno fra embedding ha un "pavimento" alto fra atti dello stesso
    | dominio (lessico giuridico comune): atti fuori tema restano ~0.83-0.86,
    | mentre quelli davvero affini salgono a ~0.95+. Calibriamo rimappando
    | l'intervallo utile [floor, ceil] su [0%, 100%]: così "più argomenti in
    | comune = % più alta" e gli atti non pertinenti scendono vicino a 0.
    | I valori dipendono dal modello di embedding: tarabili via .env.
    |
    | lexical_blend (default OFF): se attivo fonde col trigram pg_trgm — utile
    | per la caccia ai duplicati testuali, ma penalizza lo stesso-tema-parole-diverse.
    */
    'similarity' => [
        'floor' => (float) env('KNOWLEDGE_SIM_FLOOR', 0.83),
        'ceil'  => (float) env('KNOWLEDGE_SIM_CEIL', 0.97),
        'lexical_blend'   => (bool) env('KNOWLEDGE_SIM_LEXICAL_BLEND', false),
        'semantic_weight' => (float) env('KNOWLEDGE_SIM_SEMANTIC_WEIGHT', 0.5),
        'lexical_weight'  => (float) env('KNOWLEDGE_SIM_LEXICAL_WEIGHT', 0.5),
    ],

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
        // 14k caratteri con campionamento intelligente aiutano i metadati (tipo, parti, date, importi stanno nelle
        // anche quando non compaiono nelle prime pagine. Alzabile via .env se serve.
        'max_chars'     => (int) env('KNOWLEDGE_METADATA_MAX_CHARS', 14000),
        'temperature'   => (float) env('KNOWLEDGE_METADATA_TEMPERATURE', 0.2),
        // Tetto sull'output (i metadati JSON sono piccoli): blocca risposte lunghe.
        'max_output_tokens' => (int) env('KNOWLEDGE_METADATA_MAX_OUTPUT_TOKENS', 1800),
        // Token di "thinking" dei modelli 2.5 (fatturati come output): 0 = disattivati.
        // Per un'estrazione strutturata non servono e sono la voce di costo maggiore.
        'thinking_budget'   => (int) env('KNOWLEDGE_METADATA_THINKING_BUDGET', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat AI sui documenti (RAG con citazioni)
    |--------------------------------------------------------------------------
    | Recupera i chunk più pertinenti (pgvector) e li passa come contesto al
    | modello, che risponde citando le fonti [n]. top_k = estratti nel contesto.
    */
    'chat' => [
        // flash-lite = il modello più economico; thinkingBudget 0 azzera i token di
        // ragionamento (la voce più cara). Contesto e output tenuti stretti.
        'model'             => env('KNOWLEDGE_CHAT_MODEL', 'gemini-2.5-flash-lite'),
        // Modello di ripiego quando il primario è sovraccarico (503). Vuoto = disattivo.
        'fallback_model'    => env('KNOWLEDGE_CHAT_FALLBACK_MODEL', 'gemini-2.0-flash-lite'),
        'top_k'             => (int) env('KNOWLEDGE_CHAT_TOP_K', 4),
        'temperature'       => (float) env('KNOWLEDGE_CHAT_TEMPERATURE', 0.2),
        'max_output_tokens' => (int) env('KNOWLEDGE_CHAT_MAX_OUTPUT_TOKENS', 600),
        'thinking_budget'   => (int) env('KNOWLEDGE_CHAT_THINKING_BUDGET', 0),
        // Caratteri massimi per estratto nel contesto (meno token in input).
        'snippet_chars'     => (int) env('KNOWLEDGE_CHAT_SNIPPET_CHARS', 700),
        // Storico massimo (turni) reimmesso nel prompt: meno = meno token.
        'history_turns'     => (int) env('KNOWLEDGE_CHAT_HISTORY_TURNS', 4),
        // Cache: stessa domanda sullo stesso archivio non ripaga il modello.
        'cache_ttl'         => (int) env('KNOWLEDGE_CHAT_CACHE_TTL', 21600), // 6h
        'embed_cache_ttl'   => (int) env('KNOWLEDGE_CHAT_EMBED_CACHE_TTL', 604800), // 7g
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
