<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useToast } from 'primevue/usetoast'
import MetadataStatusTag from '@/Components/MetadataStatusTag.vue'
import {
    useDocumentMetadata, emptyMetadata,
    type DocumentMetadata, type MetadataStatus,
} from '@/composables/useDocumentMetadata'

/**
 * DocumentMetadataDialog — revisione dei metadati legali estratti dall'AI.
 *
 * L'avvocato vede l'estratto, lo corregge e conferma (fiducia). Tutto PrimeVue
 * nativo. Riutilizzabile ovunque ci sia un documento con metadati.
 */
interface DocDocument {
    id: number
    title: string
    metadata: DocumentMetadata | null
    metadata_status: MetadataStatus
    metadata_reviewed_at: string | null
}

const props = defineProps<{
    visible:  boolean
    document: DocDocument | null
}>()

const emit = defineEmits<{ 'update:visible': [value: boolean] }>()

const toast = useToast()
const { save, retry } = useDocumentMetadata()

const form    = ref<DocumentMetadata>(emptyMetadata())
const saving  = ref(false)
const retrying = ref(false)

const status   = computed<MetadataStatus>(() => props.document?.metadata_status ?? 'pending')
const isBusy   = computed(() => status.value === 'pending' || status.value === 'processing')
const isFailed = computed(() => status.value === 'failed')

// Vista pulita di default; si passa alla modifica con il bottone "Modifica".
const editing = ref(false)

// True se non c'è alcun dato estratto (mostra messaggio invece della vista vuota).
const isEmpty = computed(() => {
    const m = props.document?.metadata
    if (! m) return true
    return ! m.document_type && ! m.summary
        && ! m.parties?.length && ! m.dates?.length && ! m.amounts?.length
        && ! m.clauses?.length && ! m.citations?.length && ! m.risks?.length
})

watch(() => props.visible, (open) => {
    if (open) {
        editing.value = false
        form.value = props.document?.metadata
            ? structuredClone(toRawSafe(props.document.metadata))
            : emptyMetadata()
    }
})

const meta = computed(() => props.document?.metadata)

function fmtDate (s: string): string {
    if (! s) return '—'
    const [y, m, d] = s.split('-')
    return (y && m && d) ? `${d}/${m}/${y}` : s
}
function fmtAmount (n: number, currency: string): string {
    return new Intl.NumberFormat('it-IT', { style: 'currency', currency: currency || 'EUR' }).format(n || 0)
}

// Evita problemi di clonazione su proxy reattivi annidati.
function toRawSafe (m: DocumentMetadata): DocumentMetadata {
    return JSON.parse(JSON.stringify(m))
}

async function onSave (): Promise<void> {
    if (! props.document) return
    saving.value = true
    const ok = await save(props.document.id, form.value)
    saving.value = false

    toast.add(ok
        ? { severity: 'success', summary: 'Confermati', detail: 'Metadati salvati e confermati.', life: 3000 }
        : { severity: 'error', summary: 'Errore', detail: 'Salvataggio fallito.', life: 5000 })
    if (ok) emit('update:visible', false)
}

async function onRetry (): Promise<void> {
    if (! props.document) return
    retrying.value = true
    const ok = await retry(props.document.id)
    retrying.value = false
    toast.add(ok
        ? { severity: 'info', summary: 'Riavviata', detail: 'Estrazione metadati in corso.', life: 3000 }
        : { severity: 'error', summary: 'Errore', detail: 'Impossibile riavviare.', life: 5000 })
    if (ok) emit('update:visible', false)
}

// Date: il modello tiene la stringa YYYY-MM-DD, il DatePicker lavora con Date.
// Conversione manuale (no toISOString) per evitare slittamenti di fuso orario.
function isoToDate (s: string): Date | null {
    if (! s) return null
    const [y, m, d] = s.split('-').map(Number)
    return (y && m && d) ? new Date(y, m - 1, d) : null
}
function dateToIso (d: Date | null): string {
    if (! d) return ''
    const y = d.getFullYear()
    const m = String(d.getMonth() + 1).padStart(2, '0')
    const day = String(d.getDate()).padStart(2, '0')
    return `${y}-${m}-${day}`
}

