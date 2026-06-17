<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useToast } from 'primevue/usetoast'
import { fetchClients } from '@/composables/useMatters'
import { useZodForm }  from '@/composables/useZodForm'
import { matterFromDocsSchema } from '@/validation/schemas'
import type { MatterType, MatterStatus } from '@/composables/useMatters'
import type { DocumentMetadata } from '@/composables/useDocumentMetadata'

/**
 * CreateMatterFromDocsDialog — crea una pratica dai documenti selezionati nella
 * vista "Da smistare". Precompila cliente/controparti/titolo aggregando i metadati
 * estratti, poi l'avvocato conferma. Tutto PrimeVue nativo.
 */
interface TriageDoc {
    id: number
    title: string
    metadata: DocumentMetadata | null
}

const props = defineProps<{
    visible:     boolean
    documents:   TriageDoc[]      // documenti selezionati
    matterTypes: MatterType[]
}>()

const emit = defineEmits<{ 'update:visible': [value: boolean] }>()

const toast = useToast()
const saving = ref(false)
const { errors, validate, touch, clear: clearErrors } = useZodForm(
    matterFromDocsSchema,
    () => ({ title: form.value.title, client_name: form.value.client_name }),
)

// Pratiche attive del cliente (per "aggiungi a esistente").
interface ExistingMatter { id: number; title: string; status: string }
const existingMatters = ref<ExistingMatter[]>([])
const mode            = ref<'new' | 'existing'>('new')
const targetMatterId  = ref<number | null>(null)

const modeOptions = [
    { label: 'Nuova pratica', value: 'new' },
    { label: 'Aggiungi a esistente', value: 'existing' },
]

// Autocomplete clienti: nomi esistenti + possibilità di crearne uno nuovo.
const clientNames       = ref<string[]>([])
const clientSuggestions = ref<string[]>([])

async function loadClientNames (): Promise<void> {
    clientNames.value = (await fetchClients()).map(c => c.name)
}

function searchClients (event: { query: string }): void {
    const q = event.query.trim().toLowerCase()
    clientSuggestions.value = q
        ? clientNames.value.filter(n => n.toLowerCase().includes(q))
        : [...clientNames.value]
}

const statusOptions: Array<{ label: string; value: MatterStatus }> = [
    { label: 'Aperta', value: 'open' },
    { label: 'Sospesa', value: 'suspended' },
    { label: 'Chiusa', value: 'closed' },
    { label: 'Archiviata', value: 'archived' },
]

const typeOptions = [
    { label: 'Azienda', value: 'company' },
    { label: 'Persona', value: 'person' },
]

const form = ref({
    title: '',
    client_name: '',
    client_type: 'company' as 'person' | 'company',
    matter_type_id: null as number | null,
    status: 'open' as MatterStatus,
    parties: [] as Array<{ name: string; role: string }>,
})

// Riconosce il ruolo "cliente/assistito" tra le parti estratte.
const CLIENT_ROLE = /client|clien|assistit|ricorrent|attore/i

watch(() => props.visible, (open) => {
    if (! open) return
    existingMatters.value = []
    mode.value = 'new'
    targetMatterId.value = null
    loadClientNames()
    prefillFromMetadata()
    lookupClient(form.value.client_name)
})

// Cerca le pratiche aperte del cliente; se ce ne sono, abilita "aggiungi a esistente".
watch(() => form.value.client_name, (name) => lookupClient(name))

