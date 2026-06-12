import { ref }    from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * usePasskeyManagement — composable per gestire le passkey di un utente autenticato
 *
 * OPERAZIONI:
 *   loadKeys()         → ricarica la lista via Inertia reload (partial: initialKeys)
 *   addKey(keyName)    → step 1: GET  /profile/passkeys/options  (challenge)
 *                        step 2: navigator.credentials.create()  (biometrica)
 *                        step 3: POST /profile/passkeys           (salva chiave)
 *   removeKey(id)      → DELETE /profile/passkeys/:id
 *
 * DIFFERENZA CON usePasskeyRegister:
 *   - L'utente è già autenticato: nessun email/nome da inserire
 *   - Non si crea un nuovo utente: si aggiunge solo una chiave all'account esistente
 *   - Dopo addKey() ricarica la lista via Inertia (no AJAX diretto)
 *
 * UTILIZZO:
 *   Il backend deve passare le chiavi come prop Inertia `initialKeys`:
 *
 *   Route::get('/profile/passkeys', function () {
 *       $keys = WebauthnKey::where('user_id', auth()->id())...->map(...);
 *       return Inertia::render('Profile/Passkeys', ['initialKeys' => $keys]);
 *   });
 *
 *   <script setup lang="ts">
 *   const { keys, loading, error, addKey, removeKey } = usePasskeyManagement(props.initialKeys)
 *   </script>
 */

export interface PasskeyEntry {
    id:            number
    name:          string
    type:          string
    registered_at: string
}

export function usePasskeyManagement (initialKeys: PasskeyEntry[] = []) {
    const keys    = ref<PasskeyEntry[]>(initialKeys)
    const loading = ref(false)
    const error   = ref<string | null>(null)

    // ---------------------------------------------------------------------------
    // loadKeys — ricarica la lista tramite Inertia (aggiorna solo la prop initialKeys)
    // ---------------------------------------------------------------------------

    async function loadKeys (): Promise<void> {
        return new Promise((resolve) => {
            loading.value = true
            router.reload({
                only:      ['initialKeys'],
                onSuccess: (page) => {
                    keys.value    = (page.props.initialKeys as PasskeyEntry[]) ?? []
                    loading.value = false
                    resolve()
                },
                onError: () => {
                    error.value   = 'Impossibile ricaricare le passkey.'
                    loading.value = false
                    resolve()
                },
            })
        })
    }

    // ---------------------------------------------------------------------------
    // addKey — aggiunge una nuova passkey (secondo dispositivo, backup, ecc.)
    // ---------------------------------------------------------------------------

    async function addKey (keyName?: string): Promise<boolean> {
        if (! window.PublicKeyCredential) {
            error.value = 'Il tuo browser non supporta le passkey.'
            return false
        }

        loading.value = true
        error.value   = null

        try {
            // STEP 1: ottieni le opzioni di attestazione (challenge) dal server
            const optionsRes = await fetch('/profile/passkeys/options', {
                headers: {
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
            })

            if (! optionsRes.ok) {
                error.value = 'Impossibile preparare la registrazione.'
                return false
            }

            const creationOptions = await optionsRes.json()

            // STEP 2: chiedi al dispositivo di creare una nuova passkey
            const credential = await navigator.credentials.create({
                publicKey: deserializeCreationOptions(creationOptions),
            }) as PublicKeyCredential | null

            if (! credential) {
                error.value = 'Registrazione annullata.'
                return false
            }

            // STEP 3: manda la chiave pubblica al server
            const storeRes = await fetch('/profile/passkeys', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
                body: JSON.stringify({
                    response: serializeAttestation(credential),
                    key_name: keyName ?? getDeviceName(),
                }),
            })

            if (! storeRes.ok) {
                const body = await storeRes.json().catch(() => ({}))
                error.value = body.message ?? 'Registrazione fallita. Riprova.'
                return false
            }

            // Aggiorna la lista via Inertia reload
            await loadKeys()
            return true

        } catch (e) {
            if (e instanceof DOMException && e.name === 'NotAllowedError') {
                error.value = 'Registrazione annullata. Riprova quando sei pronto.'
            } else if (e instanceof DOMException && e.name === 'InvalidStateError') {
                error.value = 'Questo dispositivo ha già una passkey registrata per il tuo account.'
            } else {
                error.value = 'Errore imprevisto. Riprova tra qualche secondo.'
            }
            return false
        } finally {
            loading.value = false
        }
    }

    // ---------------------------------------------------------------------------
    // renameKey — rinomina una passkey (nome auto-rilevato può non corrispondere)
    // ---------------------------------------------------------------------------

    async function renameKey (id: number, name: string): Promise<boolean> {
        loading.value = true
        error.value   = null

        try {
            const res = await fetch(`/profile/passkeys/${id}`, {
                method:  'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
                body: JSON.stringify({ name }),
            })

            if (! res.ok) {
                const body = await res.json().catch(() => ({}))
                error.value = body.message ?? 'Impossibile rinominare la passkey.'
                return false
            }

            const entry = keys.value.find(k => k.id === id)
            if (entry) entry.name = name
            return true

        } catch {
            error.value = 'Errore di connessione.'
            return false
        } finally {
            loading.value = false
        }
    }

    // ---------------------------------------------------------------------------
    // removeKey — elimina una passkey (device smarrito o rubato)
    // ---------------------------------------------------------------------------

    async function removeKey (id: number): Promise<boolean> {
        loading.value = true
        error.value   = null

        try {
            const res = await fetch(`/profile/passkeys/${id}`, {
                method:  'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
            })

            if (! res.ok) {
                const body = await res.json().catch(() => ({}))
                error.value = body.message ?? 'Impossibile eliminare la passkey.'
                return false
            }

            // Rimuove dalla lista locale senza ricaricare dal server
            keys.value = keys.value.filter(k => k.id !== id)
            return true

        } catch {
            error.value = 'Errore di connessione.'
            return false
        } finally {
            loading.value = false
        }
    }

    return { keys, loading, error, loadKeys, addKey, renameKey, removeKey }
}

// ---------------------------------------------------------------------------
// Helpers privati (stessi di usePasskeyRegister.ts)
// ---------------------------------------------------------------------------

function csrfToken (): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

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

function getDeviceName (): string {
    const ua = navigator.userAgent
    if (/iPhone|iPad/i.test(ua)) return 'iPhone/iPad'
    if (/Android/i.test(ua))      return 'Android'
    if (/Mac/i.test(ua))          return 'Mac'
    if (/Windows/i.test(ua))      return 'Windows'
    return 'Dispositivo ' + new Date().toLocaleDateString('it-IT')
}