// Helper per liste editabili.
function addParty () { form.value.parties.push({ name: '', role: '' }) }
function addDate ()  { form.value.dates.push({ label: '', date: '' }) }
function addAmount () { form.value.amounts.push({ label: '', amount: 0, currency: 'EUR' }) }
function addClause () { form.value.clauses.push({ title: '', summary: '' }) }
function addCitation () { form.value.citations.push('') }
function addRisk () { form.value.risks.push('') }
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="emit('update:visible', $event)"
        modal :style="{ width: '46rem' }" :breakpoints="{ '640px': '95vw' }"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <span class="font-semibold">Metadati legali</span>
                <MetadataStatusTag :status="status" />
            </div>
        </template>

        <!-- In lavorazione -->
        <div v-if="isBusy" class="flex flex-col items-center gap-3 py-12 text-surface-500">
            <i class="pi pi-spin pi-spinner text-3xl" />
            <p>Estrazione dei metadati in corso. Riapri tra poco.</p>
        </div>

        <!-- Fallita -->
        <div v-else-if="isFailed" class="flex flex-col items-center gap-3 py-12 text-surface-500">
            <i class="pi pi-exclamation-triangle text-3xl text-red-500" />
            <p>Estrazione non riuscita.</p>
            <Button label="Riprova" icon="pi pi-refresh" :loading="retrying" @click="onRetry" />
        </div>

        <!-- Vista dettaglio (sola lettura) -->
        <div v-else-if="!editing" class="flex flex-col gap-5">
            <div v-if="isEmpty" class="text-center py-10 text-surface-400">
                <i class="pi pi-info-circle text-3xl mb-2 block" />
                <p>Nessun metadato estratto per questo documento.</p>
            </div>

            <template v-else>
                <div class="flex flex-wrap items-center gap-2">
                    <Tag v-if="meta?.document_type" :value="meta.document_type" icon="pi pi-file" />
                </div>

                <div v-if="meta?.summary">
                    <p class="text-xs uppercase tracking-wide text-surface-400 mb-1">Sintesi</p>
                    <p class="text-surface-700 dark:text-surface-200 whitespace-pre-line">{{ meta.summary }}</p>
                </div>

                <div v-if="meta?.parties?.length">
                    <p class="text-xs uppercase tracking-wide text-surface-400 mb-2">Parti coinvolte</p>
                    <ul class="space-y-1">
                        <li v-for="(p, i) in meta.parties" :key="`vp-${i}`" class="flex items-center gap-2 text-sm">
                            <i class="pi pi-user text-surface-400" />
                            <span class="font-medium">{{ p.name }}</span>
                            <span v-if="p.role" class="text-surface-400">— {{ p.role }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="meta?.dates?.length">
                    <p class="text-xs uppercase tracking-wide text-surface-400 mb-2">Date rilevanti</p>
                    <ul class="space-y-1">
                        <li v-for="(d, i) in meta.dates" :key="`vd-${i}`" class="text-sm">
                            <span class="text-surface-500">{{ d.label }}:</span> <span class="font-medium">{{ fmtDate(d.date) }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="meta?.amounts?.length">
                    <p class="text-xs uppercase tracking-wide text-surface-400 mb-2">Importi</p>
                    <ul class="space-y-1">
                        <li v-for="(a, i) in meta.amounts" :key="`va-${i}`" class="text-sm">
                            <span class="text-surface-500">{{ a.label }}:</span> <span class="font-medium">{{ fmtAmount(a.amount, a.currency) }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="meta?.clauses?.length">
                    <p class="text-xs uppercase tracking-wide text-surface-400 mb-2">Clausole rilevanti</p>
                    <ul class="space-y-2">
                        <li v-for="(c, i) in meta.clauses" :key="`vc-${i}`" class="text-sm">
                            <p class="font-medium">{{ c.title }}</p>
                            <p v-if="c.summary" class="text-surface-500">{{ c.summary }}</p>
                        </li>
                    </ul>
                </div>

                <div v-if="meta?.citations?.length">
                    <p class="text-xs uppercase tracking-wide text-surface-400 mb-2">Riferimenti normativi</p>
                    <div class="flex flex-wrap gap-1.5">
                        <Tag v-for="(c, i) in meta.citations" :key="`vcit-${i}`" :value="c" severity="secondary" rounded />
                    </div>
                </div>

                <div v-if="meta?.risks?.length">
                    <p class="text-xs uppercase tracking-wide text-surface-400 mb-2">Rischi / criticità</p>
                    <ul class="space-y-1">
                        <li v-for="(r, i) in meta.risks" :key="`vr-${i}`" class="flex items-start gap-2 text-sm">
                            <i class="pi pi-exclamation-triangle text-amber-500 mt-0.5" />
                            <span class="text-surface-700 dark:text-surface-200">{{ r }}</span>
                        </li>
                    </ul>
                </div>
            </template>
        </div>

        <!-- Form di revisione -->
        <div v-else class="flex flex-col gap-5">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Tipo documento</label>
                    <InputText v-model="form.document_type" placeholder="contratto, diffida, sentenza…" fluid />
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label class="text-sm font-medium">Sintesi</label>
                <Textarea v-model="form.summary" rows="3" autoResize fluid />
            </div>

            <!-- Parti -->
            <Fieldset :legend="`Parti coinvolte (${form.parties.length})`" toggleable :collapsed="!form.parties.length">
                <div class="flex flex-col gap-3">
                    <template v-for="(p, i) in form.parties" :key="`party-${i}`">
                        <div class="flex flex-col sm:flex-row gap-2">
                            <InputText v-model="p.name" placeholder="Nome" class="flex-1" />
                            <InputText v-model="p.role" placeholder="Ruolo" class="sm:w-48" />
                            <Button icon="pi pi-trash" severity="danger" text @click="form.parties.splice(i, 1)" />
                        </div>
                        <Divider v-if="i < form.parties.length - 1" class="my-0!" />
                    </template>
                    <Button icon="pi pi-plus" label="Aggiungi parte" size="small" text class="self-start" @click="addParty" />
                </div>
            </Fieldset>

            <!-- Date -->
            <Fieldset :legend="`Date rilevanti (${form.dates.length})`" toggleable :collapsed="!form.dates.length">
                <div class="flex flex-col gap-3">
                    <template v-for="(d, i) in form.dates" :key="`date-${i}`">
                        <div class="flex flex-col sm:flex-row gap-2">
                            <InputText v-model="d.label" placeholder="Descrizione" class="flex-1" />
                            <DatePicker
                                :modelValue="isoToDate(d.date)"
                                @update:modelValue="(val: any) => d.date = dateToIso(val)"
                                dateFormat="dd/mm/yy" showIcon class="sm:w-48" />
                            <Button icon="pi pi-trash" severity="danger" text @click="form.dates.splice(i, 1)" />
                        </div>
                        <Divider v-if="i < form.dates.length - 1" class="my-0!" />
                    </template>
                    <Button icon="pi pi-plus" label="Aggiungi data" size="small" text class="self-start" @click="addDate" />
                </div>
            </Fieldset>

            <!-- Importi -->
            <Fieldset :legend="`Importi (${form.amounts.length})`" toggleable :collapsed="!form.amounts.length">
                <div class="flex flex-col gap-3">
                    <template v-for="(a, i) in form.amounts" :key="`amount-${i}`">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                            <InputText v-model="a.label" placeholder="Descrizione" class="flex-1 min-w-0" />
                            <InputNumber v-model="a.amount" mode="currency" :currency="a.currency || 'EUR'" locale="it-IT" :min="0"
                                class="sm:w-40 shrink-0" :inputClass="'w-full'" />
                            <Button icon="pi pi-trash" severity="danger" text class="shrink-0" @click="form.amounts.splice(i, 1)" />
                        </div>
                        <Divider v-if="i < form.amounts.length - 1" class="my-0!" />
                    </template>
                    <Button icon="pi pi-plus" label="Aggiungi importo" size="small" text class="self-start" @click="addAmount" />
                </div>
            </Fieldset>

            <!-- Clausole -->
            <Fieldset :legend="`Clausole rilevanti (${form.clauses.length})`" toggleable :collapsed="!form.clauses.length">
                <div class="flex flex-col gap-3">
                    <template v-for="(c, i) in form.clauses" :key="`clause-${i}`">
                        <div class="flex flex-col gap-2">
                            <div class="flex gap-2">
                                <InputText v-model="c.title" placeholder="Titolo clausola" class="flex-1" />
                                <Button icon="pi pi-trash" severity="danger" text @click="form.clauses.splice(i, 1)" />
                            </div>
                            <Textarea v-model="c.summary" placeholder="Sintesi della clausola" rows="2" autoResize fluid />
                        </div>
                        <Divider v-if="i < form.clauses.length - 1" class="my-0!" />
                    </template>
                    <Button icon="pi pi-plus" label="Aggiungi clausola" size="small" text class="self-start" @click="addClause" />
                </div>
            </Fieldset>

            <!-- Riferimenti normativi -->
            <Fieldset :legend="`Riferimenti normativi (${form.citations.length})`" toggleable :collapsed="!form.citations.length">
                <div class="flex flex-col gap-3">
                    <template v-for="(_, i) in form.citations" :key="`cit-${i}`">
                        <div class="flex gap-2">
                            <InputText v-model="form.citations[i]" placeholder="es. art. 1453 c.c." class="flex-1" />
                            <Button icon="pi pi-trash" severity="danger" text @click="form.citations.splice(i, 1)" />
                        </div>
                        <Divider v-if="i < form.citations.length - 1" class="my-0!" />
                    </template>
                    <Button icon="pi pi-plus" label="Aggiungi riferimento" size="small" text class="self-start" @click="addCitation" />
                </div>
            </Fieldset>

            <!-- Rischi -->
            <Fieldset :legend="`Rischi / criticità (${form.risks.length})`" toggleable :collapsed="!form.risks.length">
                <div class="flex flex-col gap-3">
                    <template v-for="(_, i) in form.risks" :key="`risk-${i}`">
                        <div class="flex gap-2">
                            <Textarea v-model="form.risks[i]" placeholder="Rischio rilevato" rows="2" autoResize class="flex-1" />
                            <Button icon="pi pi-trash" severity="danger" text @click="form.risks.splice(i, 1)" />
                        </div>
                        <Divider v-if="i < form.risks.length - 1" class="my-0!" />
                    </template>
                    <Button icon="pi pi-plus" label="Aggiungi rischio" size="small" text class="self-start" @click="addRisk" />
                </div>
            </Fieldset>
        </div>

        <template #footer>
            <!-- Vista dettaglio -->
            <template v-if="!isBusy && !isFailed && !editing">
                <Button label="Ri-estrai" icon="pi pi-refresh" severity="secondary" text :loading="retrying" @click="onRetry" />
                <Button label="Chiudi" severity="secondary" text @click="emit('update:visible', false)" />
                <Button label="Modifica" icon="pi pi-pencil" @click="editing = true" />
            </template>

            <!-- Modifica -->
            <template v-else-if="!isBusy && !isFailed && editing">
                <Button label="Annulla" severity="secondary" text @click="editing = false" />
                <Button label="Conferma" icon="pi pi-check" :loading="saving" @click="onSave" />
            </template>

            <!-- In coda / fallita -->
            <template v-else>
                <Button v-if="!isBusy" label="Ri-estrai" icon="pi pi-refresh" severity="secondary" text :loading="retrying" @click="onRetry" />
                <Button label="Chiudi" severity="secondary" text @click="emit('update:visible', false)" />
            </template>
        </template>
    </Dialog>
</template>