async function lookupClient (name: string): Promise<void> {
    const q = (name ?? '').trim()
    if (! q) { existingMatters.value = []; return }

    const res = await fetch(`/clients/lookup?name=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
    if (! res.ok) { existingMatters.value = []; return }

    const body = await res.json()
    existingMatters.value = body.matters ?? []
    if (existingMatters.value.length) {
        targetMatterId.value = existingMatters.value[0].id
    } else {
        mode.value = 'new'
    }
}

function prefillFromMetadata (): void {
    const parties: Array<{ name: string; role: string }> = []
    let client = ''

    for (const doc of props.documents) {
        for (const p of doc.metadata?.parties ?? []) {
            const name = (p.name ?? '').trim()
            if (! name) continue
            const role = (p.role ?? '').trim()
            if (! client && CLIENT_ROLE.test(role)) {
                client = name
                continue
            }
            if (! parties.some(x => x.name.toLowerCase() === name.toLowerCase())) {
                parties.push({ name, role })
            }
        }
    }

    // Rimuove dal'elenco controparti l'eventuale cliente individuato.
    const counter = client ? parties.filter(p => p.name.toLowerCase() !== client.toLowerCase()) : parties

    form.value = {
        title: suggestTitle(client, counter),
        client_name: client,
        client_type: 'company',
        matter_type_id: null,
        status: 'open',
        parties: counter,
    }
}

function suggestTitle (client: string, parties: Array<{ name: string }>): string {
    if (client && parties.length) return `${client} c/ ${parties[0].name}`
    if (client) return client
    return props.documents[0]?.title ?? 'Nuova pratica'
}

const canSubmit = computed(() => {
    if (mode.value === 'existing') return targetMatterId.value != null
    return form.value.title.trim() !== '' && form.value.client_name.trim() !== ''
})

function addParty () { form.value.parties.push({ name: '', role: '' }) }

async function submit (): Promise<void> {
    // In modalità "nuova pratica" valida cliente e titolo; in "esistente" basta la pratica.
    if (mode.value === 'new') {
        if (! validate({ title: form.value.title, client_name: form.value.client_name })) return
    } else {
        clearErrors()
    }

    saving.value = true
    try {
        const docIds = props.documents.map(d => d.id)

        // Aggiunta a pratica esistente: aggancia solo i documenti.
        const res = mode.value === 'existing' && targetMatterId.value != null
            ? await fetch(`/matters/${targetMatterId.value}/documents`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body:    JSON.stringify({ document_ids: docIds }),
            })
            : await fetch('/matters/from-documents', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({
                    document_ids:   docIds,
                    title:          form.value.title,
                    client_name:    form.value.client_name,
                    client_type:    form.value.client_type,
                    matter_type_id: form.value.matter_type_id,
                    status:         form.value.status,
                    parties:        form.value.parties.filter(p => p.name.trim() !== ''),
                }),
            })

        if (res.ok) {
            const body = await res.json()
            toast.add({ severity: 'success',
                summary: mode.value === 'existing' ? 'Documenti aggiunti' : 'Pratica creata',
                detail: 'Documenti agganciati alla pratica.', life: 3000 })
            emit('update:visible', false)
            router.visit(`/matters/${body.id}`)
        } else {
            toast.add({ severity: 'error', summary: 'Errore', detail: 'Creazione fallita.', life: 5000 })
        }
    } finally {
        saving.value = false
    }
}

function csrf (): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="emit('update:visible', $event)"
        header="Crea pratica dai documenti"
        modal :style="{ width: '40rem' }" :breakpoints="{ '640px': '95vw' }"
    >
        <div class="flex flex-col gap-4">
            <Message severity="info" :closable="false" class="text-sm">
                {{ documents.length }} document{{ documents.length === 1 ? 'o' : 'i' }} verranno agganciati a questa pratica.
            </Message>

            <!-- Il cliente ha già pratiche attive: scelta nuova / esistente -->
            <div v-if="existingMatters.length" class="flex flex-col gap-2 rounded-lg border border-surface-200 dark:border-surface-700 p-3">
                <p class="text-sm font-medium">
                    Questo cliente ha già {{ existingMatters.length }} pratic{{ existingMatters.length === 1 ? 'a' : 'he' }} attiv{{ existingMatters.length === 1 ? 'a' : 'e' }}.
                </p>
                <SelectButton v-model="mode" :options="modeOptions" optionLabel="label" optionValue="value" :allowEmpty="false" />
                <Select v-if="mode === 'existing'"
                    v-model="targetMatterId" :options="existingMatters" optionLabel="title" optionValue="id"
                    placeholder="Scegli la pratica" fluid />
            </div>

            <!-- Campi nuova pratica (nascosti se aggiungo a una esistente) -->
            <div v-if="mode === 'new'" class="flex flex-col gap-1.5">
                <label class="text-sm font-medium">Titolo pratica</label>
                <InputText v-model="form.title" :invalid="!!errors.title" fluid @blur="touch('title')" />
                <Message v-if="errors.title" severity="error" size="small" variant="simple">{{ errors.title }}</Message>
            </div>

            <div v-if="mode === 'new'" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="flex flex-col gap-1.5 sm:col-span-2">
                    <label class="text-sm font-medium">Cliente</label>
                    <AutoComplete
                        v-model="form.client_name"
                        :suggestions="clientSuggestions"
                        @complete="searchClients"
                        dropdown completeOnFocus
                        placeholder="Cerca o digita un nuovo cliente" :invalid="!!errors.client_name" fluid
                        @blur="touch('client_name')" />
                    <Message v-if="errors.client_name" severity="error" size="small" variant="simple">{{ errors.client_name }}</Message>
                    <small v-else class="text-surface-400">Scegli un cliente esistente o digitane uno nuovo (verrà creato).</small>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Tipo</label>
                    <Select v-model="form.client_type" :options="typeOptions" optionLabel="label" optionValue="value" fluid />
                </div>
            </div>

            <div v-if="mode === 'new'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Materia</label>
                    <Select v-model="form.matter_type_id" :options="matterTypes" optionLabel="label" optionValue="id"
                        placeholder="Seleziona materia" showClear fluid />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Stato</label>
                    <Select v-model="form.status" :options="statusOptions" optionLabel="label" optionValue="value" fluid />
                </div>
            </div>

            <div v-if="mode === 'new'" class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium">Controparti</label>
                    <Button icon="pi pi-plus" label="Aggiungi" size="small" text @click="addParty" />
                </div>
                <div v-for="(p, i) in form.parties" :key="`p-${i}`" class="flex gap-2">
                    <InputText v-model="p.name" placeholder="Nome" class="flex-1" />
                    <InputText v-model="p.role" placeholder="Ruolo" class="w-40" />
                    <Button icon="pi pi-trash" severity="danger" text @click="form.parties.splice(i, 1)" />
                </div>
                <p v-if="!form.parties.length" class="text-sm text-surface-400">Nessuna controparte rilevata.</p>
            </div>
        </div>

        <template #footer>
            <Button label="Annulla" severity="secondary" text @click="emit('update:visible', false)" />
            <Button :label="mode === 'existing' ? 'Aggiungi alla pratica' : 'Crea pratica'" icon="pi pi-check"
                :disabled="!canSubmit" :loading="saving" @click="submit" />
        </template>
    </Dialog>
</template>
