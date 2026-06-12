import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useTotp } from '@/composables/useTotp'

/**
 * usePasskey — composable per l'autenticazione con passkey (WebAuthn)
 *
 * COME FUNZIONA IL FLUSSO:
 *
 *   1. challenge(): chiede al backend un token casuale (challenge)
 *      Il backend lo salva in cache con TTL 5 minuti.
 *
 *   2. Il browser chiama l'API WebAuthn nativa (navigator.credentials.get)
 *      con la challenge e il rpId (dominio dell'app).
 *      Il dispositivo (Face ID / Touch ID / Windows Hello) firma la challenge.
 *
 *   3. verify(): manda la firma al backend che la verifica.
 *      Se valida, il backend crea la sessione e risponde con redirect.
 *      Inertia aggiorna la pagina senza full reload.
 *
 * UTILIZZO NEL COMPONENTE:
 *   <script setup lang="ts">
 *   const { login, loading, error } = usePasskey()
 *   </script>
 *
 *   <template>
 *     <Button @click="login(email)" :loading="loading" label="Accedi con passkey" />
 *     <Message v-if="error" severity="error">{{ error }}</Message>
 *   </template>
 *
 * PREREQUISITI:
 *   - HTTPS (obbligatorio per WebAuthn — localhost è escluso per sviluppo)
 *   - Il browser deve supportare WebAuthn (tutti i browser moderni dal 2019)
 *   - Il dispositivo deve avere un authenticator (Face ID, Touch ID, Windows Hello)
 */
export function usePasskey () {
    const loading = ref(false)
    const error   = ref<string | null>(null)
    const { checkStatus } = useTotp()

    /**
     * Avvia il flusso di login con passkey.
     *
     * POST-LOGIN TOTP:
     *   Dopo il verify, controlla se il TOTP è richiesto per questo utente.
     *   Se sì, naviga a /auth/totp invece del dashboard.
     *   Questo permette il flusso: passkey → TOTP → dashboard.
     *
     * @param email      Email dell'utente
     * @param redirectTo Dove andare dopo il login (default: /dashboard)
     */
    async function login (email: string, redirectTo = '/dashboard'): Promise<void> {
        if (!email) {
            error.value = 'Inserisci la tua email prima di procedere.'
            return
        }

        // Controlla che il browser supporti WebAuthn
        if (!window.PublicKeyCredential) {
            error.value = 'Il tuo browser non supporta le passkey. Aggiornalo o usa un browser moderno.'
            return
        }

        loading.value = true
        error.value   = null

        try {
            // STEP 1: ottieni la challenge dal backend
            const challengeRes = await fetch('/auth/passkey/challenge', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
                body: JSON.stringify({ email }),
            })

            if (!challengeRes.ok) {
                error.value = 'Impossibile contattare il server. Riprova tra qualche secondo.'
                return
            }

            const { challenge, rpId, timeout } = await challengeRes.json()

            // STEP 2: chiedi al dispositivo di firmare la challenge
            // Questo apre il prompt biometrico (Face ID, Touch ID, ecc.)
            const credential = await navigator.credentials.get({
                publicKey: {
                    challenge:        base64ToBuffer(challenge),
                    rpId,
                    timeout,
                    userVerification: 'required',
                },
            }) as PublicKeyCredential | null

            if (!credential) {
                error.value = 'Autenticazione annullata.'
                return
            }

            // STEP 3: manda la firma al backend per la verifica
            const verifyRes = await fetch('/auth/passkey/verify', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
                body: JSON.stringify({
                    email,
                    response: serializeCredential(credential),
                }),
            })

            if (!verifyRes.ok) {
                error.value = 'Passkey non riconosciuta. Riprova o contatta il supporto.'
                return
            }

            // Login riuscito — controlla se il TOTP è richiesto prima di navigare
            const { totp_required } = await checkStatus()
            router.visit(totp_required ? '/auth/totp' : redirectTo)

        } catch (e) {
            if (e instanceof DOMException && e.name === 'NotAllowedError') {
                // L'utente ha rifiutato il prompt biometrico o ha atteso troppo
                error.value = 'Autenticazione annullata. Riprova quando sei pronto.'
            } else {
                error.value = 'Errore imprevisto. Riprova tra qualche secondo.'
                console.error('[usePasskey] errore:', e)
            }
        } finally {
            loading.value = false
        }
    }

    return { login, loading, error }
}

// ---------------------------------------------------------------------------
// Helpers privati
// ---------------------------------------------------------------------------

/** Legge il CSRF token dal meta tag che Laravel inietta nel layout Blade */
function csrfToken (): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

/**
 * Converte una stringa base64 in ArrayBuffer.
 * La challenge arriva dal backend come stringa base64 — WebAuthn vuole ArrayBuffer.
 */
function base64ToBuffer (base64: string): ArrayBuffer {
    const binary = atob(base64.replace(/-/g, '+').replace(/_/g, '/'))
    const bytes  = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i)
    }
    return bytes.buffer
}

/**
 * Serializza la PublicKeyCredential in un oggetto JSON-serializzabile.
 * navigator.credentials.get() restituisce un oggetto non-serializzabile
 * che va convertito manualmente prima di mandarlo al backend.
 */
function serializeCredential (credential: PublicKeyCredential): Record<string, unknown> {
    const response = credential.response as AuthenticatorAssertionResponse
    return {
        id:    credential.id,
        type:  credential.type,
        rawId: bufferToBase64(credential.rawId),
        response: {
            authenticatorData: bufferToBase64(response.authenticatorData),
            clientDataJSON:    bufferToBase64(response.clientDataJSON),
            signature:         bufferToBase64(response.signature),
            userHandle:        response.userHandle ? bufferToBase64(response.userHandle) : null,
        },
    }
}

/** Converte ArrayBuffer in stringa base64 URL-safe */
function bufferToBase64 (buffer: ArrayBuffer): string {
    return btoa(String.fromCharCode(...new Uint8Array(buffer)))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=/g, '')
}
