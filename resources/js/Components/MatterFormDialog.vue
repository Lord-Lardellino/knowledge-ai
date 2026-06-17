<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useToast } from 'primevue/usetoast'
import {
    useMatters, fetchClients, createClient, fetchParties, createParty,
    type MatterEntry, type MatterType, type MatterPayload, type MatterStatus,
} from '@/composables/useMatters'
import { useZodForm }  from '@/composables/useZodForm'
import { matterSchema } from '@/validation/schemas'

/**
 * MatterFormDialog — dialog riutilizzabile per creare/modificare una pratica.
 *
 * Usato sia dalla lista (creazione) sia dalla scheda (modifica). Tutto PrimeVue
 * nativo: Dialog, Select, MultiSelect, InputText, Textarea, InputNumber, DatePicker.
 */
const props = defineProps<{
    visible:     boolean
    matterTypes: MatterType[]
    matter?:     MatterEntry | null   // presente = modalità modifica
}>()

const emit = defineEmits<{
    'update:visible': [value: boolean]
    saved:            []
}>()

const toast = useToast()
const { saving, error, create, update } = useMatters()
const { errors, validate, touch } = useZodForm(matterSchema, () => ({ ...form.value }))

const isEdit = computed(() => !!props.matter)

const statusOptions: Array<{ label: string; value: MatterStatus }> = [
    { label: 'Aperta',     value: 'open' },
    { label: 'Sospesa',    value: 'suspended' },
    { label: 'Chiusa',     value: 'closed' },
    { label: 'Archiviata', value: 'archived' },
]

const clients      = ref<Array<{ id: number; name: string }>>([])
const parties      = ref<Array<{ id: number; name: string }>>([])
const selectedParties = ref<number[]>([])

const blank = (): MatterPayload => ({
    client_id: null, matter_type_id: null, title: '', reference: null,
    status: 'open', outcome: null, value_cents: null,
    opened_at: null, closed_at: null, notes: null, parties: [],
})

const form = ref<MatterPayload>(blank())

// Importo in euro (UI) ↔ value_cents (backend).
const valueEuro = computed<number | null>({
    get: () => form.value.value_cents != null ? form.value.value_cents / 100 : null,
    set: (v) => { form.value.value_cents = v != null ? Math.round(v * 100) : null },
})

// DatePicker lavora con Date; il payload viaggia come stringa ISO (YYYY-MM-DD).
const openedDate = computed<Date | null>({
    get: () => form.value.opened_at ? new Date(form.value.opened_at) : null,
    set: (d) => { form.value.opened_at = d ? d.toISOString().slice(0, 10) : null },
})

watch(() => props.visible, async (open) => {
    if (! open) return

    clients.value = await fetchClients()
    parties.value = await fetchParties()

    if (props.matter) {
        const m = props.matter
        form.value = {
            client_id: m.client_id, matter_type_id: m.matter_type_id,
            title: m.title, reference: m.reference, status: m.status,
            outcome: null, value_cents: m.value_cents,
            opened_at: m.opened_at, closed_at: null, notes: null, parties: [],
        }
        // Pre-popola le controparti se la pratica arriva con la relazione caricata.
        const loaded = (m as { parties?: Array<{ id: number }> }).parties ?? []
        selectedParties.value = loaded.map(p => p.id)
    } else {
        form.value = blank()
        selectedParties.value = []
    }
})

async function onCreateClient (name: string): Promise<void> {
    const created = await createClient({ name, type: 'company' })
    if (created) {
        clients.value.push(created)
        form.value.client_id = created.id
    }
}

async function onCreateParty (name: string): Promise<void> {
    const created = await createParty({ name, type: 'company' })
    if (created) {
        parties.value.push(created)
        selectedParties.value.push(created.id)
    }
}

async function submit (): Promise<void> {
    if (! validate(form.value)) return

    form.value.parties = selectedParties.value.map(id => ({ party_id: id, role: null }))

    const ok = props.matter
        ? await update(props.matter.id, form.value)
        : await create(form.value)

    if (ok) {
        toast.add({ severity: 'success', summary: 'Salvato', detail: 'Pratica salvata.', life: 3000 })
        emit('saved')
        emit('update:visible', false)
    } else {
        toast.add({ severity: 'error', summary: 'Errore', detail: error.value ?? 'Salvataggio fallito.', life: 5000 })
    }
}
</script>

<template>
    <Dialog
        :visible="visible"
        @update:visible="emit('update:visible', $event)"
        :header="isEdit ? 'Modifica pratica' : 'Nuova pratica'"
        modal
        :style="{ width: '40rem' }"
        :breakpoints="{ '640px': '95vw' }"
    >
        <div class="flex flex-col gap-4">

            <div class="flex flex-col gap-1.5">
                <label class="text-sm font-medium">Titolo</label>
                <InputText v-model="form.title" placeholder="Es. Rossi SRL vs Alfa S.p.A." :invalid="!!errors.title" fluid @blur="touch('title')" />
                <Message v-if="errors.title" severity="error" size="small" variant="simple">{{ errors.title }}</Message>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Cliente</label>
                    <Select
                        v-model="form.client_id"
                        :options="clients" optionLabel="name" optionValue="id"
                        placeholder="Seleziona cliente" filter editable
                        @keyup.enter="(e: any) => e.target.value && onCreateClient(e.target.value)"
                        :invalid="!!errors.client_id" fluid
                        @blur="touch('client_id')"
                    />
                    <Message v-if="errors.client_id" severity="error" size="small" variant="simple">{{ errors.client_id }}</Message>
                    <small v-else class="text-surface-400">Scrivi e premi Invio per crearne uno nuovo.</small>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Materia</label>
                    <Select
                        v-model="form.matter_type_id"
                        :options="matterTypes" optionLabel="label" optionValue="id"
                        placeholder="Seleziona materia" showClear fluid
                    />
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label class="text-sm font-medium">Controparti</label>
                <MultiSelect
                    v-model="selectedParties"
                    :options="parties" optionLabel="name" optionValue="id"
                    placeholder="Seleziona controparti" filter display="chip"
                    @keyup.enter="(e: any) => e.target.value && onCreateParty(e.target.value)"
                    fluid
                />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Stato</label>
                    <Select v-model="form.status" :options="statusOptions" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Riferimento</label>
                    <InputText v-model="form.reference" placeholder="Codice interno" fluid />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Valore (€)</label>
                    <InputNumber v-model="valueEuro" mode="currency" currency="EUR" locale="it-IT" :min="0" fluid />
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label class="text-sm font-medium">Data apertura</label>
                <DatePicker v-model="openedDate" dateFormat="dd/mm/yy" showIcon fluid />
            </div>

            <div class="flex flex-col gap-1.5">
                <label class="text-sm font-medium">Note</label>
                <Textarea v-model="form.notes" rows="3" autoResize fluid />
            </div>
        </div>

        <template #footer>
            <Button label="Annulla" severity="secondary" text @click="emit('update:visible', false)" />
            <Button :label="isEdit ? 'Salva' : 'Crea pratica'" icon="pi pi-check" :loading="saving" @click="submit" />
        </template>
    </Dialog>
</template>
