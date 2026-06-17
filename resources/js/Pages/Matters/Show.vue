<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { useToast }     from 'primevue/usetoast'
import { useConfirm }   from 'primevue/useconfirm'
import AppLayout        from '@/Layouts/AppLayout.vue'
import MatterStatusTag  from '@/Components/MatterStatusTag.vue'
import MatterFormDialog from '@/Components/MatterFormDialog.vue'
import DocumentStatusTag from '@/Components/DocumentStatusTag.vue'
import MetadataStatusTag from '@/Components/MetadataStatusTag.vue'
import DocumentMetadataDialog from '@/Components/DocumentMetadataDialog.vue'
import { useTenantChannel } from '@/composables/useTenantChannel'
import type { MatterEntry, MatterType } from '@/composables/useMatters'
import type { DocumentMetadata, MetadataStatus } from '@/composables/useDocumentMetadata'

defineOptions({ layout: AppLayout })

interface MatterDetail extends MatterEntry {
    notes:   string | null
    outcome: string | null
    client?: { id: number; name: string; type: string; email: string | null; phone: string | null } | null
    type?:   { id: number; key: string; label: string } | null
    parties: Array<{ id: number; name: string; type: string }>
    documents: MatterDocument[]
}

interface MatterDocument {
    id: number; title: string; extension: string; status: string
    chunk_count: number; size_bytes: number; created_at: string
    metadata: DocumentMetadata | null
    metadata_status: MetadataStatus
    metadata_reviewed_at: string | null
}

const props = defineProps<{
    auth: { user: { name: string; email: string }; tenant?: { id: number; name: string } | null }
    matter:      MatterDetail
    matterTypes: MatterType[]
}>()

const toast   = useToast()
const confirm = useConfirm()

const editOpen  = ref(false)
const uploading = ref(false)

// Revisione metadati legali (Fase 2).
const metaOpen   = ref(false)
const metaDoc    = ref<MatterDocument | null>(null)

function openMetadata (doc: MatterDocument): void {
    metaDoc.value  = doc
    metaOpen.value = true
}

// --- Auto-suggerimento clienti/controparti dai metadati dei documenti ---
interface Suggestion { name: string; role: string }

const applying = ref<string | null>(null)

// Aggrega le parti estratte dai documenti, esclude quelle già nella pratica.
const suggestions = computed<Suggestion[]>(() => {
    const known = new Set<string>()
    if (matterClientName.value) known.add(matterClientName.value.toLowerCase())
    for (const p of props.matter.parties) known.add(p.name.toLowerCase())

    const seen = new Set<string>()
    const out: Suggestion[] = []
    for (const doc of props.matter.documents) {
        for (const p of doc.metadata?.parties ?? []) {
            const name = (p.name ?? '').trim()
            if (! name) continue
            const key = name.toLowerCase()
            if (known.has(key) || seen.has(key)) continue
            seen.add(key)
            out.push({ name, role: (p.role ?? '').trim() })
        }
    }
    return out
})

const matterClientName = computed(() => props.matter.client?.name ?? null)

async function applySuggestion (s: Suggestion, as: 'client' | 'party'): Promise<void> {
    applying.value = `${s.name}-${as}`
    try {
        const res = await fetch(`/matters/${props.matter.id}/suggestions`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body:    JSON.stringify({ as, name: s.name, role: s.role || null }),
        })
        if (res.ok) {
            reloadMatter()
            toast.add({ severity: 'success', summary: 'Aggiunto',
                detail: as === 'client' ? `${s.name} impostato come cliente.` : `${s.name} aggiunto come controparte.`, life: 3000 })
        } else {
            toast.add({ severity: 'error', summary: 'Errore', detail: 'Operazione fallita.', life: 5000 })
        }
    } finally {
        applying.value = null
    }
}

function reloadMatter (): void {
    router.reload({ only: ['matter'] })
}

// Real-time: aggiorna stato indicizzazione e metadati dei documenti della pratica.
useTenantChannel(props.auth.tenant?.id, {
    'document.updated': (e: { id: number; status: string; chunk_count: number; metadata_status: MetadataStatus }) => {
        const doc = props.matter.documents.find(d => d.id === e.id)
        if (doc) {
            doc.status = e.status
            doc.chunk_count = e.chunk_count
            doc.metadata_status = e.metadata_status
        }
    },
})

