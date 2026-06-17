import { ref }    from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * useClients — anagrafica clienti (verticale legale).
 *
 *   create(payload)   → POST /clients
 *   update(id, p)     → PUT  /clients/:id
 *   reload()          → router.reload({ only: ['initialClients'] })
 */

export type ClientType = 'person' | 'company'

export interface ClientEntry {
    id:             number
    name:           string
    type:           ClientType
    email:          string | null
    phone:          string | null
    created_at?:    string
    matters_count?: number
}

export interface ClientPayload {
    name:     string
    type:     ClientType
    tax_code: string | null
    vat:      string | null
    email:    string | null
    phone:    string | null
    notes:    string | null
}

export function emptyClient (): ClientPayload {
    return { name: '', type: 'company', tax_code: null, vat: null, email: null, phone: null, notes: null }
}

export function useClients (initialClients: ClientEntry[] = []) {
    const clients = ref<ClientEntry[]>(initialClients)
    const saving  = ref(false)
    const error   = ref<string | null>(null)

    function reload (): Promise<void> {
        return new Promise((resolve) => {
            router.reload({
                only:      ['initialClients'],
                onSuccess: (page) => {
                    clients.value = (page.props.initialClients as ClientEntry[]) ?? []
                    resolve()
                },
                onFinish:  () => resolve(),
            })
        })
    }

    async function create (payload: ClientPayload): Promise<boolean> {
        return await send('/clients', 'POST', payload)
    }

    async function update (id: number, payload: ClientPayload): Promise<boolean> {
        return await send(`/clients/${id}`, 'PUT', payload)
    }

    async function send (url: string, method: string, payload: ClientPayload): Promise<boolean> {
        saving.value = true
        error.value  = null
        try {
            const res = await fetch(url, { method, headers: jsonHeaders(), body: JSON.stringify(payload) })
            if (! res.ok) {
                const body = await res.json().catch(() => ({}))
                error.value = body.message ?? 'Salvataggio fallito.'
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

    return { clients, saving, error, create, update, reload }
}

function jsonHeaders (): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        'Accept':       'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
    }
}
