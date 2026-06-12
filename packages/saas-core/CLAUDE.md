# saas/core — Package Overview

Pacchetto Laravel che fornisce l'infrastruttura di sicurezza comune a tutti i SaaS aziendali.
Si installa con `composer require saas/core`; il ServiceProvider si auto-registra via
`extra.laravel.providers` in `composer.json`.

## Struttura directory

```
src/
  Auth/
    Passkeys/      — Registrazione e login WebAuthn (passwordless)
    Mobile/        — Token Sanctum + refresh rotation per React Native
    Recovery/      — Magic link via email per device recovery
    Totp/          — 2FA TOTP (Google Authenticator / Authy)
  Access/
    Middleware/    — RequireRole: autorizzazione per ruolo
    Policies/      — SaasPolicy: base class per le Laravel Policy
    Seeders/       — RoleSeeder: popola i ruoli dal config
  Audit/
    Traits/        — Auditable: logga create/update/delete via activitylog
    GdprEraser     — Anonimizza utente su richiesta art. 17 GDPR
  Security/
    Casts/         — EncryptedString: cast Eloquent AES-256-CBC
    Csp/           — SaasCorePreset: preset CSP con nonce Vite
    Middleware/    — SecurityHeaders, SessionHardener
    Presets/       — RateLimitProfiles (registrati nel ServiceProvider)
  Tenancy/
    Middleware/    — SetTenant: risolve il tenant dalla request
    Models/        — Tenant model
    Resolvers/     — SubdomainResolver, HeaderResolver
    Scopes/        — TenantScope: WHERE tenant_id = ? su ogni query
  Kernel/
    Contracts/     — Interfacce: TenantResolverInterface, ecc.
  Console/
    Commands/      — InstallCommand: php artisan saas-core:install

config/saas-core.php     — Config centrale pubblicabile
routes/passkeys.php      — Route WebAuthn
routes/mobile.php        — Route API mobile
routes/recovery.php      — Route magic link recovery
routes/totp.php          — Route TOTP 2FA
database/migrations/     — Migration users, activity_log, tenant, permessi, TOTP
```

## Layer di sicurezza implementati

| Layer | Componente | Status |
|-------|-----------|--------|
| L2 | SecurityHeaders middleware (CSP nonce, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy) | ✅ |
| L2 | SaasCorePreset (spatie/laravel-csp) | ✅ |
| L3 | PasskeyController — login WebAuthn | ✅ |
| L3 | PasskeyRegistrationController — registrazione WebAuthn | ✅ |
| L3 | PasskeyManagementController — gestione passkey profilo | ✅ |
| L3 | RecoveryController — magic link recovery | ✅ |
| L3 | TotpSetupController — enrollment TOTP | ✅ |
| L3 | TotpChallengeController — verifica codice 6 cifre | ✅ |
| L3 | TotpMiddleware — enforcement TOTP per ruolo | ✅ |
| L3 | RefreshTokenRotator — Sanctum token rotation 24h/30d | ✅ |
| L3 | SessionHardener middleware | ✅ |
| L3 | Rate limiting: login (5/min), api (100/min), recovery (3/10min) | ✅ |
| L4 | TenantScope — Global Scope Eloquent tenant isolation | ✅ |
| L4 | RequireRole middleware (spatie/laravel-permission) | ✅ |
| L4 | SaasPolicy — base class per Laravel Policy | ✅ |
| L5 | EncryptedString cast — AES-256-CBC per campi sensibili | ✅ |
| L6 | Auditable trait + activitylog (7 anni, GDPR financial) | ✅ |
| L6 | GitHub Actions CI (test + composer audit) | ✅ |

## Chiavi config principali (saas-core.php)

```php
'auth.passkeys'          // abilita route WebAuthn
'auth.totp'              // abilita 2FA TOTP
'auth.totp_required_roles' // ruoli che devono configurare il TOTP
'auth.token_ttl'         // 86400s — access token mobile
'auth.refresh_ttl'       // 2592000s — refresh token mobile
'auth.session_lifetime'  // 120 minuti
'auth.redirect_after_login' // '/dashboard'
'tenancy.resolver'       // 'subdomain' | 'header'
'security.rate_limit.login'    // [5, 1]
'security.rate_limit.api'      // [100, 1]
'security.rate_limit.recovery' // [3, 10]
'security.csp_nonce'     // true
'data.encrypt_log'       // true — cifra activity log
```

## Dipendenze chiave