// Upload contestualizzato: il documento nasce già agganciato a questa pratica.
async function onUpload (event: { files: File[] }): Promise<void> {
    uploading.value = true
    for (const file of event.files) {
        const form = new FormData()
        form.append('file', file)
        form.append('matter_id', String(props.matter.id))

        const res = await fetch('/documents', {
            method:  'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body:    form,
        })

        toast.add(res.ok
            ? { severity: 'success', summary: 'Caricato', detail: `${file.name} è in elaborazione.`, life: 4000 }
            : { severity: 'error', summary: 'Errore', detail: `Caricamento di ${file.name} fallito.`, life: 5000 })
    }
    uploading.value = false
    reloadMatter()
}

function confirmRemoveDoc (doc: { id: number; title: string }): void {
    confirm.require({
        message:     `Eliminare "${doc.title}"?`,
        header:      'Elimina documento',
        icon:        'pi pi-exclamation-triangle',
        rejectLabel: 'Annulla',
        acceptLabel: 'Elimina',
        acceptClass: 'p-button-danger',
        accept: async () => {
            const res = await fetch(`/documents/${doc.id}`, {
                method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            })
            if (res.ok) reloadMatter()
            toast.add(res.ok
                ? { severity: 'success', summary: 'Eliminato', detail: `"${doc.title}" rimosso.`, life: 3000 }
                : { severity: 'error', summary: 'Errore', detail: 'Eliminazione fallita.', life: 5000 })
        },
    })
}

const extIcon: Record<string, string> = {
    pdf:  'pi pi-file-pdf text-red-500',
    docx: 'pi pi-file-word text-blue-500',
    xlsx: 'pi pi-file-excel text-green-500',
    txt:  'pi pi-file text-surface-400',
}

function formatEuro (cents: number | null): string {
    if (cents == null) return '—'
    return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(cents / 100)
}
function formatDate (iso: string | null): string {
    if (! iso) return '—'
    return new Date(iso).toLocaleDateString('it-IT', { day: 'numeric', month: 'short', year: 'numeric' })
}
function csrf (): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}
</script>

