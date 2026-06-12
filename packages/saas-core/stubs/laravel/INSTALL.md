# Installazione saas/core

## Requisiti

| Tecnologia | Versione minima |
|---|---|
| PHP | 8.3+ |
| Laravel | 11 / 12 / 13 |
| PostgreSQL | 14+ |
| Node.js | 20+ |

---

## 1. Aggiungi il package

**Da GitHub (produzione):**

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/webby-developers/saas-core.git"
    }
],
"require": {
    "saas/core": "dev-main"
}
```

**Da path locale (sviluppo):**

```json
"repositories": [
    {
        "type": "path",
        "url": "../Core",
        "options": { "symlink": true }
    }
],
"require": {
    "saas/core": "@dev"
}
```

```bash
composer install
```

---

## 2. Configura il database

In `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=il_tuo_db
DB_USERNAME=postgres
DB_PASSWORD=la_tua_password
```

---

## 3. Esegui il comando di installazione

**Solo backend (PHP):**

```bash
php artisan saas-core:install
```

**Con frontend Vue 3 + Inertia.js + PrimeVue + Tailwind v4:**

```bash
php artisan saas-core:install --with-vue
```

**Con app mobile React Native/Expo:**

```bash
php artisan saas-core:install --with-react-native
```

Il comando fa tutto in automatico:

| Operazione | Dettaglio |
|---|---|
| Pubblica config | `config/saas-core.php` |
| Pubblica migration | Tutte le tabelle del core |
| Esegue migration | `php artisan migrate` |
| Copia User model | Chiede conferma se esiste già |
| Copia AppServiceProvider | Avverte se esiste già |
| Copia route stub | `routes/web.php` e `routes/api.php` |
| Crea ruoli | `admin` e `user` nel database |
| Copia frontend | Pages, composables, layouts, CSS, TS (solo `--with-vue`) |
| Copia mobile | App React Native/Expo in `mobile/` (solo `--with-react-native`) |

### Opzioni

```bash
php artisan saas-core:install --force       # sovrascrive tutto senza chiedere
php artisan saas-core:install --no-migrate  # salta le migration
php artisan saas-core:install --no-seed     # salta il seeder dei ruoli
php artisan saas-core:install --with-vue    # installa anche il frontend Vue
php artisan saas-core:install --with-react-native # installa anche l'app mobile Expo
```

---

## 4. Installa le dipendenze frontend (solo se usi --with-vue)

```bash
npm install tailwindcss-primeui
npm install
npm run dev
```

## 4b. Installa l'app mobile (solo se usi --with-react-native)

```bash
cd mobile
npm install
cp .env.example .env
npm run ios      # oppure: npm run android
```

> **Perché `tailwindcss-primeui`?**
> È il ponte ufficiale tra PrimeVue e Tailwind v4. Senza di esso le classi come
> `bg-primary`, `text-surface-500`, `border-surface-200` non esistono come utility Tailwind.

---

## 5. Configura WebAuthn (passkeys)

Nel file `.env` aggiungi:

```env
WEBAUTHN_NAME="Nome del tuo SaaS"
WEBAUTHN_ID=tuosaas.com
```

> `WEBAUTHN_ID` deve essere il dominio principale senza protocollo e senza `www`.
> In locale usa `localhost`.

Le passkey richiedono **HTTPS**. Con Laravel Herd:

```bash
herd secure nome-progetto
```

---

## 6. Personalizza saas-core.php

```php
// config/saas-core.php

'tenancy' => [
    'resolver' => 'subdomain', // o 'header' per React Native
],

'auth' => [
    'passkeys'         => true,
    'session_lifetime' => 120,  // minuti di inattività
    'token_ttl'        => 86400,    // access token mobile (secondi)
    'refresh_ttl'      => 2592000,  // refresh token mobile (secondi)
],

'access' => [
    'roles'        => ['admin', 'user'], // ruoli creati dal seeder
    'default_role' => 'user',           // assegnato alla registrazione
],

'audit' => [
    'retention_years'  => 7,
    'financial_models' => [
        \App\Models\Invoice::class,
        \App\Models\Payment::class,
    ],
],

'security' => [
    'rate_limit' => [
        'login' => [5, 1],   // 5 tentativi al minuto
        'api'   => [100, 1], // 100 request al minuto
    ],
    'hsts_max_age' => 31536000, // 1 anno
    'csp_nonce'    => true,
],
```

---

## 7. Aggiungi i middleware alle route

```php
// routes/web.php

// Route pubbliche (login, registrazione)
Route::middleware(['web', 'security.headers'])->group(function () {
    Route::get('/login',    fn () => Inertia::render('Auth/Login'))->name('login');
    Route::get('/register', fn () => Inertia::render('Auth/Register'))->name('register');
});

// Route autenticate con multi-tenancy
Route::middleware(['web', 'auth', 'tenant', 'session.hardener', 'security.headers'])
    ->group(function () {
        Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
    });

// Route solo admin
Route::middleware(['web', 'auth', 'tenant', 'session.hardener', 'security.headers', 'role:admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/', fn () => Inertia::render('Admin/Dashboard'));
    });
```

### Middleware disponibili

| Alias | Classe | Descrizione |
|---|---|---|
| `tenant` | `SetTenant` | Risolve tenant dal sottodominio o header X-Tenant-ID |
| `role:nomeruolo` | `RequireRole` | Blocca se l'utente non ha il ruolo specificato |
| `security.headers` | `SecurityHeaders` | Aggiunge X-Frame-Options, HSTS, CSP, ecc. |
| `session.hardener` | `SessionHardener` | Scade la sessione dopo inattività, regenera token |

---

## Struttura file copiati da --with-vue

```
resources/
├── css/
│   └── app.css                         ← Tailwind v4 + tailwindcss-primeui
└── js/
    ├── app.ts                           ← Bootstrap Vue + Inertia + PrimeVue Aura
    ├── composables/
    │   ├── usePasskey.ts                ← Login con passkey (navigator.credentials.get)
    │   └── usePasskeyRegister.ts        ← Registrazione passkey (navigator.credentials.create)
    ├── Layouts/
    │   └── AppLayout.vue                ← Layout con Menubar PrimeVue
    └── Pages/
        ├── Auth/
        │   ├── Login.vue                ← Pagina login
        │   └── Register.vue             ← Pagina registrazione
        └── Dashboard.vue                ← Dashboard autenticata

vite.config.ts                           ← Vite 8 + @tailwindcss/vite + laravel-vite-plugin
```
