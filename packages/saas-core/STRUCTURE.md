# saas-core — Struttura del Package

Package Laravel riutilizzabile che copre tutti i layer di sicurezza per applicazioni SaaS.
Tecnologie: PHP 8.5 · Laravel 13 · PostgreSQL · Vue 3 · React Native.

---

## Filosofia

Questo package **non reinventa** ciò che esiste già.
Ogni modulo è uno strato sottile di orchestrazione sopra package maturi (Sanctum, Spatie, ecc.).
La regola è: **wrappa e configura**, non riscrivere.

Ogni modulo comunica con gli altri solo tramite **Laravel Events**.
Questo significa che puoi disabilitare o sostituire un modulo senza toccare gli altri.

---

## Struttura

```
saas-core/
│
├── src/
│   │
│   ├── Kernel/
│   │   └── Contracts/
│   │       ├── TenantResolverInterface.php
│   │       ├── TokenStorageInterface.php
│   │       └── AuditDriverInterface.php
│   │
│   │   COSA È: Le interfacce PHP che definiscono i "contratti" del package.
│   │   PERCHÉ: Un contratto dice COSA fa un componente, non COME.
│   │           Ogni SaaS può fornire la propria implementazione nel AppServiceProvider.
│   │           Esempio: TenantResolverInterface può essere implementata via subdomain
│   │           in NaviLedger e via JWT claim in un altro SaaS.
│   │
│   ├── Auth/
│   │   │
│   │   ├── Passkeys/
│   │   │   ├── PasskeyController.php
│   │   │   └── PasskeyChallenge.php
│   │   │
│   │   │   COSA È: Gestione dell'autenticazione passwordless via WebAuthn.
│   │   │   PERCHÉ: Le passkey sono lo standard moderno (FIDO2). Funziona su web
│   │   │           (browser Web Authentication API) e mobile (Face ID / Touch ID /
│   │   │           biometria Android). Il backend Laravel è identico per entrambi.
│   │   │   DIPENDE DA: asbiin/laravel-webauthn
│   │   │
│   │   ├── Mobile/
│   │   │   ├── DeviceTokenService.php
│   │   │   └── RefreshTokenRotator.php
│   │   │
│   │   │   COSA È: Token Sanctum arricchiti con device fingerprint per React Native.
│   │   │   PERCHÉ: Il browser gestisce i cookie automaticamente. React Native no.
│   │   │           Qui gestiamo access token (vita breve) + refresh token (vita lunga)
│   │   │           con rotazione automatica per sicurezza.
│   │   │   DIPENDE DA: laravel/sanctum
│   │   │
│   │   └── Session/
│   │       └── SessionHardener.php
│   │
│   │       COSA È: Middleware che rafforza i cookie di sessione web.
│   │       PERCHÉ: Per default Laravel non imposta tutti i flag di sicurezza.
│   │               Aggiungiamo: Secure (solo HTTPS), HttpOnly (non leggibile da JS),
│   │               SameSite=Strict (protegge da CSRF cross-site).
│   │
│   ├── Tenancy/
│   │   │
│   │   ├── Middleware/
│   │   │   └── SetTenant.php
│   │   │
│   │   │   COSA È: Middleware che legge il tenant dalla request e lo imposta globalmente.
│   │   │   PERCHÉ: Ogni request deve sapere a quale tenant appartiene prima di toccare
│   │   │           il database. Questo middleware gira prima di tutto il resto.
│   │   │
│   │   ├── Resolvers/
│   │   │   ├── SubdomainResolver.php
│   │   │   └── HeaderResolver.php
│   │   │
│   │   │   COSA È: Due strategie diverse per identificare il tenant dalla request.
│   │   │   PERCHÉ: Web usa sottodomini (acme.tuosaas.com → tenant "acme").
│   │   │           React Native non ha sottodomini: manda X-Tenant-ID nell'header HTTP.
│   │   │           Quale usare si configura in saas-core.php.
│   │   │
│   │   └── Scopes/
│   │       └── TenantScope.php
│   │
│   │       COSA È: Global Scope Eloquent registrato automaticamente su ogni Model.
│   │       PERCHÉ: Senza questo, una query come User::all() restituisce utenti di
│   │               TUTTI i tenant. Il Global Scope aggiunge WHERE tenant_id = ?
│   │               automaticamente su ogni query, senza che lo sviluppatore
│   │               debba ricordarselo. PostgreSQL RLS è il secondo layer indipendente.
│   │
│   ├── Access/
│   │   │
│   │   ├── Middleware/
│   │   │   └── RequireRole.php
│   │   │
│   │   │   COSA È: Middleware che blocca la request se l'utente non ha il ruolo richiesto.
│   │   │   PERCHÉ: Protegge le route a livello HTTP, prima ancora che il controller
│   │   │           esegua logica. Wrappa spatie/laravel-permission.
│   │   │
│   │   └── Seeders/
│   │       └── RoleSeeder.php
│   │
│   │       COSA È: Seeder che crea i ruoli base definiti in saas-core.php.
│   │       PERCHÉ: Ogni SaaS parte con ruoli diversi. Li configuriamo nel file config
│   │               e questo seeder li crea nel DB automaticamente.
│   │   DIPENDE DA: spatie/laravel-permission
│   │
│   ├── Audit/
│   │   └── Traits/
│   │       └── Auditable.php
│   │
│   │   COSA È: Trait PHP da aggiungere a qualsiasi Model per loggare ogni modifica.
│   │   PERCHÉ: Con `use Auditable` su un Model, ogni create/update/delete viene
│   │           registrato automaticamente con chi, quando e cosa è cambiato.
│   │           Obbligatorio per compliance GDPR e audit trail finanziario.
│   │   DIPENDE DA: spatie/laravel-activitylog
│   │
│   └── Security/
│       │
│       ├── Middleware/
│       │   └── SecurityHeaders.php
│       │
│       │   COSA È: Middleware che aggiunge header HTTP di sicurezza ad ogni response.
│       │   PERCHÉ: Header come X-Frame-Options, X-Content-Type-Options, HSTS
│       │           proteggono da clickjacking, MIME sniffing, downgrade HTTP.
│       │           Senza questi header i browser moderni segnalano la app come non sicura.
│       │
│       └── Presets/
│           ├── CspPreset.php
│           └── RateLimitProfiles.php
│
│           COSA È: Configurazioni pronte per CSP (Content Security Policy) e rate limiting.
│           PERCHÉ: CSP dice al browser quali risorse può caricare (protegge da XSS).
│                   RateLimitProfiles definisce soglie pronte: 5 tentativi/min sul login,
│                   100 req/min sulle API. Ogni SaaS può sovrascriverle.
│           DIPENDE DA: spatie/laravel-csp, bepsvpt/laravel-security-header
│
├── config/
│   └── saas-core.php
│
│   COSA È: File di configurazione centrale del package.
│   PERCHÉ: Tutto ciò che cambia da SaaS a SaaS sta qui: ruoli, resolver tenancy,
│           TTL dei token, retention audit, flag on/off per ogni modulo.
│           Pubblicato nell'app con: php artisan vendor:publish --tag=saas-core-config
│
├── database/
│   └── migrations/
│
│   COSA È: Migration per le colonne aggiuntive necessarie al package.
│   PERCHÉ: Non ricreano la tabella users (che esiste già in Laravel).
│           Aggiungono solo le colonne mancanti: webauthn credentials, tenant_id,
│           device_tokens. Pubblicabili e modificabili dall'app.
│
├── routes/
│   └── passkeys.php
│
│   COSA È: Route per gli endpoint WebAuthn (challenge + verify).
│   PERCHÉ: Registrate automaticamente dal ServiceProvider solo se
│           'auth.passkeys' => true nella config. Opt-in, non obbligatorie.
│
├── resources/
│   └── views/auth/
│
│   COSA È: Blade views per il flusso di autenticazione web.
│   PERCHÉ: Pubblicabili nell'app con vendor:publish. Ogni SaaS le personalizza
│           nel proprio resources/views/vendor/saas-core/.
│
├── stubs/
│   ├── vue/
│   │
│   │   COSA È: Componenti Vue 3 per il flusso passkey (pulsante, modal, feedback).
│   │   PERCHÉ: Stub = punto di partenza. Il SaaS li pubblica e li personalizza
│   │           liberamente. Non sono componenti "bloccati" come in un npm package.
│   │
│   └── react-native/
│
│       COSA È: Componenti React Native per il flusso passkey mobile.
│       PERCHÉ: Stesso concetto degli stub Vue, ma per l'app mobile.
│               Usano react-native-passkeys sotto.
│
└── tests/
    ├── Unit/     ← test su singole classi isolate (es: SubdomainResolver)
    └── Feature/  ← test su flussi completi (es: login passkey end-to-end)

    COSA È: Suite di test con PestPHP.
    PERCHÉ: Un package senza test non è affidabile. Ogni modulo ha i suoi test.
            Feature test usano orchestra/testbench per simulare un'app Laravel reale.
```

