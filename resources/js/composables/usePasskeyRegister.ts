import { ref } from 'vue'

/**
 * usePasskeyRegister — composable per la registrazione con passkey (WebAuthn)
 *
 * DIFFERENZA CON usePasskey (login):
 *   - Login:        il dispositivo FIRMA una challenge con una chiave ESISTENTE
 *   - Registrazione: il dispositivo CREA una nuova coppia di chiavi (pubblica/privata)
 *                    la chiave privata rimane sul dispositivo, quella pubblica va al server
 *
 * FLUSSO:
 *   1. register(name, email):
 *      → POST /auth/passkey/register/options  (crea utente, ritorna challenge + opzioni)
 *      → navigator.credentials.create()       (biometrica → crea passkey sul dispositivo)
 *      → POST /auth/passkey/register           (manda chiave pubblica al server)
 *      → Inertia.visit('/dashboard')           (login automatico)
 *
 * UTILIZZO:
 *   <script setup lang="ts">
 *   const { register, loading, error } = usePasskeyRegister()
 *   </script>
 */
export function usePasskeyRegister () {
    const loading = ref(false)
    const error   = ref<string | null>(null)

    type RegisterOptions = {
        company?: string      // self-signup: crea il tenant, l'utente diventa owner
        inviteToken?: string  // invito: entra in un tenant esistente
    }

    async function register (name: string, email: string, redirectTo = '/dashboard', options: RegisterOptions = {}): Promise<void> {
        if (!name || !email) {
            error.value = 'Inserisci nome ed email prima di procedere.'
            return
        }

        if (!window.PublicKeyCredential) {
            error.value = 'Il tuo browser non supporta le passkey. Usa Chrome, Safari o Edge aggiornati.'
            return
        }

        loading.value = true
        error.value   = null

        try {
            // STEP 1: ottieni le opzioni di attestazione dal server
            const optionsRes = await fetch('/auth/passkey/register/options', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
                body: JSON.stringify({
                    name,
                    email,
                    company:      options.company,
                    invite_token: options.inviteToken,
                }),
            })

            if (!optionsRes.ok) {
                const body = await optionsRes.json().catch(() => ({}))
                error.value = body.message ?? 'Errore durante la preparazione della registrazione.'
                return
            }

            const creationOptions = await optionsRes.json()

            // STEP 2: chiedi al dispositivo di creare una nuova passkey
            // Questo apre il prompt biometrico (Face ID, Touch ID, Windows Hello)
            // e genera una coppia di chiavi asimmetriche sul dispositivo
            const credential = await navigator.credentials.create({
                publicKey: deserializeCreationOptions(creationOptions),
            }) as PublicKeyCredential | null

            if (!credential) {
                error.value = 'Registrazione annullata.'
                return
            }

            // STEP 3: manda la chiave pubblica al server per verificarla e salvarla
            const registerRes = await fetch('/auth/passkey/register', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
                body: JSON.stringify({
                    response: serializeAttestation(credential),
                    key_name: getDeviceName(),
                }),
            })

            if (!registerRes.ok) {
                const body = await registerRes.json().catch(() => ({}))
                error.value = body.message ?? 'Registrazione fallita. Riprova.'
                return
            }

            const body = await registerRes.json().catch(() => ({}))
            const target = typeof body.redirect === 'string' ? body.redirect : redirectTo

            window.location.assign(target)

        } catch (e) {
            if (e instanceof DOMException && e.name === 'NotAllowedError') {
                error.value = 'Registrazione annullata. Riprova quando sei pronto.'
            } else if (e instanceof DOMException && e.name === 'InvalidStateError') {
                error.value = 'Questa email ha già una passkey registrata su questo dispositivo.'
            } else {
                error.value = 'Errore imprevisto. Riprova tra qualche secondo.'
                console.error('[usePasskeyRegister] errore:', e)
            }
        } finally {
            loading.value = false
        }
    }

    return { register, loading, error }
}

// ---------------------------------------------------------------------------
// Helpers privati
// ---------------------------------------------------------------------------

function csrfToken (): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

/**
 * Deserializza le opzioni dal server: converte i campi base64 in ArrayBuffer
 * che navigator.credentials.create() si aspetta.
 */
function deserializeCreationOptions (options: Record<string, any>): PublicKeyCredentialCreationOptions {
    return {
        ...options,
        challenge: base64ToBuffer(options.challenge),
        user: {
            ...options.user,
            id: base64ToBuffer(options.user.id),
        },
        excludeCredentials: (options.excludeCredentials ?? []).map((c: any) => ({
            ...c,
            id: base64ToBuffer(c.id),
        })),
    } as PublicKeyCredentialCreationOptions
}

/**
 * Serializza l'attestation response in JSON-serializzabile da mandare al server.
 */
function serializeAttestation (credential: PublicKeyCredential): Record<string, unknown> {
    const response = credential.response as AuthenticatorAttestationResponse
    return {
        id:    credential.id,
        type:  credential.type,
        rawId: bufferToBase64(credential.rawId),
        response: {
            attestationObject: bufferToBase64(response.attestationObject),
            clientDataJSON:    bufferToBase64(response.clientDataJSON),
        },
    }
}

function base64ToBuffer (base64: string): ArrayBuffer {
    const binary = atob(base64.replace(/-/g, '+').replace(/_/g, '/'))
    const bytes  = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
    return bytes.buffer
}

function bufferToBase64 (buffer: ArrayBuffer): string {
    return btoa(String.fromCharCode(...new Uint8Array(buffer)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '')
}

/** Restituisce un nome leggibile per il dispositivo corrente */
function getDeviceName (): string {
    const ua = navigator.userAgent
    if (/iPhone|iPad/i.test(ua))  return 'iPhone/iPad'
    if (/Android/i.test(ua))       return 'Android'
    if (/Mac/i.test(ua))           return 'Mac'
    if (/Windows/i.test(ua))       return 'Windows'
    return 'Dispositivo ' + new Date().toLocaleDateString('it-IT')
}
