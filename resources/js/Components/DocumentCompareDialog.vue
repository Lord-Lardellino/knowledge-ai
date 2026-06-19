<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useToast } from 'primevue/usetoast'

interface DocumentOption {
    id: number
    title: string
    extension: string
    status: string
    chunk_count: number
}

interface SimilarDocument extends DocumentOption {
    similarity: number
    preview: string
    matter: { id: number; title: string } | null
    created_at: string
}

interface SimilarGroup {
    client_id: number | null
    client_name: string
    documents: SimilarDocument[]
}

interface DiffRow {
    type: 'context' | 'added' | 'removed'
    old_line: number | null
    new_line: number | null
    text: string
}

interface DiffHunk {
    old_start: number
    new_start: number
    rows: DiffRow[]
}

interface AlignBlock {
    text: string
    kind: 'identical' | 'similar' | 'unique'
    link: number | null
    topic: string | null
}

interface DiffResult {
    base: { id: number; title: string }
    target: { id: number; title: string }
    mode: 'paragraphs' | 'lines'
    truncated: boolean
    stats: { added: number; removed: number; unchanged: number; similar: number }
    hunks: DiffHunk[]
    alignment: { base: AlignBlock[]; target: AlignBlock[] }
}

type SearchMode = 'semantic' | 'exact' | 'client' | 'semantic_query'
type CompareView = 'highlight' | 'diff' | 'documents'

const props = defineProps<{
    visible: boolean
    documents: DocumentOption[]
}>()

const emit = defineEmits<{
    'update:visible': [value: boolean]
}>()

const toast = useToast()
const baseId = ref<number | null>(null)
const diffMode = ref<'paragraphs' | 'lines'>('paragraphs')
const compareView = ref<CompareView>('highlight')
const searchMode = ref<SearchMode>('semantic')
const searchText = ref('')
const loadingSimilar = ref(false)
const loadingCompare = ref(false)
const groups = ref<SimilarGroup[]>([])
const selected = ref<SimilarDocument | null>(null)
const result = ref<DiffResult | null>(null)
const error = ref<string | null>(null)

const searchModes = [
    { label: 'Simili', value: 'semantic', icon: 'pi pi-sitemap' },
    { label: 'Uguali', value: 'exact', icon: 'pi pi-clone' },
    { label: 'Cliente', value: 'client', icon: 'pi pi-id-card' },
    { label: 'Campo', value: 'semantic_query', icon: 'pi pi-sparkles' },
]

const compareViews = [
    { label: 'Evidenzia', value: 'highlight' },
    { label: 'Git diff', value: 'diff' },
    { label: 'Documenti', value: 'documents' },
]

// Navigazione collegata: passaggio gemello evidenziato al passaggio dell'altro lato.
const activeLink = ref<number | null>(null)

function focusLink (link: number | null): void {
    if (link === null) return
    activeLink.value = link
    nextTick(() => {
        // Scorre ogni colonna nel PROPRIO contenitore fino al passaggio collegato,
        // così cliccando l'originale il documento confrontato va sul gemello (e viceversa).
        document.querySelectorAll<HTMLElement>(`[data-link="${link}"]`).forEach((el) => {
            const scroller = el.closest<HTMLElement>('.overflow-auto')
            if (!scroller) return
            const top = scroller.scrollTop
                + el.getBoundingClientRect().top - scroller.getBoundingClientRect().top
                - scroller.clientHeight / 2 + el.clientHeight / 2
            scroller.scrollTo({ top, behavior: 'smooth' })
        })
    })
}

function blockClass (block: AlignBlock): string {
    const active = block.link !== null && block.link === activeLink.value
    if (block.kind === 'identical') {
        return (active ? 'ring-2 ring-emerald-400 ' : '') + 'bg-emerald-50 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-100 cursor-pointer'
    }
    if (block.kind === 'similar') {
        return (active ? 'ring-2 ring-amber-400 ' : '') + 'bg-amber-50 text-amber-900 dark:bg-amber-500/15 dark:text-amber-100 cursor-pointer'
    }
    return 'text-surface-500 dark:text-surface-400'
}

const diffModes = [
    { label: 'Paragrafi', value: 'paragraphs' },
    { label: 'Righe', value: 'lines' },
]

const readyDocuments = computed(() => props.documents
    .filter(doc => doc.status === 'indexed' && doc.chunk_count > 0)
    .map(doc => ({
        ...doc,
        label: `${doc.title} - ${doc.extension.toUpperCase()} - ${doc.chunk_count} chunk`,
    })))

