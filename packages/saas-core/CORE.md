# saas/core — Documentazione

Package Laravel riutilizzabile per tutti i layer di sicurezza di un'applicazione SaaS multi-tenant.

---

## Stack tecnologico

| Layer | Tecnologia |
|---|---|
| Backend | PHP 8.3+, Laravel 13, PostgreSQL 14+ |
| Autenticazione | WebAuthn/FIDO2 via `asbiin/laravel-webauthn` v5 |
| Token mobile | Laravel Sanctum v4 |
| Permessi | `spatie/laravel-permission` v6 |
| Audit | `spatie/laravel-activitylog` v4 |
| CSP | `spatie/laravel-csp` v3 |
| Header sicurezza | `bepsvpt/laravel-security-header` v8 |
| Frontend | Vue 3, Inertia.js, PrimeVue v4 (Aura), Tailwind v4, Vite 8 |
| Mobile | React Native + Sanctum (access + refresh token) |
| Test | PestPHP v3, Orchestra Testbench v10 |

---

## Struttura del package

```
saas/core
├── config/
│   └── saas-core.php              ← configurazione centrale (pubblicabile)
├── database/
│   └── migrations/                ← tabelle: users, tenants, permissions, activity_log, PAT
├── routes/
│   ├── passkeys.php               ← POST /auth/passkey/challenge|verify|register/*
│   └── mobile.php                 ← POST /auth/mobile/refresh|logout
├── src/
│   ├── SaasCoreServiceProvider.php
│   ├── Kernel/Contracts/          ← interfacce (TenantResolverInterface, ecc.)
│   ├── Auth/
│   │   ├── Passkeys/              ← PasskeyController, PasskeyRegistrationController
│   │   └── Mobile/                ← MobileAuthController, DeviceTokenService, RefreshTokenRotator
│   ├── Tenancy/
│   │   ├── Middleware/SetTenant.php
│   │   ├── Models/Tenant.php
│   │   ├── Resolvers/SubdomainResolver.php
│   │   ├── Resolvers/HeaderResolver.php
│   │   └── Scopes/TenantScope.php
│   ├── Access/
│   │   ├── Middleware/RequireRole.php
│   │   └── Seeders/RoleSeeder.php
│   ├── Audit/
│   │   ├── GdprEraser.php
│   │   └── Traits/Auditable.php
│   ├── Security/
│   │   ├── Middleware/SecurityHeaders.php
│   │   ├── Middleware/SessionHardener.php
│   │   └── Csp/SaasCorePreset.php
│   └── Console/Commands/InstallCommand.php
├── stubs/
│   ├── laravel/                   ← stub copiati da saas-core:install --with-vue
│   └── react-native/              ← stub copiati da saas-core:install --with-react-native
└── tests/                         ← 73 test, 129 assertion (tutti verdi)
```

---

## Moduli

### Auth — Passkey (WebAuthn)

Autenticazione passwordless con FIDO2. Il browser usa biometrica (Face ID, Touch ID, Windows Hello) invece di una password.

**Flusso login in 2 step:**

```
1. POST /auth/passkey/challenge   { email }
   ← { challenge, rpId, timeout }   ← server genera challenge casuale

2. navigator.credentials.get({ publicKey: options })
   ← il browser firma la challenge con la chiave privata sul dispositivo

3. POST /auth/passkey/verify   { email, response }
   ← { redirect: '/dashboard' }     ← server verifica la firma → sessione creata
```

**Flusso registrazione in 2 step:**

```
1. POST /auth/passkey/register/options   { name, email }
   ← PublicKeyCredentialCreationOptions  ← server crea utente + challenge

2. navigator.credentials.create({ publicKey: options })
   ← il browser CREA una nuova coppia di chiavi (privata sul device, pubblica → server)

3. POST /auth/passkey/register   { response, key_name }
   ← { redirect: '/dashboard' }   ← server verifica attestazione, salva chiave pubblica
```

**File chiave:**
- `src/Auth/Passkeys/PasskeyController.php` — `challenge()` e `verify()`
- `src/Auth/Passkeys/PasskeyRegistrationController.php` — `options()` e `register()`
- `routes/passkeys.php`

**Frontend (stubs):**
- `composables/usePasskey.ts` — login (usa `navigator.credentials.get`)
- `composables/usePasskeyRegister.ts` — registrazione (usa `navigator.credentials.create`)
- `Pages/Auth/Login.vue` e `Pages/Auth/Register.vue`

