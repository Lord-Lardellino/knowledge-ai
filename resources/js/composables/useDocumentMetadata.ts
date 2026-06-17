import { router } from '@inertiajs/vue3'

/**
 * useDocumentMetadata — salvataggio/ri-estrazione dei metadati legali (Fase 2).
 *
 *   save(id, metadata) → PUT  /documents/:id/metadata        (conferma/correzione)
 *   retry(id)          → POST /documents/:id/metadata/retry  (rilancia estrazione)
 */

export type MetadataStatus = 'pending' | 'processing' | 'ready' | 'confirmed' | 'failed'

export interface DocumentMetadata {
    document_type: string
    summary:       string
    parties:   Array<{ name: string; role: string }>
    dates:     Array<{ label: string; date: string }>
    amounts:   Array<{ label: string; amount: number; currency: string }>
    citations: string[]
    clauses:   Array<{ title: string; summary: string }>
    risks:     string[]
}

export function emptyMetadata (): DocumentMetadata {
    return {
        document_type: '', summary: '', parties: [], dates: [],
        amounts: [], citations: [], clauses: [], risks: [],
    }
}

export function useDocumentMetadata () {
    async function save (id: number, metadata: DocumentMetadata): Promise<boolean> {
        const res = await fetch(`/documents/${id}/metadata`, {
            method:  'PUT',
            headers: jsonHeaders(),
            body:    JSON.stringify({ metadata }),
        })
        if (res.ok) router.reload({ only: ['matter'] })
        return res.ok
    }

    async function retry (id: number): Promise<boolean> {
        const res = await fetch(`/documents/${id}/metadata/retry`, {
            method:  'POST',
            headers: jsonHeaders(),
        })
        if (res.ok) router.reload({ only: ['matter'] })
        return res.ok
    }

    return { save, retry }
}

function jsonHeaders (): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        'Accept':       'application/json',
        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
    }
}