| Package | Perché |
|---------|--------|
| laravel/fortify | TwoFactorAuthenticationProvider per TOTP |
| laravel/sanctum | Token API mobile |
| asbiin/laravel-webauthn | WebAuthn / passkey |
| spatie/laravel-permission | RBAC |
| spatie/laravel-activitylog | Audit log |
| spatie/laravel-csp | Content Security Policy |

**Suggest (opzionali, app-level):**
`bacon/bacon-qr-code` — QR code TOTP lato backend · `sentry/sentry-laravel` — error tracking ·
`logtail/monolog-logtail` — log centralizzati · `spatie/laravel-backup` — backup S3 WORM

## Test

**209/209** passing (PostgreSQL). Struttura:

```
tests/
  Feature/
    PasskeyControllerTest.php      — login WebAuthn (challenge + verify)
    PasskeyRegistrationTest.php    — registrazione WebAuthn 2-step
    PasskeyManagementTest.php      — gestione passkey profilo utente
    RecoveryTest.php               — magic link recovery
    SecurityHeadersTest.php        — header HTTP di sicurezza
    SessionHardenerTest.php        — inattività, rotazione sessione
    SetTenantMiddlewareTest.php    — tenant isolation middleware
    TotpSetupTest.php              — enrollment TOTP (setup/confirm/disable)
    TotpChallengeTest.php          — verifica codice login + recovery codes
    TotpMiddlewareTest.php         — enforcement TOTP per ruolo
  Unit/
    AuditableTraitTest.php         — logging create/update/delete
    CspPolicyTest.php              — CSP nonce e direttive
    DeviceTokenServiceTest.php     — token mobile + rotation
    EncryptedStringCastTest.php    — cast AES-256-CBC + field binding
    GdprEraserTest.php             — anonimizzazione GDPR
    PasskeyChallengeTest.php       — WebAuthn challenge storage
    RoleSeederTest.php             — seeding ruoli
    SaasPolicyTest.php             — sameTenant / isSuperAdmin / isTenantAdmin
    TenancyResolversTest.php       — subdomain e header resolver
```

```bash
./vendor/bin/pest                     # intera suite
./vendor/bin/pest --filter=Totp       # solo test TOTP
./vendor/bin/pest --filter=Passkey    # solo test passkey
./vendor/bin/pest --filter=Encrypted  # solo encrypted cast
```

## Comandi utili

```bash
php artisan saas-core:install          # setup guidato
php artisan vendor:publish --tag=saas-core-config
php artisan vendor:publish --tag=saas-core-migrations
php artisan vendor:publish --tag=saas-core-stubs
```

## Note architetturali importanti

- **Route + middleware `web`**: le route caricate via `loadRoutesFrom()` da un ServiceProvider
  NON ricevono automaticamente il gruppo `web`. Tutte le route passkey e TOTP includono
  esplicitamente `['web', ...]` per garantire `StartSession`.

- **`Webauthn::swap(Mockery::mock())`** nei test: `PublicKeyCredentialCreationOptions` è
  `final` e non può essere mock-ata con `Facade::shouldReceive()`. `swap()` con un mock
  puro bypassa il controllo del tipo restituito.

- **PostgreSQL `FOR UPDATE` con aggregati**: `lockForUpdate()->count()` non è supportato
  da PostgreSQL. Usare sempre `lockForUpdate()->get(['id'])->count()`.

- **MySQL vs PostgreSQL**: `ALTER TABLE ADD COLUMN IF NOT EXISTS` è PostgreSQL-only.
  Usare sempre `Schema::hasColumn()` nelle migration idempotenti.

- **Origin WebAuthn nativi (mobile)**: le passkey Android inviano origin
  `android:apk-key-hash:<base64url>` che webauthn-lib rifiuta di default.
  `auth.passkey_origins` (env `WEBAUTHN_ALLOWED_ORIGINS`) attiva
  `CheckAllowedOrigins` via extend della `CeremonyStepManagerFactory`;
  APP_URL viene sempre incluso o il login browser si rompe.

- **Route Fortify disattivate di default** (`auth.fortify_routes => false`):
  Fortify serve solo come libreria TOTP. Le sue route `/login` ecc. confliggono
  con quelle del package e su Laravel fresh causano `BindingResolutionException`.
  `Fortify::ignoreRoutes()` è chiamato in `register()` (gira prima di ogni `boot()`).

- **InstallCommand fa MERGE, non copia, di `package.json` e `app.css`**:
  su Laravel fresh esistono sempre — una copia skip-if-exists li salterebbe
  e la build Vite fallirebbe per dipendenze/stili mancanti.

- **Tenancy `composite`**: resolver per SaaS web+mobile insieme — prova prima
  l'header `X-Tenant-ID` (mobile), poi il sottodominio (web).