**Differenza tecnica login vs registrazione:**
- `credentials.get()` → firma con chiave ESISTENTE → login
- `credentials.create()` → genera NUOVA coppia di chiavi → registrazione

**Rollback automatico:** se la registrazione fallisce dopo che l'utente è stato creato, il controller elimina l'utente così si può riprovare con gli stessi dati.

**Requisito:** le passkey richiedono HTTPS. Con Herd: `herd secure nome-progetto`.

---

### Auth — Mobile (Sanctum)

Autenticazione per React Native con coppia access token + refresh token. Dopo una passkey verificata, il controller emette i token invece di creare una sessione web.

**Flusso:**

```
POST /auth/passkey/verify   { email, response }
← { access_token, refresh_token, token_type: 'Bearer', expires_in: 86400 }

// Richieste API autenticate:
Authorization: Bearer <access_token>
X-Tenant-ID: acme

// Rinnovo token prima della scadenza:
POST /auth/mobile/refresh   { refresh_token }
← { access_token, refresh_token }   ← vecchio refresh token viene eliminato (rotation)
```

**Sicurezza token:**
- Access token: vita breve (default 24h)
- Refresh token: vita lunga (default 30gg) con rotation — ogni uso genera un nuovo token
- Device fingerprint: legato all'impronta del dispositivo per rilevare furto token

**File chiave:**
- `src/Auth/Mobile/MobileAuthController.php`
- `src/Auth/Mobile/DeviceTokenService.php`
- `src/Auth/Mobile/RefreshTokenRotator.php`

---

### Tenancy — Multi-tenancy

Ogni request viene associata automaticamente a un tenant. Tutte le query Eloquent filtrano per `tenant_id` senza che il codice applicativo debba farlo manualmente.

**Due strategie di risoluzione:**

| Strategia | Quando usarla | Come funziona |
|---|---|---|
| `subdomain` | App web Vue | `acme.tuosaas.com` → tenant `acme` |
| `header` | React Native | Header `X-Tenant-ID: acme` |

**SubdomainResolver — casi gestiti:**

| Host | Risultato | Motivo |
|---|---|---|
| `acme.tuosaas.com` | `acme` | 3 segmenti, standard |
| `acme.localhost` | `acme` | 2 segmenti, TLD dev (`localhost`) |
| `acme.test` | `acme` | 2 segmenti, TLD dev (`test`) |
| `tuosaas.com` | `null` | dominio root, nessun tenant |
| `localhost` | `null` | bare localhost, nessun tenant |

**SetTenant middleware:**

Importante: usa `app()->bind()` invece di `app()->instance()` per binding null.
```php
// SBAGLIATO: Laravel usa isset() che restituisce false per null
app()->instance('current.tenant', null);

// CORRETTO: il closure restituisce null in modo sicuro
app()->bind('current.tenant', fn () => null);
```

**File chiave:**
- `src/Tenancy/Middleware/SetTenant.php`
- `src/Tenancy/Resolvers/SubdomainResolver.php`
- `src/Tenancy/Resolvers/HeaderResolver.php`
- `src/Tenancy/Scopes/TenantScope.php`
- `src/Tenancy/Models/Tenant.php`

---

### Access — RBAC

Ruoli e permessi basati su `spatie/laravel-permission`. Il middleware `RequireRole` blocca l'accesso alle route protette.

```php
// In route:
Route::middleware('role:admin')->group(...)
Route::middleware('role:admin,manager')->group(...) // uno qualsiasi dei due

// Nel codice:
$user->hasRole('admin');
$user->assignRole('user');
```

**RoleSeeder** crea i ruoli definiti in `saas-core.access.roles`. Eseguito automaticamente da `saas-core:install`.

**File chiave:**
- `src/Access/Middleware/RequireRole.php`
- `src/Access/Seeders/RoleSeeder.php`

---

### Audit — GDPR & Compliance

**Auditable trait:** aggiunge il logging automatico di create/update/delete su qualsiasi Model.

```php
// In un Model:
use SaaS\Core\Audit\Traits\Auditable;

class Invoice extends Model {
    use Auditable;
}

// Ogni modifica viene loggata in activity_log:
// { subject_type: 'Invoice', subject_id: 1, event: 'updated',
//   properties: { old: {...}, attributes: {...} } }
```

