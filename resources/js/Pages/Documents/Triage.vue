<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head }          from '@inertiajs/vue3'
import AppLayout         from '@/Layouts/AppLayout.vue'
import MetadataStatusTag from '@/Components/MetadataStatusTag.vue'
import CreateMatterFromDocsDialog from '@/Components/CreateMatterFromDocsDialog.vue'
import type { MatterType } from '@/composables/useMatters'
import type { DocumentMetadata, MetadataStatus } from '@/composables/useDocumentMetadata'

defineOptions({ layout: AppLayout })

interface TriageDoc {
    id: number
    title: string
    extension: string
    metadata: DocumentMetadata | null
    metadata_status: MetadataStatus
}

interface Proposal {
    client_name: string
    documents:   TriageDoc[]
}

const props = defineProps<{
    auth: { user: { name: string; email: string }; tenant?: { id: number; name: string } | null }
    proposals:   Proposal[]
    unassigned:  TriageDoc[]
    matterTypes: MatterType[]
}>()

const dialogOpen = ref(false)
const dialogDocs = ref<TriageDoc[]>([])

// Selezione manuale per i documenti senza cliente rilevato.
const selected = ref<TriageDoc[]>([])

const total = computed(() => props.proposals.reduce((n, p) => n + p.documents.length, 0) + props.unassigned.length)

function confirmProposal (p: Proposal): void {
    dialogDocs.value = p.documents
    dialogOpen.value = true
}

function createFromSelection (): void {
    if (selected.value.length) {
        dialogDocs.value = selected.value
        dialogOpen.value = true
    }
}

const extIcon: Record<string, string> = {
    pdf:  'pi pi-file-pdf text-red-500',
    docx: 'pi pi-file-word text-blue-500',
    xlsx: 'pi pi-file-excel text-green-500',
    txt:  'pi pi-file text-surface-400',
}

function partiesPreview (doc: TriageDoc): string {
    const names = (doc.metadata?.parties ?? []).map(p => p.name).filter(Boolean)
    return names.length ? names.join(', ') : '—'
}
</script>

<template>
    <Head title="Da smistare" />

    <div class="mx-auto max-w-5xl space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Da smistare</h1>
            <p class="mt-0.5 text-sm text-surface-500">
                L'AI ha raggruppato i documenti per cliente. Controlla e conferma per creare le pratiche.
            </p>
        </div>

        <!-- Tutto smistato -->
        <Card v-if="total === 0">
            <template #content>
                <div class="text-center py-12 text-surface-400">
                    <i class="pi pi-check-circle text-4xl mb-3 block text-green-500" />
                    <p class="font-medium text-surface-600 dark:text-surface-300">Tutto smistato.</p>
                    <p class="text-sm">Nessun documento in attesa di essere assegnato a una pratica.</p>
                </div>
            </template>
        </Card>

        <!-- Proposte AI: una card per cliente -->
        <template v-if="proposals.length">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-surface-400">Pratiche proposte</h2>
            <Card v-for="p in proposals" :key="p.client_name">
                <template #content>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <i class="pi pi-id-card text-primary-500" />
                                <span class="font-semibold">{{ p.client_name }}</span>
                                <Tag :value="`${p.documents.length} doc`" severity="secondary" rounded />
                            </div>
                            <ul class="mt-2 space-y-1">
                                <li v-for="d in p.documents" :key="d.id" class="flex items-center gap-2 text-sm text-surface-500">
                                    <i :class="extIcon[d.extension] ?? 'pi pi-file text-surface-400'" />
                                    <span class="truncate">{{ d.title }}</span>
                                </li>
                            </ul>
                        </div>
                        <Button label="Crea pratica" icon="pi pi-check" @click="confirmProposal(p)" />
                    </div>
                </template>
            </Card>
        </template>

        <!-- Senza cliente rilevato: selezione manuale -->
        <template v-if="unassigned.length">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-surface-400">
                    Senza cliente rilevato
                </h2>
                <Button label="Crea pratica dai selezionati" icon="pi pi-briefcase" size="small"
                    :disabled="!selected.length" :badge="selected.length ? String(selected.length) : undefined"
                    @click="createFromSelection" />
            </div>
            <Card>
                <template #content>
                    <Message severity="warn" :closable="false" class="text-sm mb-3">
                        Per questi documenti l'AI non ha riconosciuto un cliente. Selezionali e crea la pratica a mano.
                    </Message>
                    <DataTable v-model:selection="selected" :value="unassigned" dataKey="id" :rows="15" paginator>
                        <Column selectionMode="multiple" style="width: 3rem" />
                        <Column field="title" header="Documento">
                            <template #body="{ data }">
                                <div class="flex items-center gap-2">
                                    <i :class="extIcon[data.extension] ?? 'pi pi-file text-surface-400'" />
                                    <span class="font-medium truncate max-w-xs">{{ data.title }}</span>
                                </div>
                            </template>
                        </Column>
                        <Column header="Parti rilevate">
                            <template #body="{ data }"><span class="text-surface-500 text-sm">{{ partiesPreview(data) }}</span></template>
                        </Column>
                        <Column header="Metadati AI">
                            <template #body="{ data }"><MetadataStatusTag :status="data.metadata_status" /></template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </template>

        <CreateMatterFromDocsDialog
            v-model:visible="dialogOpen"
            :documents="dialogDocs"
            :matter-types="matterTypes"
        />
    </div>
</template>
