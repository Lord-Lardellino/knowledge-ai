<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { fullscreenOverlay } from '@/stores/ui'

interface Source {
    n: number
    document_id: number
    title: string
    chunk_index: number
    snippet: string
    similarity: number
}

interface ChatMessage {
    role: 'user' | 'assistant'
    content: string
    sources?: Source[]
    error?: boolean
    retry?: boolean
}

const open = ref(false)
const input = ref('')
const loading = ref(false)
const messages = ref<ChatMessage[]>([])
const scroller = ref<HTMLElement | null>(null)

// Filtro pratica: limita la chat ai documenti di un fascicolo.
const matters = ref<{ id: number; title: string }[]>([])
const selectedMatter = ref<number | null>(null)
let mattersLoaded = false

async function loadMatters (): Promise<void> {
    if (mattersLoaded) return
    mattersLoaded = true
    try {
        const res = await fetch('/chat/matters', { headers: { Accept: 'application/json' } })
        if (res.ok) matters.value = await res.json()
    } catch { /* il filtro resta opzionale */ }
}

function csrf (): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

function toggle (): void {
    open.value = !open.value
    if (open.value) {
        void loadMatters()
        nextTick(scrollDown)
    }
}

function scrollDown (): void {
    nextTick(() => {
        if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
    })
}

function openSource (s: Source): void {
    window.open(`/documents/${s.document_id}/viewer`, '_blank', 'noopener,noreferrer')
}

async function send (): Promise<void> {
    const question = input.value.trim()
    if (question === '' || loading.value) return

    messages.value.push({ role: 'user', content: question })
    input.value = ''
    loading.value = true
    scrollDown()

    // Storico (escluso il turno appena aggiunto, e gli errori/sovraccarichi) per
    // mantenere il filo senza sprecare token su messaggi non utili.
    const history = messages.value
        .slice(0, -1)
        .filter(m => !m.error && !m.retry)
        .map(m => ({ role: m.role, content: m.content }))

    try {
        const res = await fetch('/chat/ask', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ question, history, matter_id: selectedMatter.value }),
        })

        const body = await res.json().catch(() => ({}))
        if (!res.ok) {
            messages.value.push({ role: 'assistant', content: body.message ?? 'Errore nella richiesta.', error: true })
        } else {
            messages.value.push({ role: 'assistant', content: body.answer, sources: body.sources ?? [], retry: body.retry === true })
        }
    } catch {
        messages.value.push({ role: 'assistant', content: 'Errore di connessione.', error: true })
    } finally {
        loading.value = false
        scrollDown()
    }
}

function onKeydown (e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault()
        void send()
    }
}

function reset (): void {
    messages.value = []
}
</script>

<template>
    <!-- Pannello chat -->
    <Transition name="chat-pop">
        <div
            v-if="open && !fullscreenOverlay"
            class="fixed bottom-24 right-5 z-50 flex h-[min(34rem,75vh)] w-[min(26rem,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-2xl border border-surface-200 bg-surface-0 shadow-2xl dark:border-surface-700 dark:bg-surface-900"
        >
            <!-- Header -->
            <div class="flex items-center justify-between gap-2 border-b border-surface-200 bg-primary-500/10 px-4 py-3 dark:border-surface-700">
                <div class="flex items-center gap-2">
                    <i class="pi pi-sparkles text-primary-500" />
                    <span class="text-sm font-semibold">Assistente documenti</span>
                </div>
                <div class="flex items-center gap-1">
                    <Button v-if="messages.length" icon="pi pi-trash" severity="secondary" text rounded size="small" v-tooltip.bottom="'Nuova conversazione'" @click="reset" />
                    <Button icon="pi pi-times" severity="secondary" text rounded size="small" @click="open = false" />
                </div>
            </div>

            <!-- Filtro pratica -->
            <div v-if="matters.length" class="border-b border-surface-200 px-3 py-2 dark:border-surface-700">
                <Select
                    v-model="selectedMatter"
                    :options="matters"
                    optionLabel="title"
                    optionValue="id"
                    placeholder="Tutti i documenti"
                    showClear
                    size="small"
                    class="w-full"
                >
                    <template #dropdownicon><i class="pi pi-folder text-xs" /></template>
                </Select>
            </div>

            <!-- Messaggi -->
            <div ref="scroller" class="flex-1 space-y-3 overflow-y-auto p-4">
                <div v-if="!messages.length" class="mt-6 text-center text-sm text-surface-500">
                    <i class="pi pi-comments mb-2 block text-2xl text-surface-300" />
                    Chiedi qualcosa sui tuoi documenti.<br>
                    <span class="text-xs">Es. "Qual è la scadenza nella pratica X?", "Riassumi la sentenza", "Trova la clausola penale".</span>
                </div>

                <div v-for="(m, i) in messages" :key="i" class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                    <div
                        class="max-w-[85%] rounded-2xl px-3.5 py-2 text-sm leading-relaxed"
                        :class="m.role === 'user'
                            ? 'bg-primary-500 text-white'
                            : m.error
                                ? 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-200'
                                : 'bg-surface-100 text-surface-800 dark:bg-surface-800 dark:text-surface-100'"
                    >
                        <p class="m-0 whitespace-pre-wrap">{{ m.content }}</p>

                        <!-- Fonti citate -->
                        <div v-if="m.sources && m.sources.length" class="mt-2 flex flex-col gap-1 border-t border-surface-200/60 pt-2 dark:border-surface-700/60">
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-surface-400">Fonti</span>
                            <button
                                v-for="s in m.sources"
                                :key="s.n"
                                type="button"
                                class="flex items-start gap-1.5 rounded-md px-1.5 py-1 text-left text-xs text-surface-600 transition-colors hover:bg-surface-200/70 dark:text-surface-300 dark:hover:bg-surface-700/60"
                                v-tooltip.left="s.snippet"
                                @click="openSource(s)"
                            >
                                <span class="mt-px rounded bg-primary-500/15 px-1 font-mono text-[10px] text-primary-600 dark:text-primary-300">{{ s.n }}</span>
                                <span class="truncate">{{ s.title }}</span>
                                <i class="pi pi-external-link ml-auto text-[10px] opacity-60" />
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="loading" class="flex justify-start">
                    <div class="rounded-2xl bg-surface-100 px-3.5 py-2 text-sm text-surface-500 dark:bg-surface-800">
                        <i class="pi pi-spin pi-spinner mr-1" />sto cercando nei documenti…
                    </div>
                </div>
            </div>

            <!-- Input -->
            <div class="flex items-end gap-2 border-t border-surface-200 p-3 dark:border-surface-700">
                <Textarea
                    v-model="input"
                    rows="1"
                    autoResize
                    placeholder="Scrivi una domanda…"
                    class="max-h-32 flex-1 text-sm"
                    @keydown="onKeydown"
                />
                <Button icon="pi pi-send" rounded :disabled="loading || !input.trim()" @click="send" />
            </div>
        </div>
    </Transition>

    <!-- Pulsante flottante (nascosto durante un overlay full-screen) -->
    <Button
        v-if="!fullscreenOverlay"
        :icon="open ? 'pi pi-times' : 'pi pi-comments'"
        rounded
        class="fixed bottom-5 right-5 z-50 size-14 shadow-xl"
        v-tooltip.left="'Assistente documenti'"
        @click="toggle"
    />
</template>

<style scoped>
.chat-pop-enter-active, .chat-pop-leave-active { transition: opacity .15s ease, transform .15s ease; }
.chat-pop-enter-from, .chat-pop-leave-to { opacity: 0; transform: translateY(8px) scale(.98); }
</style>