<template>
    <Head :title="matter.title" />

    <div class="mx-auto max-w-5xl space-y-6">

        <!-- Header -->
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-start gap-3 min-w-0">
                <Button icon="pi pi-arrow-left" severity="secondary" text rounded @click="router.visit('/matters')" />
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-2xl font-semibold truncate">{{ matter.title }}</h1>
                        <MatterStatusTag :status="matter.status" />
                    </div>
                    <p v-if="matter.reference" class="mt-0.5 text-sm text-surface-500">Rif. {{ matter.reference }}</p>
                </div>
            </div>
            <Button icon="pi pi-pencil" label="Modifica" severity="secondary" outlined @click="editOpen = true" />
        </div>

        <!-- Riepilogo -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Card>
                <template #content>
                    <p class="text-xs uppercase tracking-wide text-surface-400 mb-1">Cliente</p>
                    <p class="font-medium">{{ matter.client?.name ?? '—' }}</p>
                    <p v-if="matter.client?.email" class="text-sm text-surface-500">{{ matter.client.email }}</p>
                    <p v-if="matter.client?.phone" class="text-sm text-surface-500">{{ matter.client.phone }}</p>
                </template>
            </Card>
            <Card>
                <template #content>
                    <p class="text-xs uppercase tracking-wide text-surface-400 mb-1">Materia</p>
                    <p class="font-medium">{{ matter.type?.label ?? '—' }}</p>
                    <p class="text-sm text-surface-500 mt-2">Valore: {{ formatEuro(matter.value_cents) }}</p>
                    <p class="text-sm text-surface-500">Apertura: {{ formatDate(matter.opened_at) }}</p>
                </template>
            </Card>
            <Card>
                <template #content>
                    <p class="text-xs uppercase tracking-wide text-surface-400 mb-1">Controparti</p>
                    <div v-if="matter.parties.length" class="flex flex-wrap gap-1.5">
                        <Tag v-for="p in matter.parties" :key="p.id" :value="p.name" severity="secondary" rounded />
                    </div>
                    <p v-else class="text-surface-400 text-sm">Nessuna controparte</p>
                </template>
            </Card>
        </div>

        <!-- Note -->
        <Card v-if="matter.notes">
            <template #content>
                <p class="text-xs uppercase tracking-wide text-surface-400 mb-1">Note</p>
                <p class="whitespace-pre-line text-surface-700 dark:text-surface-200">{{ matter.notes }}</p>
            </template>
        </Card>

        <!-- Suggerimenti dai documenti (auto-clienti/controparti con conferma) -->
        <Card v-if="suggestions.length">
            <template #title>
                <div class="flex items-center gap-2">
                    <i class="pi pi-sparkles text-primary-500" />
                    <span>Suggerimenti dai documenti</span>
                </div>
            </template>
            <template #content>
                <p class="text-sm text-surface-500 mb-3">
                    Parti rilevate nei documenti, non ancora collegate alla pratica. Conferma per aggiungerle.
                </p>
                <div class="flex flex-col divide-y divide-surface-200 dark:divide-surface-700">
                    <div v-for="s in suggestions" :key="s.name" class="flex items-center gap-3 py-2.5">
                        <div class="min-w-0 flex-1">
                            <span class="font-medium">{{ s.name }}</span>
                            <span v-if="s.role" class="ml-2 text-xs text-surface-400">{{ s.role }}</span>
                        </div>
                        <Button label="Cliente" icon="pi pi-id-card" size="small" severity="secondary" outlined
                            :loading="applying === `${s.name}-client`" @click="applySuggestion(s, 'client')" />
                        <Button label="Controparte" icon="pi pi-users" size="small" severity="secondary" outlined
                            :loading="applying === `${s.name}-party`" @click="applySuggestion(s, 'party')" />
                    </div>
                </div>
            </template>
        </Card>

        <!-- Documenti della pratica -->
        <Card>
            <template #title>
                <div class="flex items-center justify-between">
                    <span>Documenti</span>
                    <FileUpload
                        mode="basic" name="file" :auto="true" customUpload
                        accept=".pdf,.docx,.xlsx,.txt" :showUploadButton="false" :showCancelButton="false"
                        chooseLabel="Carica" chooseIcon="pi pi-upload"
                        :disabled="uploading" @uploader="onUpload"
                    />
                </div>
            </template>
            <template #content>
                <DataTable :value="matter.documents" dataKey="id" :rows="10" paginator removableSort>
                    <template #empty>
                        <div class="text-center py-10 text-surface-400">
                            <i class="pi pi-folder-open text-3xl mb-2 block" />
                            <p>Nessun documento in questa pratica.</p>
                        </div>
                    </template>

                    <Column field="title" header="Documento" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <i :class="extIcon[data.extension] ?? 'pi pi-file text-surface-400'" />
                                <span class="font-medium truncate max-w-xs">{{ data.title }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column field="status" header="Stato" sortable>
                        <template #body="{ data }"><DocumentStatusTag :status="data.status" /></template>
                    </Column>
                    <Column header="Metadati AI">
                        <template #body="{ data }">
                            <button type="button" class="cursor-pointer" @click="openMetadata(data)">
                                <MetadataStatusTag :status="data.metadata_status" />
                            </button>
                        </template>
                    </Column>
                    <Column field="created_at" header="Caricato" sortable>
                        <template #body="{ data }"><span class="text-surface-500">{{ formatDate(data.created_at) }}</span></template>
                    </Column>
                    <Column header="" style="width: 96px">
                        <template #body="{ data }">
                            <div class="flex">
                                <Button icon="pi pi-sparkles" severity="secondary" text rounded size="small"
                                    v-tooltip.top="'Metadati legali'" @click="openMetadata(data)" />
                                <Button icon="pi pi-trash" severity="danger" text rounded size="small"
                                    v-tooltip.top="'Elimina'" @click="confirmRemoveDoc(data)" />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <MatterFormDialog
            v-model:visible="editOpen"
            :matter-types="matterTypes"
            :matter="matter"
            @saved="reloadMatter"
        />

        <DocumentMetadataDialog
            v-model:visible="metaOpen"
            :document="metaDoc"
        />
    </div>
</template>
