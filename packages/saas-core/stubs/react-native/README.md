# SaaS Core React Native Auth Stub

Stub mobile auth-only per testare il core Laravel con React Native/Expo.

Include solo:

- Login con passkey nativa.
- Register con passkey nativa.
- Recovery email.
- Logout mobile Sanctum.

Non include task, documenti, workflow o logica prodotto.

## Avvio

Le passkey native non funzionano in Expo Go: serve una dev build o una build EAS.

```bash
cd mobile
npm install
cp .env.example .env
npx expo prebuild --platform android
npx expo run:android        # build + install sul device collegato
```

In alternativa, build manuale con Gradle (non richiede Android Studio aggiornato):

```bash
cd android
./gradlew assembleDebug
# APK in: android/app/build/outputs/apk/debug/app-debug.apk
adb install app/build/outputs/apk/debug/app-debug.apk
```

**Importante**: prima di buildare aggiorna in `app.json`:
- `extra.EXPO_PUBLIC_API_URL` → URL HTTPS del backend
- `ios.associatedDomains` → il tuo dominio
- Il backend deve servire `/.well-known/assetlinks.json` con lo SHA-256
  del keystore di firma (`keytool -list -v -keystore android/app/debug.keystore -storepass android`)

Lato backend (`.env` Laravel) servono inoltre:

```env
# Android: origin nativo accettato da WebAuthn (vedi sotto come calcolarlo)
WEBAUTHN_ALLOWED_ORIGINS=android:apk-key-hash:<base64url-sha256-firma>
ANDROID_PACKAGE_NAME=com.tuaazienda.tuaapp
ANDROID_SHA256_FINGERPRINTS=AA:BB:CC:...
# iOS: TEAMID.bundleIdentifier per apple-app-site-association
IOS_APP_ID=AB12CD34EF.com.tuaazienda.tuaapp
```

Conversione impronta SHA-256 (hex) → apk-key-hash (base64url):

```bash
echo "AA:BB:CC:..." | tr -d ':' | xxd -r -p | basenc --base64url | tr -d '='
```

## ⚠ Sicurezza in produzione

Il `debug.keystore` di React Native è **pubblico e identico per tutti i
progetti** (impronta `FA:C6:17:45:...`). Prima di andare in produzione:

1. Firma l'app con un keystore tuo (o usa Play App Signing)
2. **Rimuovi l'impronta di debug** da `ANDROID_SHA256_FINGERPRINTS` e
   `WEBAUTHN_ALLOWED_ORIGINS` — se la lasci, qualunque app firmata con la
   chiave di debug standard può spacciarsi per la tua ed essere autorizzata
   dal tuo dominio.

## Configurazione

```env
EXPO_PUBLIC_API_URL=https://acme.example.com
EXPO_PUBLIC_TENANT_ID=acme
```

Ogni request autenticata invia:

```text
Authorization: Bearer <access_token>
X-Tenant-ID: <tenant>
X-Device-ID: <device_id>
```

## Endpoint usati

```text
POST /auth/mobile/passkey/challenge
POST /auth/mobile/passkey/verify
POST /auth/mobile/passkey/register/options
POST /auth/mobile/passkey/register
POST /recover
POST /auth/mobile/logout
POST /auth/mobile/refresh
```

## iOS

Configura `ios.associatedDomains` in `app.json`:

```json
["webcredentials:example.com"]
```

Sul dominio devi pubblicare:

```text
https://example.com/.well-known/apple-app-site-association
```

con:

```json
{
  "webcredentials": {
    "apps": ["TEAM_ID.com.yourcompany.operations"]
  }
}
```

## Android

Sul dominio devi pubblicare:

```text
https://example.com/.well-known/assetlinks.json
```

con relazione:

```json
[
  {
    "relation": [
      "delegate_permission/common.handle_all_urls",
      "delegate_permission/common.get_login_creds"
    ],
    "target": {
      "namespace": "android_app",
      "package_name": "com.yourcompany.operations",
      "sha256_cert_fingerprints": ["SHA256_CERT_FINGERPRINT"]
    }
  }
]
```

Sostituisci dominio, package, Team ID e fingerprint con quelli reali.
