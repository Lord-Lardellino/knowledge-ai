<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'

interface DocHit { id: number; title: string; extension: string; status: string }
interface MatterHit { id: number; title: string }
interface ClientHit { id: number; name: string }

const query = ref('')
const open = ref(false)
const loading = ref(false)
const docs = ref<DocHit[]>([])
const matters = ref<MatterHit[]>([])
const clients = ref<ClientHit[]>([])
const root = ref<HTMLElement | null>(null)

let timer: ReturnType<typeof setTimeout> | null = null
let reqId = 0

const hasResults = () => docs.value.length > 0 || matters.value.length > 0 || clients.value.length > 0

watch(query, (q) => {
    if (timer) clearTimeout(timer)
    if (q.trim().length < 2) {
        docs.value = []; matters.value = []; clients.value = []
        open.value = false
        return
    }
    open.value = true
    timer = setTimeout(() => void run(q.trim()), 250)
})

async function run (q: string): Promise<void> {
    const id = ++reqId
    loading.value = true
    try {
        const res = await fetch(`/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
        const body = await res.json().catch(() => ({}))
        if (id !== reqId) return // risposta superata da una più recente
        docs.value = body.documents ?? []
        matters.value = body.matters ?? []
        clients.value = body.clients ?? []
    } finally {
        if (id === reqId) loading.value = false
    }
}

function close (): void {
    open.value = false
}

function reset (): void {
    query.value = ''
    close()
}

function goDocument (d: DocHit): void {
    reset()
    if (d.status === 'indexed') window.open(`/documents/${d.id}/viewer`, '_blank', 'noopener,noreferrer')
    else router.visit('/knowledge')
}

function goMatter (m: MatterHit): void { reset(); router.visit(`/matters/${m.id}`) }
function goClient (c: ClientHit): void { reset(); router.visit(`/clients/${c.id}`) }

function onClickOutside (e: MouseEvent): void {
    if (root.value && !root.value.contains(e.target as Node)) close()
}

const extIcon = (ext: string): string => {
    const e = (ext || '').toLowerCase()
    if (e === 'pdf') return 'pi pi-file-pdf text-red-500'
    if (['doc', 'docx', 'odt', 'rtf'].includes(e)) return 'pi pi-file-word text-blue-500'
    if (['xls', 'xlsx', 'csv', 'ods'].includes(e)) return 'pi pi-file-excel text-emerald-500'
    return 'pi pi-file text-surface-400'
}

onMounted(() => document.addEventListener('mousedown', onClickOutside))
onBeforeUnmount(() => document.removeEventListener('mousedown', onClickOutside))
</script>

<template>
    <div ref="root" class="relative w-full max-w-md">
        <IconField class="w-full">
            <InputIcon :class="loading ? 'pi pi-spin pi-spinner' : 'pi pi-search'" />
            <InputText
                v-model="query"
                placeholder="Cerca documenti, pratiche, clienti…"
                class="w-full rounded-full"
                @focus="query.trim().length >= 2 && (open = true)"
                @keydown.esc="reset"
            />
        </IconField>

        <!-- Risultati -->
        <div
            v-if="open"
            class="absolute left-0 right-0 top-11 z-50 max-h-[70vh] overflow-y-auto rounded-xl border border-surface-200 bg-surface-0 p-1.5 shadow-2xl dark:border-surface-700 dark:bg-surface-900"
        >
            <div v-if="!hasResults() && !loading" class="px-3 py-6 text-center text-sm text-surface-500">
                Nessun risultato per "{{ query }}".
            </div>

            <template v-else>
                <!-- Documenti -->
                <div v-if="docs.length" class="mb-1">
                    <p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-surface-400">Documenti</p>
                    <button v-for="d in docs" :key="`d-${d.id}`" type="button"
                        class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-800"
                        @click="goDocument(d)">
                        <i :class="extIcon(d.extension)" />
                        <span class="truncate">{{ d.title }}</span>
                        <i class="pi pi-external-link ml-auto text-xs text-surface-400" />
                    </button>
                </div>

                <!-- Pratiche -->
                <div v-if="matters.length" class="mb-1">
                    <p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-surface-400">Pratiche</p>
                    <button v-for="m in matters" :key="`m-${m.id}`" type="button"
                        class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-800"
                        @click="goMatter(m)">
                        <i class="pi pi-briefcase text-primary-500" />
                        <span class="truncate">{{ m.title }}</span>
                    </button>
                </div>

                <!-- Clienti -->
                <div v-if="clients.length">
                    <p class="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-surface-400">Clienti</p>
                    <button v-for="c in clients" :key="`c-${c.id}`" type="button"
                        class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-800"
                        @click="goClient(c)">
                        <i class="pi pi-id-card text-primary-500" />
                        <span class="truncate">{{ c.name }}</span>
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>
