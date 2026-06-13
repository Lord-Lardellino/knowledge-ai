import { ref }    from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * useDocuments — gestione documenti della knowledge base (utente autenticato).
 *
 * Il backend passa la lista come prop Inertia `initialDocuments`. Le azioni
 * (upload/elimina) usano gli endpoint JSON e poi ricaricano la prop via Inertia.
 *
 *   upload(file)  → POST   /documents   (multipart)
 *   remove(id)    → DELETE /documents/:id
 *   reload()      → router.reload({ only: ['initialDocuments'] })
 */

export type DocumentStatus = 'pending' | 'processing' | 'indexed' | 'failed'

export interface DocumentEntry {
    id:                number
    title:             string
    original_filename: string
    extension:         string
    mime_type:         string
    size_bytes:        number
    status:            DocumentStatus
    chunk_count:       number
    indexed_at:        string | null
    created_at:        string
}

export function useDocuments (initialDocuments: DocumentEntry[] = []) {
    const documents = ref<DocumentEntry[]>(initialDocuments)
    const uploading = ref(false)
    const error     = ref<string | null>(null)

    function reload (): Promise<void> {
        return new Promise((resolve) => {
            router.reload({
                only:      ['initialDocuments'],
                onSuccess: (page) => {
                    documents.value = (page.props.initialDocuments as DocumentEntry[]) ?? []
                    resolve()
                },
                onFinish:  () => resolve(),
            })
        })
    }

    async function upload (file: File): Promise<boolean> {
        uploading.value = true
        error.value     = null

        try {
            const form = new FormData()
            form.append('file', file)

            const res = await fetch('/documents', {
                method:  'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
                body: form,
            })

            if (! res.ok) {
                const body = await res.json().catch(() => ({}))
                error.value = body.message
                    ?? body.errors?.file?.[0]
                    ?? 'Caricamento fallito. Riprova.'
                return false
            }

            await reload()
            return true

        } catch {
            error.value = 'Errore di connessione durante il caricamento.'
            return false
        } finally {
            uploading.value = false
        }
    }

    async function remove (id: number): Promise<boolean> {
        error.value = null

        try {
            const res = await fetch(`/documents/${id}`, {
                method:  'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept':       'application/json',
                },
            })

            if (! res.ok) {
                error.value = 'Impossibile eliminare il documento.'
                return false
            }

            documents.value = documents.value.filter(d => d.id !== id)
            return true

        } catch {
            error.value = 'Errore di connessione.'
            return false
        }
    }

    return { documents, uploading, error, upload, remove, reload }
}

function csrfToken (): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}
