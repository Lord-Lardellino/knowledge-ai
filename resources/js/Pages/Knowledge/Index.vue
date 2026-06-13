<script setup lang="ts">
import { Head }            from '@inertiajs/vue3'
import { useToast }        from 'primevue/usetoast'
import { useConfirm }      from 'primevue/useconfirm'
import AppLayout           from '@/Layouts/AppLayout.vue'
import DocumentStatusTag   from '@/Components/DocumentStatusTag.vue'
import { useDocuments }    from '@/composables/useDocuments'
import type { DocumentEntry } from '@/composables/useDocuments'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    auth: { user: { name: string; email: string }; tenant?: { id: number; name: string } | null }
    initialDocuments: DocumentEntry[]
}>()

const toast   = useToast()
const confirm = useConfirm()

const { documents, uploading, error, upload, remove, reload } = useDocuments(props.initialDocuments)

// FileUpload in modalità custom: gestiamo noi l'invio, un file alla volta.
async function onUpload (event: { files: File[] }): Promise<void> {
    for (const file of event.files) {
        const ok = await upload(file)
        if (ok) {
            toast.add({ severity: 'success', summary: 'Caricato', detail: `${file.name} è in elaborazione.`, life: 4000 })
        } else {
            toast.add({ severity: 'error', summary: 'Errore', detail: error.value ?? 'Caricamento fallito.', life: 6000 })
        }
    }
}

function confirmRemove (doc: DocumentEntry): void {
    confirm.require({
        message:     `Vuoi eliminare "${doc.title}"? L'azione è irreversibile.`,
        header:      'Elimina documento',
        icon:        'pi pi-exclamation-triangle',
        rejectLabel: 'Annulla',
        acceptLabel: 'Elimina',
        acceptClass: 'p-button-danger',
        accept: async () => {
            const ok = await remove(doc.id)
            toast.add(ok
                ? { severity: 'success', summary: 'Eliminato', detail: `"${doc.title}" rimosso.`, life: 4000 }
                : { severity: 'error', summary: 'Errore', detail: error.value ?? 'Eliminazione fallita.', life: 6000 })
        },
    })
}

const extIcon: Record<string, string> = {
    pdf:  'pi pi-file-pdf text-red-500',
    docx: 'pi pi-file-word text-blue-500',
    xlsx: 'pi pi-file-excel text-green-500',
    txt:  'pi pi-file text-surface-400',
}

function formatBytes (bytes: number): string {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function formatDate (iso: string): string {
    return new Date(iso).toLocaleDateString('it-IT', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>

<template>
    <Head title="Knowledge Base" />

    <div class="mx-auto max-w-5xl space-y-6">

        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">Knowledge Base</h1>
                <p class="mt-0.5 text-sm text-surface-500">
                    Carica documenti — verranno indicizzati per la ricerca semantica.
                </p>
            </div>
            <Button icon="pi pi-refresh" label="Aggiorna" severity="secondary" outlined :loading="uploading" @click="reload" />
        </div>

        <!-- Upload -->
        <Card>
            <template #content>
                <FileUpload
                    name="file"
                    :multiple="true"
                    :auto="true"
                    customUpload
                    accept=".pdf,.docx,.xlsx,.txt"
                    :showUploadButton="false"
                    :showCancelButton="false"
                    @uploader="onUpload"
                >
                    <template #empty>
                        <div class="flex flex-col items-center gap-2 py-6 text-surface-500">
                            <i class="pi pi-cloud-upload text-3xl text-surface-400" />
                            <p class="text-sm">Trascina qui i file o usa <strong>Choose</strong>.</p>
                            <p class="text-xs text-surface-400">PDF, DOCX, XLSX, TXT — max {{ 25 }} MB</p>
                        </div>
                    </template>
                </FileUpload>
            </template>
        </Card>

        <!-- Lista documenti -->
        <Card>
            <template #content>
                <DataTable :value="documents" dataKey="id" :rows="10" paginator removableSort>
                    <template #empty>
                        <div class="text-center py-10 text-surface-400">
                            <i class="pi pi-folder-open text-3xl mb-2 block" />
                            <p>Nessun documento caricato.</p>
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
                        <template #body="{ data }">
                            <DocumentStatusTag :status="data.status" />
                        </template>
                    </Column>

                    <Column field="chunk_count" header="Chunk" sortable>
                        <template #body="{ data }">
                            <span class="text-surface-500">{{ data.chunk_count || '—' }}</span>
                        </template>
                    </Column>

                    <Column field="size_bytes" header="Dimensione" sortable>
                        <template #body="{ data }">
                            <span class="text-surface-500">{{ formatBytes(data.size_bytes) }}</span>
                        </template>
                    </Column>

                    <Column field="created_at" header="Caricato" sortable>
                        <template #body="{ data }">
                            <span class="text-surface-500">{{ formatDate(data.created_at) }}</span>
                        </template>
                    </Column>

                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <Button icon="pi pi-trash" severity="danger" text rounded size="small"
                                v-tooltip.top="'Elimina'" @click="confirmRemove(data)" />
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

    </div>
</template>
