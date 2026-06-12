import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * useTotp — composable per tutte le operazioni TOTP
 *
 * OPERAZIONI CHALLENGE (post-login):
 *   verifyCode(code)   → POST /auth/totp/challenge
 *   checkStatus()      → GET  /auth/totp/status
 *
 * OPERAZIONI SETUP (profilo):
 *   fetchSetup()       → GET  /profile/totp/setup  (genera segreto + QR URL)
 *   confirmCode(code)  → POST /profile/totp/confirm (attiva TOTP + recovery codes)
 *   disableTotp(code)  → POST /profile/totp/disable (disabilita TOTP)
 */
export function useTotp () {
    const loading       = ref(false)
    const error         = ref<string | null>(null)
    const secret        = ref<string | null>(null)
    const qrUrl         = ref<string | null>(null)
    const recoveryCodes = ref<string[]>([])

    // ---------------------------------------------------------------------------
    // Challenge — verifica il codice TOTP durante il login
    // ---------------------------------------------------------------------------

    /**
     * Verifica il codice 6 cifre (o recovery code) e naviga al dashboard.
     * Da usare in Auth/TotpChallenge.vue dopo il login con passkey.
     */
    async function verifyCode (code: string): Promise<void> {
        loading.value = true
        error.value   = null

        try {
            const res = await fetch('/auth/totp/challenge', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
                body: JSON.stringify({ code }),
            })

            const data = await res.json().catch(() => ({}))

            if (! res.ok) {
                error.value = data.message ?? 'Codice non valido.'
                return
            }

            // TOTP verificato — naviga al dashboard
            router.visit(data.redirect ?? '/dashboard')

        } catch {
            error.value = 'Errore di connessione. Riprova.'
        } finally {
            loading.value = false
        }
    }

    /**
     * Controlla se il TOTP è richiesto per l'utente corrente.
     * Usato in usePasskey.ts dopo il login passkey.
     */
    async function checkStatus (): Promise<{ totp_required: boolean; totp_enabled: boolean }> {
        try {
            const res = await fetch('/auth/totp/status', {
                headers: { 'Accept': 'application/json' },
            })
            if (! res.ok) return { totp_required: false, totp_enabled: false }
            return await res.json()
        } catch {
            return { totp_required: false, totp_enabled: false }
        }
    }

    // ---------------------------------------------------------------------------
    // Setup — gestione TOTP dal profilo utente
    // ---------------------------------------------------------------------------

    /**
     * Genera un nuovo segreto TOTP e l'URL per il QR code.
     * Popola secret e qrUrl nel composable — il componente li legge dal ref.
     */
    async function fetchSetup (): Promise<boolean> {
        loading.value = true
        error.value   = null

        try {
            const res  = await fetch('/profile/totp/setup', {
                headers: {
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
            })
            const data = await res.json().catch(() => ({}))

            if (! res.ok) {
                error.value = data.message ?? 'Impossibile generare il segreto TOTP.'
                return false
            }

            secret.value = data.secret
            qrUrl.value  = data.qr_url
            return true

        } catch {
            error.value = 'Errore di connessione.'
            return false
        } finally {
            loading.value = false
        }
    }

    /**
     * Conferma il primo codice TOTP — attiva il secondo fattore.
     * In caso di successo popola recoveryCodes (unica volta in chiaro).
     */
    async function confirmCode (code: string): Promise<boolean> {
        loading.value = true
        error.value   = null

        try {
            const res  = await fetch('/profile/totp/confirm', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
                body: JSON.stringify({ code }),
            })
            const data = await res.json().catch(() => ({}))

            if (! res.ok) {
                error.value = data.message ?? 'Codice non valido.'
                return false
            }

            recoveryCodes.value = data.recovery_codes ?? []
            // Reset del QR — non serve più dopo la conferma
            secret.value = null
            qrUrl.value  = null
            return true

        } catch {
            error.value = 'Errore di connessione.'
            return false
        } finally {
            loading.value = false
        }
    }

    /**
     * Disabilita il TOTP richiedendo il codice corrente.
     * Richiede il codice per prevenire che una sessione rubata disabiliti il 2FA.
     */
    async function disableTotp (code: string): Promise<boolean> {
        loading.value = true
        error.value   = null

        try {
            const res  = await fetch('/profile/totp/disable', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
                body: JSON.stringify({ code }),
            })
            const data = await res.json().catch(() => ({}))

            if (! res.ok) {
                error.value = data.message ?? 'Codice non valido.'
                return false
            }

            return true

        } catch {
            error.value = 'Errore di connessione.'
            return false
        } finally {
            loading.value = false
        }
    }

    return {
        loading, error, secret, qrUrl, recoveryCodes,
        verifyCode, checkStatus,
        fetchSetup, confirmCode, disableTotp,
    }
}

function csrfToken (): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}