**GdprEraser:** cancellazione conforme al GDPR (diritto all'oblio).

```php
// Nel controller:
(new \SaaS\Core\Audit\GdprEraser)->erase($request->user());
```

- Anonimizza nome ed email dell'utente (non elimina il record per integrità referenziale)
- **Preserva** i log dei Model finanziari (`audit.financial_models`) — obbligo legale 7 anni
- Elimina accessi, token Sanctum, log non finanziari

**Retention:** configurabile via `audit.retention_years`. Default 7 anni (minimo legale EU per dati finanziari).

**File chiave:**
- `src/Audit/Traits/Auditable.php`
- `src/Audit/GdprEraser.php`

---

### Security — Header HTTP

**SecurityHeaders middleware** aggiunge automaticamente gli header di sicurezza a ogni response:

| Header | Valore default | Protezione contro |
|---|---|---|
| `X-Frame-Options` | `SAMEORIGIN` | Clickjacking |
| `X-Content-Type-Options` | `nosniff` | MIME sniffing |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Data leakage nei referer |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` | Accesso sensori browser |
| `Strict-Transport-Security` | `max-age=31536000` | Downgrade HTTPS→HTTP |
| `Content-Security-Policy` | nonce + self per script/style | XSS |

**SessionHardener middleware:** scade la sessione dopo inattività configurabile.
- Controlla `last_activity` in sessione
- Dopo `auth.session_lifetime` minuti → logout forzato + redirect a `/login`
- Usa URL configurabile (`saas-core.auth.login_url`) invece di `route('login')` fisso

**SaasCorePreset (Spatie CSP v3):** implementa l'interfaccia `Preset` di Spatie CSP.
- Blocca `object-src: none` (no Flash, no plugin)
- Blocca `frame-ancestors: none` (no iframe da altri domini)
- Restringe `form-action: self` (no form submission a domini esterni)
- Script e style: nonce se `csp_nonce: true`, altrimenti `self`

```php
// In config/csp.php dell'app:
'presets' => [
    \SaaS\Core\Security\Csp\SaasCorePreset::class,
],
```

**File chiave:**
- `src/Security/Middleware/SecurityHeaders.php`
- `src/Security/Middleware/SessionHardener.php`
- `src/Security/Csp/SaasCorePreset.php`

---

### Console — InstallCommand

Comando Artisan che automatizza l'installazione completa del package in un'app Laravel.

```bash
php artisan saas-core:install [opzioni]
```

| Opzione | Effetto |
|---|---|
| *(nessuna)* | Installazione interattiva con conferme |
| `--force` | Sovrascrive tutti i file senza chiedere |
| `--no-migrate` | Salta `php artisan migrate` |
| `--no-seed` | Salta la creazione dei ruoli |
| `--with-vue` | Copia tutti i file frontend Vue/Inertia/PrimeVue |
| `--with-react-native` | Copia gli stub React Native/Expo in `mobile/` |

**File chiave:** `src/Console/Commands/InstallCommand.php`

---

## Frontend — Vue 3 + Inertia.js + PrimeVue + Tailwind v4

### Configurazione CSS layer (critica)

PrimeVue e Tailwind possono creare conflitti di specificità. La soluzione ufficiale usa i CSS cascade layers.

**`app.css`:**
```css
@import "tailwindcss";
@import "tailwindcss-primeui";   /* esporta bg-primary, text-surface-*, ecc. */
```

**`app.ts`:**
```typescript
app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: {
            darkModeSelector: '.dark',
            cssLayer: {
                name: 'primevue',
                order: 'theme, base, primevue',  // Tailwind v4
                // NON usare 'tailwind-base, primevue, tailwind-utilities' (era Tailwind v3)
            },
        },
    },
})
```

**Ordine di precedenza risultante:**
```
theme (variabili Tailwind) → base (reset) → primevue (componenti) → utilities (classi Tailwind)
```
Le utility Tailwind (`flex`, `text-lg`, `bg-red-500`) sovrascrivono sempre gli stili PrimeVue.

### Stack completo

| File | Scopo |
|---|---|
| `vite.config.ts` | Vite 8 + `@tailwindcss/vite` + `laravel-vite-plugin` + Vue |
| `app.css` | Tailwind v4 + `tailwindcss-primeui` + `@theme` custom |
| `app.ts` | Bootstrap Inertia + PrimeVue Aura + cssLayer |
| `Layouts/AppLayout.vue` | Menubar PrimeVue, Toast, ConfirmDialog |
| `Pages/Auth/Login.vue` | Email + pulsante passkey |
| `Pages/Auth/Register.vue` | Nome + email + pulsante registrazione passkey |
| `composables/usePasskey.ts` | Login WebAuthn (`credentials.get`) |
| `composables/usePasskeyRegister.ts` | Registrazione WebAuthn (`credentials.create`) |

### VSCode — eliminare warning CSS

Il linter CSS di VSCode non riconosce le at-rule di Tailwind v4. Falsi positivi, non errori reali.

```json
// .vscode/settings.json
{
    "css.lint.unknownAtRules": "ignore",
    "scss.lint.unknownAtRules": "ignore"
}
```

---

## ServiceProvider — cosa registra automaticamente

| Tipo | Nome / Alias | Classe |
|---|---|---|
| Singleton | `TenantResolverInterface` | `SubdomainResolver` o `HeaderResolver` |
| Middleware | `tenant` | `SetTenant` |
| Middleware | `role` | `RequireRole` |
| Middleware | `security.headers` | `SecurityHeaders` |
| Middleware | `session.hardener` | `SessionHardener` |
| Rate limiter | `login` | 5 tentativi/minuto per IP |
| Rate limiter | `api` | 100 request/minuto per IP |
| Route | `/auth/passkey/*` | Se `auth.passkeys: true` |
| Route | `/auth/mobile/*` | Sempre |
| Migrations | — | Caricate automaticamente |
| Command | `saas-core:install` | Solo in console |

---

## Test

```bash
./vendor/bin/pest
```

73 test, 129 assertion. Tutti verdi.

| File test | Cosa copre |
|---|---|
| `TenancyTest.php` | SubdomainResolver, HeaderResolver, TenantScope, SetTenant |
| `PasskeyTest.php` | Challenge, verify, registrazione |
| `MobileAuthTest.php` | Login mobile, refresh token rotation |
| `AccessTest.php` | RequireRole, RoleSeeder |
| `AuditTest.php` | Auditable trait, GdprEraser |
| `SecurityTest.php` | SecurityHeaders (tutti gli header), SessionHardener, SaasCorePreset CSP |

**Setup test:** PostgreSQL (`saas_core_test` database), Orchestra Testbench v10, PestPHP v3.

---

## Note critiche (errori comuni)

**1. Binding null nel container Laravel**
```php
// SBAGLIATO: isset(null) === false → Laravel prova a risolvere come classe → eccezione
app()->instance('current.tenant', null);

// CORRETTO: il closure è sempre presente → restituisce null in modo sicuro
app()->bind('current.tenant', fn () => null);
```

**2. Spatie CSP v3: API cambiata**
```php
// SBAGLIATO (v2): metodo non esiste in v3
$policy->addDirective(Directive::SCRIPT, 'self');

// CORRETTO (v3): implementare Preset con configure(Policy $policy)
$policy->add(Directive::SCRIPT, Keyword::SELF);
```

**3. Tailwind v4: ordine cssLayer**
```typescript
// SBAGLIATO: nomi layer di Tailwind v3
order: 'tailwind-base, primevue, tailwind-utilities'

// CORRETTO: nomi layer di Tailwind v4
order: 'theme, base, primevue'
```

**4. ESM in vite.config.ts: `__dirname` non esiste**
```typescript
// SBAGLIATO in moduli ES
resolve: { alias: { '@': path.resolve(__dirname, 'resources/js') } }

// CORRETTO con ESM
import { fileURLToPath, URL } from 'node:url'
resolve: { alias: { '@': fileURLToPath(new URL('resources/js', import.meta.url)) } }
```

**5. Passkey richiedono HTTPS**
```bash
# Con Herd:
herd secure nome-progetto
```

**6. `app.key` mancante nei test**
```php
// In tests/TestCase.php → defineEnvironment():
$config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
```

---

## Roadmap

- [x] React Native stubs (Expo iOS/Android)
- [ ] TOTP (Google Authenticator) come secondo fattore
- [ ] Row Level Security PostgreSQL (RLS) per doppio layer di isolamento
- [ ] Feature flags via `laravel/pennant`
- [ ] Cron/command per pulizia utenti senza passkey registrate
- [ ] Pubblicazione su Packagist
