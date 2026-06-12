<script setup lang="ts">
import { ref, computed } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePasskeyManagement } from '@/composables/usePasskeyManagement'
import type { PasskeyEntry } from '@/composables/usePasskeyManagement'


defineOptions({ layout: AppLayout })

const props = defineProps<{
    auth: { user: { name: string; email: string; email_verified_at: string | null } }
    initialKeys: PasskeyEntry[]
}>()

const toast = useToast()
const confirm = useConfirm()

const { keys, loading, error, addKey, renameKey, removeKey } = usePasskeyManagement(props.initialKeys ?? [])

const isRecovery = computed(() => new URLSearchParams(window.location.search).get('recovery') === '1')
const emailVerified = computed(() => !!props.auth.user.email_verified_at)

const newKeyName = ref('')
const showAddForm = ref(false)
const editingId = ref<number | null>(null)
const editName = ref('')

function startRename(key: PasskeyEntry): void {
    editingId.value = key.id
    editName.value = key.name
}

function cancelRename(): void {
    editingId.value = null
    editName.value = ''
}

async function confirmRename(id: number): Promise<void> {
    const name = editName.value.trim()
    if (!name) { cancelRename(); return }
    const ok = await renameKey(id, name)
    if (ok) {
        cancelRename()
        toast.add({ severity: 'success', summary: 'Rinominata', detail: 'Nome aggiornato.', life: 3000 })
    } else if (error.value) {
        toast.add({ severity: 'error', summary: 'Errore', detail: error.value, life: 5000 })
    }
}

async function handleAddKey(): Promise<void> {
    const ok = await addKey(newKeyName.value.trim() || undefined)
    if (ok) {
        showAddForm.value = false
        newKeyName.value = ''
        toast.add({ severity: 'success', summary: 'Passkey aggiunta', detail: 'Nuovo dispositivo registrato.', life: 4000 })
    } else if (error.value) {
        toast.add({ severity: 'error', summary: 'Errore', detail: error.value, life: 5000 })
    }
}

function handleRemoveKey(key: PasskeyEntry): void {
    if (keys.value.length <= 1 && !emailVerified.value) {
        toast.add({ severity: 'warn', summary: 'Impossibile eliminare', detail: "Non puoi eliminare l'unica passkey senza un'email verificata.", life: 6000 })
        return
    }
    confirm.require({
        message: `Vuoi davvero eliminare la passkey "${key.name}"?`,
        header: 'Elimina passkey',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annulla',
        acceptLabel: 'Elimina',
        acceptClass: 'p-button-danger',
        accept: async () => {
            const ok = await removeKey(key.id)
            if (ok) {
                toast.add({ severity: 'success', summary: 'Passkey eliminata', detail: `"${key.name}" rimossa dall'account.`, life: 4000 })
            } else if (error.value) {
                toast.add({ severity: 'error', summary: 'Errore', detail: error.value, life: 5000 })
            }
        },
    })
}
</script>

<template>
    <div class="max-w-3xl mx-auto space-y-4">

        <Message v-if="isRecovery" severity="warn" :closable="false">
            <strong>Accesso tramite link di recupero.</strong>
            Registra subito un nuovo dispositivo cliccando "Aggiungi passkey".
        </Message>

        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-surface-900 dark:text-surface-0">I tuoi dispositivi</h1>
                <p class="text-sm text-surface-500 mt-0.5">Gestisci le passkey registrate sul tuo account.</p>
            </div>
            <Button v-if="!showAddForm" label="Aggiungi passkey" icon="pi pi-plus" @click="showAddForm = true" />
        </div>

        <!-- Form aggiunta -->
        <Card v-if="showAddForm">
            <template #title>Registra un nuovo dispositivo</template>
            <template #content>
                <p class="text-sm text-surface-500 mb-4">
                    Il tuo dispositivo ti chiederà di usare Face ID, Touch ID o Windows Hello.
                </p>
                <div class="flex flex-col gap-2 mb-4">
                    <label for="key-name" class="text-sm font-medium">
                        Nome dispositivo <span class="text-surface-400">(opzionale)</span>
                    </label>
                    <InputText id="key-name" v-model="newKeyName" placeholder="es. MacBook Pro, iPhone 15..."
                        class="w-full max-w-sm" :disabled="loading" />
                </div>
                <div class="flex gap-3">
                    <Button label="Registra passkey" icon="pi pi-fingerprint" :loading="loading"
                        @click="handleAddKey" />
                    <Button label="Annulla" severity="secondary" text :disabled="loading"
                        @click="showAddForm = false; newKeyName = ''" />
                </div>
            </template>
        </Card>

        <!-- Tabella passkey -->
        <Card>
            <template #content>
                <DataTable :value="keys" :loading="loading && keys.length === 0">
                    <Column field="name" header="Dispositivo">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <i class="pi pi-mobile text-surface-400 shrink-0" />
                                <template v-if="editingId === data.id">
                                    <InputText v-model="editName" size="small" class="w-48" autofocus
                                        @keyup="(e: KeyboardEvent) => { if (e.key === 'Enter') confirmRename(data.id); else if (e.key === 'Escape') cancelRename() }" />
                                    <Button icon="pi pi-check" severity="success" text rounded size="small"
                                        @click="confirmRename(data.id)" />
                                    <Button icon="pi pi-times" severity="secondary" text rounded size="small"
                                        @click="cancelRename" />
                                </template>
                                <template v-else>
                                    <span class="font-medium">{{ data.name }}</span>
                                    <Button icon="pi pi-pencil" text rounded size="small" @click="startRename(data)" />
                                </template>
                            </div>
                        </template>
                    </Column>
                    <Column field="registered_at" header="Registrata il" />
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <Button icon="pi pi-trash" severity="danger" text rounded size="small"
                                :disabled="keys.length <= 1 && !emailVerified"
                                v-tooltip.top="keys.length <= 1 && !emailVerified ? 'Verifica l\'email prima di eliminare l\'unica passkey' : 'Elimina'"
                                @click="handleRemoveKey(data)" />
                        </template>
                    </Column>
                    <template #empty>
                        <div class="text-center py-8 text-surface-400">
                            <i class="pi pi-key text-3xl mb-2 block" />
                            <p>Nessuna passkey registrata.</p>
                        </div>
                    </template>
                </DataTable>
            </template>
        </Card>
        <p class="text-xs text-surface-400">
            Se perdi un dispositivo, eliminalo subito per revocare l'accesso.
            Puoi sempre recuperare l'accesso tramite email dalla pagina di login.
        </p>
    </div>
</template>