const baseDocument = computed(() => readyDocuments.value.find(doc => doc.id === baseId.value) ?? null)
const hasSimilar = computed(() => groups.value.some(group => group.documents.length > 0))
const needsQuery = computed(() => searchMode.value === 'semantic_query')
const showQuery = computed(() => searchMode.value === 'semantic_query' || searchMode.value === 'client')
const canLoadSimilar = computed(() => !needsQuery.value || searchText.value.trim().length >= 2)

const flatSuggestions = computed(() => groups.value
    .flatMap(group => group.documents.map(document => ({ ...document, client_name: group.client_name })))
    .sort((a, b) => b.similarity - a.similarity))

watch(() => props.visible, (visible) => {
    if (!visible) return
    reset()
    if (readyDocuments.value.length > 0) {
        baseId.value = readyDocuments.value[0].id
        void loadSimilar()
    }
})

watch(baseId, (value, oldValue) => {
    if (!props.visible || !value || value === oldValue) return
    void loadSimilar()
})


watch(searchMode, () => {
    clearSelection()
    groups.value = []
    error.value = null

    if (!props.visible) return
    if (!canLoadSimilar.value) return

    void loadSimilar()
})

function reset(): void {
    diffMode.value = 'paragraphs'
    compareView.value = 'highlight'
    searchMode.value = 'semantic'
    searchText.value = ''
    groups.value = []
    clearSelection()
    error.value = null
}

function clearSelection(): void {
    selected.value = null
    result.value = null
    activeLink.value = null
}

function close(): void {
    emit('update:visible', false)
}

