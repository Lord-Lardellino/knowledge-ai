<?php

/**
 * saas-core.php — Configurazione centrale del package
 *
 * Questo file viene pubblicato nell'app con:
 *   php artisan vendor:publish --tag=saas-core-config
 *
 * Ogni SaaS sovrascrive solo le chiavi che gli interessano.
 * Le chiavi non toccate usano questi valori di default.
 *
 * La struttura rispecchia i moduli del package:
 *   auth → src/Auth/
 *   tenancy → src/Tenancy/
 *   access → src/Access/
 *   audit → src/Audit/
 *   security → src/Security/
 */

return [

    // -------------------------------------------------------------------------
    // AUTH
    // Configurazione dell'autenticazione passwordless e dei token mobile.
    // -------------------------------------------------------------------------
    'auth' => [

        // Abilita il flusso passkey (WebAuthn).
        // Se false, le route /auth/passkey/* non vengono registrate.
        'passkeys' => true,

        // Abilita il TOTP (Google Authenticator / Authy) come secondo fattore.
        // Se false, le route /auth/totp/* e /profile/totp/* non vengono registrate.
        'totp' => true,

        // Registra le route native di Fortify (/login, /two-factor-challenge, ...).
        // Default false: il package usa Fortify SOLO per il TwoFactorAuthenticationProvider
        // (generazione/verifica codici TOTP) — le route di auth sono già fornite
        // dal package stesso. Su Laravel fresh le route Fortify causano
        // BindingResolutionException (LoginViewResponse non bindato) appena
        // visiti /login. Metti true solo se sai cosa stai facendo.
        'fortify_routes' => false,

        // Ruoli per cui il TOTP è OBBLIGATORIO dopo il login passkey.
        // [] (vuoto) = richiesto per tutti gli utenti che hanno attivato il TOTP.
        // Esempio: ['admin', 'owner'] → solo admin e owner vengono forzati.
        'totp_required_roles' => [],

        // Durata dell'access token Sanctum per le API mobile (in secondi).
        // 86400 = 24 ore. Vita breve per limitare il danno in caso di furto.
        'token_ttl' => 86400,

        // Durata del refresh token (in secondi).
        // 2592000 = 30 giorni. L'app mobile lo usa per ottenere un nuovo access token
        // senza far rifare il login all'utente.
        'refresh_ttl' => 2592000,

        // Durata della sessione web (in minuti).
        // Dopo questo tempo di inattività, l'utente viene disconnesso.
        'session_lifetime' => 120,

        // URL di redirect dopo login completato (passkey + eventuale TOTP).
        // Usato dai controller invece di route('dashboard') che non esiste
        // in tutti gli ambienti (es. test senza route named).
        'redirect_after_login' => '/dashboard',

        // Origin WebAuthn aggiuntivi per le app native (mobile).
        // Le passkey Android inviano un origin "android:apk-key-hash:<base64url>"
        // invece di "https://...": la libreria webauthn-lib li rifiuta a meno che
        // non siano in questa lista. L'origin web (APP_URL) viene aggiunto
        // automaticamente dal ServiceProvider — qui vanno SOLO quelli extra.
        //
        // Come calcolare l'apk-key-hash dalla firma SHA-256 del keystore:
        //   keytool -list -v -keystore app/debug.keystore -storepass android
        //   → prendere l'impronta SHA256, convertirla da hex a bytes e poi base64url.
        // Oppure: copiare il valore "origin" dal log Laravel dopo un tentativo fallito.
        //
        // Esempio .env:
        //   WEBAUTHN_ALLOWED_ORIGINS=android:apk-key-hash:-sYXRdwJA3hvue3mKpYrOZ9zSPC7b4mbgzJmdZEDO5w
        'passkey_origins' => array_filter(
            array_map('trim', explode(',', (string) env('WEBAUTHN_ALLOWED_ORIGINS', '')))
        ),
    ],

    // -------------------------------------------------------------------------
    // MOBILE — identità delle app native per i Digital Asset Links
    // Usate dalle route /.well-known/* (vedi stub routes/web.php).
    // env() qui (nei file config) sopravvive a config:cache; nelle route NO.
    // -------------------------------------------------------------------------
    'mobile' => [

        // Package name dell'app Android (lo stesso di app.json → android.package)
        'android_package_name' => env('ANDROID_PACKAGE_NAME', 'com.example.myapp'),

        // Impronte SHA-256 dei certificati di firma, formato AA:BB:CC:...
        // separati da virgola. Per debug: keytool -list -v -keystore debug.keystore
        // Per Play Store: Play Console → App integrity → App signing key.
        // ⚠ In produzione RIMUOVI l'impronta del debug.keystore: è pubblica
        //   e nota a chiunque — lasciarla permette a un'app firmata con la
        //   chiave di debug standard di spacciarsi per la tua.
        'android_sha256_fingerprints' => array_filter(
            array_map('trim', explode(',', (string) env('ANDROID_SHA256_FINGERPRINTS', '')))
        ),

        // App ID iOS: TEAMID.bundleIdentifier (es. AB12CD34EF.com.example.myapp)
        // Necessario per le passkey native iOS (apple-app-site-association).
        'ios_app_id' => env('IOS_APP_ID', ''),
    ],

    // -------------------------------------------------------------------------
    // TENANCY
    // Come il package identifica a quale tenant appartiene ogni request.
    // -------------------------------------------------------------------------
    'tenancy' => [

        // Strategia per risolvere il tenant dalla request.
        // 'subdomain' → legge il sottodominio: acme.tuosaas.com → tenant "acme"
        //               Usato dalle app web Vue.
        // 'header'    → legge l'header HTTP X-Tenant-ID: acme
        //               Usato da React Native (non ha sottodomini).
        // 'composite' → prova prima l'header, poi il sottodominio.
        //               Per SaaS che servono web E mobile insieme.
        'resolver' => 'subdomain',

        // Model Eloquent del tenant. Sostituiscilo se la tua app estende
        // il Tenant del package (es. App\Models\Tenant con colonne extra).
        'model' => \SaaS\Core\Tenancy\Models\Tenant::class,

        // Self-signup: permette di creare un tenant alla registrazione
        // passando "company". false = i tenant si creano solo da backend
        // (es. SaaS a vendita assistita dove il commerciale apre il tenant).
        'self_signup' => true,

        // Nome della colonna tenant_id in ogni tabella del database.
        // Il TenantScope la usa per aggiungere WHERE tenant_id = ? ad ogni query.
        'column' => 'tenant_id',

        // Abilita il Row Level Security di PostgreSQL come secondo layer.
        // Il Global Scope Eloquent è il primo layer (applicazione).
        // RLS è il secondo layer (database): blocca accessi anche in caso di bug PHP.
        'rls' => true,

        // Giorni di validità di un invito al tenant.
        'invite_ttl_days' => 7,

        // Sottodomini riservati all'infrastruttura: un tenant non può
        // registrarsi con questi slug (confliggerebbero con i servizi).
        'reserved_slugs' => [
            'www', 'api', 'app', 'admin', 'mail', 'smtp', 'ftp', 'staging', 'dev', 'test',
        ],
    ],

    // -------------------------------------------------------------------------
    // ACCESS
    // Ruoli e permessi dell'applicazione.
    // -------------------------------------------------------------------------
    'access' => [

        // Lista dei ruoli che il RoleSeeder creerà nel database.
        // Ogni SaaS personalizza questa lista con i propri ruoli.
        // Esempio NaviLedger: ['owner', 'captain', 'crew', 'supplier', 'admin']
        'roles' => [
            'owner',
            'admin',
            'user',
        ],

        // Ruolo assegnato automaticamente ai nuovi utenti al momento della registrazione.
        'default_role' => 'user',

        // Ruolo assegnato a chi CREA il tenant (self-signup con "company").
        'owner_role' => 'owner',

        // Ruoli assegnabili tramite invito. owner escluso di proposito:
        // l'owner è solo il fondatore del tenant.
        'invitable_roles' => ['admin', 'user'],

        // Ruoli considerati "admin del tenant" da SaasPolicy::isTenantAdmin().
        // Usato per autorizzare operazioni riservate (gestione utenti, billing, ecc.).
        'admin_roles' => ['admin', 'owner'],

        // Ruolo super-admin: bypassa tutti i check di tenant isolation in SaasPolicy.
        // Usato solo per operazioni di supporto/amministrazione globale.
        'super_admin_role' => 'super-admin',

        // Abilita il sistema di feature flag tramite laravel/pennant.
        // Permette di attivare/disattivare funzionalità per singolo tenant o utente.
        'feature_flags' => false,
    ],

    // -------------------------------------------------------------------------
    // AUDIT
    // Logging delle modifiche ai Model per compliance GDPR e audit finanziario.
    // -------------------------------------------------------------------------
    'audit' => [

        // Anni di retention per i log di audit.
        // 7 anni è il minimo legale per dati finanziari in Italia/EU.
        'retention_years' => 7,

        // Lista dei Model con retention estesa (dati finanziari).
        // Questi Model non vengono mai anonimizzati dal GdprEraser,
        // anche se l'utente esercita il diritto all'oblio.
        // Esempio: [Invoice::class, Payment::class, Transaction::class]
        'financial_models' => [],

        // Driver per lo storage dei log.
        // 'database' → tabella activity_log (spatie/laravel-activitylog default)
        // 'elasticsearch' → per ricerche full-text su grandi volumi di log
        'driver' => 'database',
    ],

    // -------------------------------------------------------------------------
    // DATA PROTECTION (L5)
    // Configurazione per la protezione dei dati sensibili a riposo.
    // -------------------------------------------------------------------------
    'data' => [

        // Abilita il field binding nel cast EncryptedString.
        // Il field binding firma crittograficamente il nome del campo nel ciphertext:
        // impedisce che il valore cifrato di 'iban' venga spostato in 'passport_number'.
        // Disabilita solo se stai migrando dati esistenti senza field binding.
        'encrypted_field_binding' => true,

        // Cifra i log di activitylog (campi 'properties').
        // Richiede che il DB supporti la funzione decrypt() di Laravel.
        // Utile per log che contengono valori sensibili (es. vecchio IBAN).
        'encrypt_log' => false,

        // NOTA: Backup S3 Object Lock, AWS KMS, Infisical sono responsabilità
        // dell'app consumer — vanno configurati in config/filesystems.php e .env.
        // Documentazione: https://docs.tuosaas.com/security/data-protection
    ],

    // -------------------------------------------------------------------------
    // SECURITY
    // Rate limiting, header HTTP, Content Security Policy.
    // -------------------------------------------------------------------------
    'security' => [

        'rate_limit' => [

            // Tentativi di login: 5 al minuto prima del blocco temporaneo.
            // Formato: [numero_tentativi, minuti]
            // Dopo i 5 tentativi l'IP viene bloccato per 15 minuti (gestito nel LoginLimiter).
            'login' => [5, 1],

            // Limite globale per le API: 100 request al minuto per IP.
            'api' => [100, 1],

            // Magic link di recovery: 3 richieste ogni 10 minuti per IP.
            // Previene spam email e enumerazione degli account registrati.
            'recovery' => [3, 10],
        ],

        // Durata HSTS in secondi (HTTP Strict Transport Security).
        // 31536000 = 1 anno. Dice al browser di usare SEMPRE HTTPS per questo dominio.
        // Attenzione: una volta attivato è difficile da rimuovere.
        'hsts_max_age' => 31536000,

        // Abilita il nonce per il Content Security Policy integrato con Vite.
        // Il nonce è un token casuale per ogni request che autorizza gli script inline.
        // Necessario per far funzionare Vite in dev senza disabilitare CSP.
        'csp_nonce' => true,
    ],

];
