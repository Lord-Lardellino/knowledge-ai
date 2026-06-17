import { ref }    from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * useMatters — gestione pratiche legali (verticale legale).
 *
 * Il backend passa la lista come prop Inertia `initialMatters`. Le azioni
 * (crea/aggiorna/elimina) usano gli endpoint JSON e poi ricaricano la prop.
 *
 *   create(payload) → POST   /matters
 *   update(id, p)   → PUT    /matters/:id
 *   remove(id)      → DELETE /matters/:id
 *   reload()        → router.reload({ only: ['initialMatters'] })
 */

export type MatterStatus = 'open' | 'suspended' | 'closed' | 'archived'

export interface MatterType {
    id:        number
    key:       string
    label:     string
    is_system: boolean
}

export interface MatterParty {
    party_id: number
    role:     string | null
}

export interface MatterEntry {
    id:              number
    client_id:       number
    matter_type_id:  number | null
    reference:       string | null
    title:           string
    status:          MatterStatus
    value_cents:     number | null
    opened_at:       string | null
    created_at:      string
    client?:         { id: number; name: string } | null
    type?:           { id: number; label: string } | null
    documents_count?: number
}

export interface MatterPayload {
    client_id:      number | null
    matter_type_id: number | null
    title:          string
    reference:      string | null
    status:         MatterStatus
    outcome:        string | null
    value_cents:    number | null
    opened_at:      string | null
    closed_at:      string | null
    notes:          string | null
    parties:        MatterParty[]
}

export function useMatters (initialMatters: MatterEntry[] = []) {
    const matters = ref<MatterEntry[]>(initialMatters)
    const saving  = ref(false)
    const error   = ref<string | null>(null)

    function reload (): Promise<void> {
        return new Promise((resolve) => {
            router.reload({
                only:      ['initialMatters'],
                onSuccess: (page) => {
                    matters.value = (page.props.initialMatters as MatterEntry[]) ?? []
                    resolve()
                },
                onFinish:  () => resolve(),
            })
        })
    }

    async function create (payload: MatterPayload): Promise<boolean> {
        return await send('/matters', 'POST', payload)
    }

    async function update (id: number, payload: MatterPayload): Promise<boolean> {
        return await send(`/matters/${id}`, 'PUT', payload)
    }

    async function send (url: string, method: string, payload: MatterPayload): Promise<boolean> {
        saving.value = true
        error.value  = null

        try {
            const res = await fetch(url, {
                method,
                headers: jsonHeaders(),
                body:    JSON.stringify(payload),
            })

            if (! res.ok) {
                const body = await res.json().catch(() => ({}))
                error.value = body.message ?? firstError(body) ?? 'Salvataggio fallito.'
                return false
            }

            await reload()
            return true
        } catch {
            error.value = 'Errore di connessione.'
            return false
        } finally {
            saving.value = false
        }
    }

    async function remove (id: number): Promise<boolean> {
        error.value = null
        try {
            const res = await fetch(`/matters/${id}`, { method: 'DELETE', headers: jsonHeaders() })
            if (! res.ok) {
                error.value = 'Impossibile eliminare la pratica.'
                return false
            }
            matters.value = matters.value.filter(m => m.id !== id)
            return true
        } catch {
            error.value = 'Errore di connessione.'
            return false
        }
    }

    return { matters, saving, error, create, update, remove, reload }
}

/** Carica i clienti del tenant (per i select). */
export async function fetchClients (): Promise<Array<{ id: number; name: string }>> {
    const res = await fetch('/clients/options', { headers: { Accept: 'application/json' } })
    return res.ok ? await res.json() : []
}

/** Crea un cliente al volo. Restituisce il record creato o null. */
export async function createClient (payload: Record<string, unknown>): Promise<{ id: number; name: string } | null> {
    const res = await fetch('/clients', { method: 'POST', headers: jsonHeaders(), body: JSON.stringify(payload) })
    return res.ok ? await res.json() : null
}

/** Carica le controparti del tenant (per i select). */
export async function fetchParties (): Promise<Array<{ id: number; name: string }>> {
    const res = await fetch('/parties', { headers: { Accept: 'application/json' } })
    return res.ok ? await res.json() : []
}

/** Crea una controparte al volo. Restituisce il record creato o null. */
export async function createParty (payload: Record<string, unknown>): Promise<{ id: number; name: string } | null> {
    const res = await fetch('/parties', { method: 'POST', headers: jsonHeaders(), body: JSON.stringify(payload) })
    return res.ok ? await res.json() : null
}

function jsonHeaders (): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        'Accept':       'application/json',
        'X-CSRF-TOKEN': csrfToken(),
    }
}

function firstError (body: { errors?: Record<string, string[]> }): string | null {
    const errors = body.errors
    if (! errors) return null
    const first = Object.values(errors)[0]
    return Array.isArray(first) ? first[0] : null
}

function csrfToken (): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}