async function loadSimilar(): Promise<void> {
    if (!baseId.value) return

    if (!canLoadSimilar.value) {
        groups.value = []
        clearSelection()
        error.value = 'Inserisci almeno 2 caratteri per il campo semantico.'
        return
    }

    loadingSimilar.value = true
    error.value = null
    groups.value = []
    clearSelection()

    const params = new URLSearchParams({
        limit: '80',
        mode: searchMode.value,
    })

    const query = searchText.value.trim()
    if (query !== '') {
        params.set('q', query)
    }

    try {
        const res = await fetch(`/documents/${baseId.value}/similar?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        })
        const body = await res.json().catch(() => ({}))
        if (!res.ok) {
            error.value = body.message ?? 'Ricerca documenti simili non riuscita.'
            toast.add({ severity: 'error', summary: 'Errore', detail: error.value, life: 5000 })
            return
        }
        groups.value = body.groups ?? []
    } finally {
        loadingSimilar.value = false
    }
}

async function selectCandidate(candidate: SimilarDocument): Promise<void> {
    selected.value = candidate
    await compare(candidate)

}

async function compare(candidate = selected.value): Promise<void> {
    if (!baseId.value || !candidate) return

    loadingCompare.value = true
    error.value = null
    result.value = null

    try {
        const res = await fetch('/documents/compare', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ base_id: baseId.value, target_id: candidate.id, mode: diffMode.value }),
        })

        const body = await res.json().catch(() => ({}))
        if (!res.ok) {
            error.value = body.message ?? 'Confronto non riuscito.'
            toast.add({ severity: 'error', summary: 'Errore confronto', detail: error.value, life: 5000 })
            return
        }

        result.value = body as DiffResult
    } finally {
        loadingCompare.value = false
    }
}

function similarityLabel(value: number): string {
    return `${Math.max(0, Math.min(100, Math.round(value * 100)))}%`
}

function rankLabel(index: number): string {
    return `#${index + 1}`
}

function extensionLabel(extension: string): string {
    return extension ? extension.toUpperCase() : 'DOC'
}

function thumbnailIcon(extension: string): string {
    const ext = extension.toLowerCase()
    if (ext === 'pdf') return 'pi pi-file-pdf'
    if (['doc', 'docx'].includes(ext)) return 'pi pi-file-word'
    if (['xls', 'xlsx', 'csv'].includes(ext)) return 'pi pi-file-excel'
    if (['png', 'jpg', 'jpeg', 'webp'].includes(ext)) return 'pi pi-image'
    return 'pi pi-file'
}

function viewerUrl(id: number): string {
    return `/documents/${id}/viewer`
}

function previewUrl(id: number): string {
    return `/documents/${id}/preview`
}

function openPreview(id: number): void {
    window.open(previewUrl(id), '_blank', 'noopener,noreferrer')
}

function leftClass(row: DiffRow): string {
    if (row.type === 'removed') return 'bg-red-50 text-red-950 dark:bg-red-950/30 dark:text-red-100'
    if (row.type === 'context') return 'bg-surface-0 text-surface-700 dark:bg-surface-950 dark:text-surface-200'
    return 'bg-surface-50 text-surface-300 dark:bg-surface-900 dark:text-surface-700'
}

function rightClass(row: DiffRow): string {
    if (row.type === 'added') return 'bg-emerald-50 text-emerald-950 dark:bg-emerald-950/30 dark:text-emerald-100'
    if (row.type === 'context') return 'bg-surface-0 text-surface-700 dark:bg-surface-950 dark:text-surface-200'
    return 'bg-surface-50 text-surface-300 dark:bg-surface-900 dark:text-surface-700'
}

function leftText(row: DiffRow): string {
    return row.type === 'added' ? '' : row.text
}

function rightText(row: DiffRow): string {
    return row.type === 'removed' ? '' : row.text
}

function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :style="{ width: '98vw', maxWidth: '1840px' }"
        :contentStyle="{ padding: '0' }"
        :pt="{ header: { class: 'items-start' } }"
        @update:visible="emit('update:visible', $event)"
    >
        <template #header>
            <div class="flex w-full flex-col gap-3">
                <span class="text-lg font-semibold text-surface-900 dark:text-surface-50">Confronto documenti</span>

                <Message v-if="readyDocuments.length < 1" severity="warn" :closable="false">
                    Serve almeno un documento indicizzato nella pratica.
                </Message>

                <div v-else class="grid w-full grid-cols-1 gap-3 xl:grid-cols-[minmax(20rem,1fr)_auto_auto_auto] xl:items-end">
                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-semibold uppercase tracking-wide text-surface-400">Documento base</label>
                    <Select v-model="baseId" :options="readyDocuments" optionLabel="label" optionValue="id" class="w-full" />
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-semibold uppercase tracking-wide text-surface-400">Suggerimenti</label>
                    <SelectButton v-model="searchMode" :options="searchModes" optionLabel="label" optionValue="value" :allowEmpty="false" />
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-semibold uppercase tracking-wide text-surface-400">Vista</label>
                    <SelectButton v-model="compareView" :options="compareViews" optionLabel="label" optionValue="value" :allowEmpty="false" />
                </div>

                <div class="flex flex-col justify-end gap-1.5">
                    <Button label="Ricarica" icon="pi pi-refresh" severity="secondary" outlined :loading="loadingSimilar" @click="loadSimilar" />
                </div>
            </div>

            <div v-if="showQuery" class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-[minmax(0,1fr)_auto]">
                <IconField>
                    <InputIcon :class="searchMode === 'client' ? 'pi pi-id-card' : 'pi pi-sparkles'" />
                    <InputText
                        v-model="searchText"
                        class="w-full"
                        :placeholder="searchMode === 'client' ? 'Filtra per cliente' : 'Cerca un campo semantico, es. clausola penale, recesso, privacy'"
                        @keydown.enter.prevent="loadSimilar"
                    />
                </IconField>
                <Button label="Cerca" icon="pi pi-search" :loading="loadingSimilar" :disabled="!canLoadSimilar" @click="loadSimilar" />
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs text-surface-500">
                <Tag v-if="flatSuggestions.length" :value="`${flatSuggestions.length} suggeriti`" severity="secondary" rounded />
                <span v-if="searchMode === 'semantic'">Ordinati dal piu simile al meno simile.</span>
                <span v-else-if="searchMode === 'exact'">Cerca duplicati esatti o titoli uguali.</span>
                <span v-else-if="searchMode === 'client'">Raggruppa i risultati del cliente e li ordina per vicinanza.</span>
                <span v-else>Usa una richiesta embedding per trovare il campo richiesto.</span>
            </div>

                <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
            </div>
        </template>

        <div class="grid h-[76vh] grid-cols-1 overflow-hidden lg:grid-cols-[330px_minmax(0,1fr)]">
            <aside class="flex min-h-0 flex-col overflow-hidden border-b border-surface-200 bg-surface-50/70 p-3 dark:border-surface-800 dark:bg-surface-950 lg:border-b-0 lg:border-r">
                <div class="mb-3 flex items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-semibold text-surface-900 dark:text-surface-50">Documenti suggeriti</p>
                        <p class="text-xs text-surface-500">Gruppi per cliente, ranking globale per similarita.</p>
                    </div>
                    <Tag v-if="flatSuggestions[0]" :value="similarityLabel(flatSuggestions[0].similarity)" severity="success" rounded />
                </div>

                <div v-if="loadingSimilar" class="space-y-3">
                    <Skeleton v-for="i in 4" :key="i" height="7.5rem" />
                </div>

                <Message v-else-if="!hasSimilar" severity="info" :closable="false">
                    Nessun documento simile trovato nell'archivio indicizzato.
                </Message>

                <div v-else class="min-h-0 flex-1 space-y-3 overflow-y-auto pr-1">
                    <Panel v-for="group in groups" :key="group.client_id ?? 'none'" toggleable>
                        <template #header>
                            <div class="flex min-w-0 items-center gap-2">
                                <i class="pi pi-id-card text-primary-500" />
                                <span class="truncate text-sm font-semibold">{{ group.client_name }}</span>
                                <Tag :value="String(group.documents.length)" severity="secondary" rounded />
                            </div>
                        </template>

                        <button
                            v-for="doc in group.documents"
                            :key="doc.id"
                            type="button"
                            class="mb-2 grid w-full grid-cols-[4.75rem_minmax(0,1fr)] gap-3 rounded-xl border p-2.5 text-left transition-all last:mb-0"
                            :class="selected?.id === doc.id
                                ? 'border-primary-400 bg-primary-50 shadow-sm dark:border-primary-700 dark:bg-primary-950/40'
                                : 'border-surface-200 bg-surface-0 hover:border-primary-300 hover:shadow-sm dark:border-surface-800 dark:bg-surface-900 dark:hover:border-primary-700'"
                            @click="selectCandidate(doc)"
                        >
                            <div class="relative aspect-[4/5] overflow-hidden rounded-lg border border-surface-200 bg-white shadow-sm dark:border-surface-700 dark:bg-surface-950">
                                <div class="absolute inset-x-0 top-0 flex items-center justify-between border-b border-surface-100 bg-surface-50 px-2 py-1 dark:border-surface-800 dark:bg-surface-900">
                                    <span class="text-[10px] font-bold text-surface-500">{{ extensionLabel(doc.extension) }}</span>
                                    <i :class="thumbnailIcon(doc.extension)" class="text-xs text-primary-500" />
                                </div>
                                <div class="absolute inset-x-2 top-8 space-y-1.5">
                                    <span class="block h-1.5 rounded bg-surface-200 dark:bg-surface-700" />
                                    <span class="block h-1.5 w-10/12 rounded bg-surface-200 dark:bg-surface-700" />
                                    <span class="block h-1.5 w-11/12 rounded bg-surface-100 dark:bg-surface-800" />
                                    <span class="block h-1.5 w-8/12 rounded bg-surface-100 dark:bg-surface-800" />
                                </div>
                                <Tag class="absolute bottom-1.5 left-1.5" :value="similarityLabel(doc.similarity)" severity="success" rounded />
                            </div>

                            <div class="min-w-0">
                                <div class="mb-1 flex items-center gap-2">
                                    <Tag :value="rankLabel(flatSuggestions.findIndex(item => item.id === doc.id))" severity="secondary" rounded />
                                    <span class="truncate text-sm font-semibold text-surface-900 dark:text-surface-50">{{ doc.title }}</span>
                                </div>
                                <p v-if="doc.matter" class="mb-1 truncate text-xs text-surface-500">{{ doc.matter.title }}</p>
                                <p class="line-clamp-4 text-xs leading-relaxed text-surface-600 dark:text-surface-300">{{ doc.preview || 'Preview testuale non disponibile.' }}</p>
                            </div>
                        </button>
                    </Panel>
                </div>
            </aside>

            <section class="min-w-0 overflow-hidden bg-surface-0 dark:bg-surface-950">
                <div v-if="selected" class="flex h-full flex-col">
                    <div class="border-b border-surface-200 p-4 dark:border-surface-800">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-surface-900 dark:text-surface-50">
                                    {{ baseDocument?.title }} / {{ selected.title }}
                                </p>
                                <p class="text-xs text-surface-500">
                                    {{ selected.matter?.title ?? 'Senza pratica' }} - {{ similarityLabel(selected.similarity) }} similarita
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <SelectButton
                                    v-if="compareView === 'diff'"
                                    v-model="diffMode"
                                    :options="diffModes"
                                    optionLabel="label"
                                    optionValue="value"
                                    :allowEmpty="false"
                                    @change="compare()"
                                />
                                <Button icon="pi pi-external-link" label="Apri simile" severity="secondary" outlined @click="openPreview(selected.id)" />
                            </div>
                        </div>
                    </div>

                    <!-- Vista Evidenzia: passaggi identici (verde) e simili (ambra), navigabili. -->
                    <div v-if="compareView === 'highlight'" class="flex min-h-0 flex-1 flex-col overflow-hidden">
                        <div v-if="loadingCompare" class="space-y-3 p-4">
                            <Skeleton height="2rem" />
                            <Skeleton height="20rem" />
                        </div>
                        <template v-else-if="result">
                            <div class="flex flex-wrap items-center gap-3 border-b border-surface-200 px-4 py-2 text-xs dark:border-surface-800">
                                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-emerald-300 dark:bg-emerald-500/50" /> Stesso passaggio</span>
                                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-amber-300 dark:bg-amber-500/50" /> Stesso argomento</span>
                                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded bg-surface-200 dark:bg-surface-700" /> Solo qui</span>
                                <span class="ml-auto text-surface-500">Affinità per significato (per le parole uguali usa Git diff). Clicca un passaggio per saltare al gemello.</span>
                            </div>
                            <div class="grid min-h-0 flex-1 grid-cols-1 overflow-hidden xl:grid-cols-2">
                                <div class="flex min-h-0 flex-col border-b border-surface-200 dark:border-surface-800 xl:border-b-0 xl:border-r">
                                    <p class="truncate border-b border-surface-200 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-surface-500 dark:border-surface-800">{{ baseDocument?.title }}</p>
                                    <div class="min-h-0 flex-1 space-y-1.5 overflow-auto p-3">
                                        <div
                                            v-for="(block, i) in result.alignment.base"
                                            :key="`b-${i}`"
                                            :data-link="block.link ?? undefined"
                                            class="rounded-md px-2.5 py-1.5 text-sm leading-relaxed transition-shadow"
                                            :class="blockClass(block)"
                                            @click="focusLink(block.link)"
                                        >
                                            <Tag v-if="block.topic && block.kind !== 'unique'" :value="block.topic" severity="warn" class="mb-1 text-[10px]" />
                                            <p class="m-0">{{ block.text }}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex min-h-0 flex-col">
                                    <p class="truncate border-b border-surface-200 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-surface-500 dark:border-surface-800">{{ selected.title }}</p>
                                    <div class="min-h-0 flex-1 space-y-1.5 overflow-auto p-3">
                                        <div
                                            v-for="(block, i) in result.alignment.target"
                                            :key="`t-${i}`"
                                            :data-link="block.link ?? undefined"
                                            class="rounded-md px-2.5 py-1.5 text-sm leading-relaxed transition-shadow"
                                            :class="blockClass(block)"
                                            @click="focusLink(block.link)"
                                        >
                                            <Tag v-if="block.topic && block.kind !== 'unique'" :value="block.topic" severity="warn" class="mb-1 text-[10px]" />
                                            <p class="m-0">{{ block.text }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div v-if="compareView === 'documents'" class="grid min-h-0 flex-1 grid-cols-1 gap-0 overflow-hidden xl:grid-cols-2">
                        <div class="flex min-h-0 flex-col border-b border-surface-200 dark:border-surface-800 xl:border-b-0 xl:border-r">
                            <div class="flex items-center justify-between gap-3 border-b border-surface-200 px-4 py-2 dark:border-surface-800">
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-semibold uppercase tracking-wide text-surface-500">Originale</p>
                                    <p class="truncate text-sm font-semibold text-surface-900 dark:text-surface-50">{{ baseDocument?.title }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Tag v-if="baseDocument" :value="extensionLabel(baseDocument.extension)" severity="secondary" rounded />
                                    <Button v-if="baseDocument" icon="pi pi-external-link" severity="secondary" text rounded @click="openPreview(baseDocument.id)" />
                                </div>
                            </div>
                            <iframe
                                v-if="baseDocument"
                                :src="viewerUrl(baseDocument.id)"
                                class="min-h-96 flex-1 border-0 bg-white dark:bg-white"
                            />
                        </div>

                        <div class="flex min-h-0 flex-col">
                            <div class="flex items-center justify-between gap-3 border-b border-surface-200 px-4 py-2 dark:border-surface-800">
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-semibold uppercase tracking-wide text-surface-500">Documento simile</p>
                                    <p class="truncate text-sm font-semibold text-surface-900 dark:text-surface-50">{{ selected.title }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Tag :value="extensionLabel(selected.extension)" severity="secondary" rounded />
                                    <Button icon="pi pi-external-link" severity="secondary" text rounded @click="openPreview(selected.id)" />
                                </div>
                            </div>
                            <iframe
                                :src="viewerUrl(selected.id)"
                                class="min-h-96 flex-1 border-0 bg-white dark:bg-white"
                            />
                        </div>
                    </div>
                    <div v-else-if="compareView === 'diff'" class="min-h-0 flex-1 overflow-hidden">
                        <div v-if="loadingCompare" class="space-y-3 p-4">
                            <Skeleton height="2rem" />
                            <Skeleton height="18rem" />
                            <Skeleton height="18rem" />
                        </div>

                        <div v-else-if="result" class="flex h-full flex-col">
                            <div class="flex flex-wrap items-center gap-2 border-b border-surface-200 px-4 py-3 dark:border-surface-800">
                                <Tag :value="`+${result.stats.added}`" severity="success" />
                                <Tag :value="`-${result.stats.removed}`" severity="danger" />
                                <Tag :value="`${result.stats.unchanged} uguali`" severity="secondary" />
                                <Message v-if="result.truncated" severity="warn" :closable="false" class="ml-auto">
                                    Confronto limitato per mantenere la pagina veloce.
                                </Message>
                            </div>

                            <div class="grid grid-cols-2 border-b border-surface-200 text-xs font-semibold uppercase tracking-wide text-surface-500 dark:border-surface-800">
                                <div class="border-r border-surface-200 px-4 py-2 dark:border-surface-800">Originale</div>
                                <div class="px-4 py-2">Documento simile</div>
                            </div>

                            <div class="min-h-0 flex-1 overflow-auto p-4">
                                <div v-for="(hunk, hunkIndex) in result.hunks" :key="hunkIndex" class="mb-5 overflow-hidden rounded-lg border border-surface-200 dark:border-surface-800">
                                    <div class="border-b border-surface-200 bg-surface-100 px-3 py-2 font-mono text-xs text-surface-500 dark:border-surface-800 dark:bg-surface-900">
                                        @@ -{{ hunk.old_start }} +{{ hunk.new_start }} @@
                                    </div>
                                    <div class="font-mono text-xs leading-5">
                                        <div
                                            v-for="(row, rowIndex) in hunk.rows"
                                            :key="`${hunkIndex}-${rowIndex}`"
                                            class="grid grid-cols-[3rem_minmax(0,1fr)_3rem_minmax(0,1fr)] border-b border-surface-200 last:border-b-0 dark:border-surface-800"
                                        >
                                            <span class="border-r border-inherit px-2 py-1 text-right text-surface-400" :class="leftClass(row)">{{ row.old_line ?? '' }}</span>
                                            <pre class="border-r border-inherit px-3 py-1 whitespace-pre-wrap break-words" :class="leftClass(row)">{{ leftText(row) }}</pre>
                                            <span class="border-r border-inherit px-2 py-1 text-right text-surface-400" :class="rightClass(row)">{{ row.new_line ?? '' }}</span>
                                            <pre class="px-3 py-1 whitespace-pre-wrap break-words" :class="rightClass(row)">{{ rightText(row) }}</pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else class="flex h-full items-center justify-center p-8 text-center text-surface-500">
                    <div>
                        <i class="pi pi-search mb-3 block text-4xl text-surface-300" />
                        <p class="font-medium text-surface-700 dark:text-surface-200">Scegli una miniatura suggerita.</p>
                        <p class="mt-1 text-sm">Il confronto grande si apre qui, in diff o con i file affiancati.</p>
                    </div>
                </div>
            </section>
        </div>

        <template #footer>
            <div class="flex w-full items-center justify-between gap-3">
                <span v-if="baseDocument" class="truncate text-xs text-surface-500">Base: {{ baseDocument.title }}</span>
                <Button label="Chiudi" severity="secondary" text @click="close" />
            </div>
        </template>
    </Dialog>
</template>