---

## Come si installa in un nuovo SaaS

```bash
# 1. Aggiungi il package
composer require yourorg/saas-core

# 2. Pubblica config, migration e stub
php artisan saas-core:install

# 3. Esegui le migration
php artisan migrate

# 4. Crea i ruoli base
php artisan db:seed --class=SaasCoreRoleSeeder
```

---

## Dipendenze esterne

| Package | Versione | Modulo che lo usa |
|---|---|---|
| laravel/framework | ^13.0 | tutto |
| laravel/sanctum | ^4.x | Auth/Mobile |
| laravel/fortify | ^1.x | Auth (pipeline) |
| asbiin/laravel-webauthn | ^5.x | Auth/Passkeys |
| spatie/laravel-permission | ^6.x | Access |
| spatie/laravel-activitylog | ^4.x | Audit |
| spatie/laravel-csp | ^3.x | Security/Presets |
| bepsvpt/laravel-security-header | ^8.x | Security/Middleware |

---

## Decisioni architetturali

**Tenancy column-based**: ogni tabella ha una colonna `tenant_id`. Semplice, stabile,
migrabile a `stancl/tenancy` (schema-per-tenant) in futuro senza riscrivere i moduli.

**Mono-package**: tutto in un repository. Quando avrai 2-3 SaaS con bisogni diversi
puoi splittare in monorepo senza riscrivere codice, solo riorganizzando i composer.json.

**UI come stub**: i componenti Vue e React Native sono stub pubblicabili, non librerie
bloccate. Ogni SaaS li personalizza liberamente dopo la pubblicazione.
