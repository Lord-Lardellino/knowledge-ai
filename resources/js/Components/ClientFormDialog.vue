<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useClients, emptyClient, type ClientEntry, type ClientPayload } from '@/composables/useClients'
import { useZodForm }  from '@/composables/useZodForm'
import { clientSchema } from '@/validation/schemas'

/**
 * ClientFormDialog — dialog riutilizzabile per creare/modificare un cliente.
 * Tutto PrimeVue nativo. Usato dalla pagina anagrafica clienti.
 */
const props = defineProps<{
    visible: boolean
    client?: ClientEntry | null   // presente = modifica
}>()

const emit = defineEmits<{
    'update:visible': [value: boolean]
    saved:            []
}>()

const toast = useToast()
const { saving, error, create, update } = useClients()
const { errors, validate, touch } = useZodForm(clientSchema, () => ({ ...form.value }))

const isEdit = computed(() => !!props.client)

const typeOptions = [
    { label: 'Azienda', value: 'company' },
    { label: 'Persona', value: 'person' },
]

const form = ref<ClientPayload>(emptyClient())

watch(() => props.visible, (open) => {
    if (! open) return
    if (props.client) {
        const c = props.client
        form.value = {
            name: c.name, type: c.type, tax_code: null, vat: null,
            email: c.email, phone: c.phone, notes: null,
        }
    } else {
        form.value = emptyClient()
    }
})

async function submit (): Promise<void> {
    if (! validate(form.value)) return

    const ok = props.client
        ? await update(props.client.id, form.value)
        : await create(form.value)

    if (ok) {
        toast.add({ severity: 'success', summary: 'Salvato', detail: 'Cliente salvato.', life: 3000 })
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
        :header="isEdit ? 'Modifica cliente' : 'Nuovo cliente'"
        modal :style="{ width: '34rem' }" :breakpoints="{ '640px': '95vw' }"
    >
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-1.5">
                <label class="text-sm font-medium">Nome / Ragione sociale</label>
                <InputText v-model="form.name" :invalid="!!errors.name" fluid @blur="touch('name')" />
                <Message v-if="errors.name" severity="error" size="small" variant="simple">{{ errors.name }}</Message>
            </div>

            <div class="flex flex-col gap-1.5">
                <label class="text-sm font-medium">Tipo</label>
                <Select v-model="form.type" :options="typeOptions" optionLabel="label" optionValue="value" fluid />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Codice fiscale</label>
                    <InputText v-model="form.tax_code" fluid />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Partita IVA</label>
                    <InputText v-model="form.vat" fluid />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Email</label>
                    <InputText v-model="form.email" :invalid="!!errors.email" fluid @blur="touch('email')" />
                    <Message v-if="errors.email" severity="error" size="small" variant="simple">{{ errors.email }}</Message>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label class="text-sm font-medium">Telefono</label>
                    <InputText v-model="form.phone" fluid />
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label class="text-sm font-medium">Note</label>
                <Textarea v-model="form.notes" rows="3" autoResize fluid />
            </div>
        </div>

        <template #footer>
            <Button label="Annulla" severity="secondary" text @click="emit('update:visible', false)" />
            <Button :label="isEdit ? 'Salva' : 'Crea cliente'" icon="pi pi-check" :loading="saving" @click="submit" />
        </template>
    </Dialog>
</template>
