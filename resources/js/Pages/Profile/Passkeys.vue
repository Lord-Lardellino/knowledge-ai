<script setup lang="ts">
/**
 * Passkeys.vue — pagina di gestione passkey dell'utente autenticato
 *
 * BACKEND (Laravel):
 *   Route::get('/profile/passkeys', function () {
 *       $keys = WebauthnKey::where('user_id', auth()->id())
 *           ->orderBy('created_at', 'desc')
 *           ->get(['id', 'name', 'type', 'created_at'])
 *           ->map(fn ($key) => [
 *               'id'            => $key->id,
 *               'name'          => $key->name,
 *               'type'          => $key->type,
 *               'registered_at' => $key->created_at->format('d/m/Y'),
 *           ]);
 *       return Inertia::render('Profile/Passkeys', [
 *           'auth'        => ['user' => auth()->user()],
 *           'initialKeys' => $keys,
 *       ]);
 *   });
 *
 * FUNZIONALITÀ:
 *   - Mostra la lista delle passkey registrate (nome, data)
 *   - Permette di aggiungere un nuovo dispositivo
 *   - Permette di eliminare una passkey (es. device smarrito)
 *   - Banner di recovery: se arriva da /recover/verify?recovery=1,
 *     mostra un avviso che invita a registrare subito un nuovo dispositivo
 *
 * PROTEZIONE UX:
 *   Il pulsante elimina è disabilitato se rimane solo 1 passkey
 *   e l'email non è verificata (il backend blocca comunque, ma
 *   disabilitiamo il bottone per un feedback immediato).
 *
 * USA IL LAYOUT AppLayout per menu e toast globali.
 */
import { ref, computed }                from 'vue'
import { useToast }                     from 'primevue/usetoast'
import { useConfirm }                   from 'primevue/useconfirm'
import Button                           from 'primevue/button'
import DataTable                        from 'primevue/datatable'
import Column                           from 'primevue/column'
import Message                          from 'primevue/message'
import InputText                        from 'primevue/inputtext'
import AppLayout                        from '@/Layouts/AppLayout.vue'
import { usePasskeyManagement }         from '@/composables/usePasskeyManagement'
import type { PasskeyEntry }            from '@/composables/usePasskeyManagement'

// Indica al router Inertia di usare AppLayout per questa Page
defineOptions({ layout: AppLayout })

const props = defineProps<{
    auth:        { user: { name: string; email: string; email_verified_at: string | null } }
    initialKeys: PasskeyEntry[]
}>()

const toast   = useToast()
const confirm = useConfirm()

const { keys, loading, error, addKey, renameKey, removeKey } = usePasskeyManagement(props.initialKeys ?? [])

// Controlla se l'utente è arrivato tramite il flusso di recovery
// (il RecoveryController aggiunge ?recovery=1 al redirect)
const isRecovery = computed(() =>
    new URLSearchParams(window.location.search).get('recovery') === '1'
)

// Controlla se l'email è verificata — usato per abilitare/disabilitare elimina
const emailVerified = computed(() => !! props.auth.user.email_verified_at)

// Nome per la nuova passkey (modificabile dall'utente)
const newKeyName = ref('')
const showAddForm = ref(false)

// initialKeys arriva come prop dal server — nessun fetch iniziale necessario

// Stato rinomina inline
const editingId = ref<number | null>(null)
const editName  = ref('')

function startRename (key: PasskeyEntry): void {
    editingId.value = key.id
    editName.value  = key.name
}

function cancelRename (): void {
    editingId.value = null
    editName.value  = ''
}

async function confirmRename (id: number): Promise<void> {
    const name = editName.value.trim()
    if (! name) { cancelRename(); return }

    const ok = await renameKey(id, name)
    if (ok) {
        cancelRename()
        toast.add({ severity: 'success', summary: 'Rinominata', detail: 'Nome aggiornato.', life: 3000 })
    } else if (error.value) {
        toast.add({ severity: 'error', summary: 'Errore', detail: error.value, life: 5000 })
    }
}

// ---------------------------------------------------------------------------
// Aggiunge una nuova passkey
// ---------------------------------------------------------------------------
async function handleAddKey (): Promise<void> {
    const name = newKeyName.value.trim() || undefined
    const ok   = await addKey(name)

    if (ok) {
        showAddForm.value = false
        newKeyName.value  = ''
        toast.add({
            severity: 'success',
            summary:  'Passkey aggiunta',
            detail:   'Il nuovo dispositivo è stato registrato con successo.',
            life:      4000,
        })
    } else if (error.value) {
        toast.add({
            severity: 'error',
            summary:  'Errore',
            detail:   error.value,
            life:      5000,
        })
    }
}

// ---------------------------------------------------------------------------
// Elimina una passkey con dialog di conferma
// ---------------------------------------------------------------------------
function handleRemoveKey (key: PasskeyEntry): void {
    // Se è l'unica passkey e l'email non è verificata, blocca subito
    if (keys.value.length <= 1 && ! emailVerified.value) {
        toast.add({
            severity: 'warn',
            summary:  'Impossibile eliminare',
            detail:   'Non puoi eliminare l\'unica passkey senza un\'email verificata.',
            life:      6000,
        })
        return
    }

    // Dialog di conferma prima di eliminare
    confirm.require({
        message: `Vuoi davvero eliminare la passkey "${key.name}"?`
                + (keys.value.length <= 1 ? '\n\nAttenzione: è l\'unica passkey registrata.' : ''),
        header:  'Elimina passkey',
        icon:    'pi pi-exclamation-triangle',
        rejectLabel:  'Annulla',
        acceptLabel:  'Elimina',
        acceptClass:  'p-button-danger',
        accept: async () => {
            const ok = await removeKey(key.id)
            if (ok) {
                toast.add({
                    severity: 'success',
                    summary:  'Passkey eliminata',
                    detail:   `"${key.name}" è stata rimossa dall\'account.`,
                    life:      4000,
                })
            } else if (error.value) {
                toast.add({
                    severity: 'error',
                    summary:  'Errore',
                    detail:   error.value,
                    life:      5000,
                })
            }
        },
    })
}
</script>

<template>
    <!-- Banner recovery: mostrato quando l'utente arriva dal magic link -->
    <Message
        v-if="isRecovery"
        severity="warn"
        :closable="false"
        class="mb-6"
    >
        <div class="flex items-start gap-2">
            <i class="pi pi-shield text-lg mt-0.5" />
            <div>
                <strong>Accesso tramite link di recupero</strong><br>
                Stai usando un accesso temporaneo. Ti consigliamo di registrare
                subito un nuovo dispositivo cliccando "Aggiungi passkey" qui sotto.
            </div>
        </div>
    </Message>

    <!-- Intestazione pagina -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-surface-900">I tuoi dispositivi</h1>
            <p class="text-surface-500 text-sm mt-1">
                Gestisci le passkey registrate sul tuo account.
            </p>
        </div>
        <Button
            v-if="! showAddForm"
            label="Aggiungi passkey"
            icon="pi pi-plus"
            @click="showAddForm = true"
        />
    </div>

    <!-- Form aggiungi nuova passkey -->
    <div
        v-if="showAddForm"
        class="bg-white rounded-xl border border-surface-200 p-6 mb-6"
    >
        <h2 class="text-lg font-semibold text-surface-900 mb-4">
            Registra un nuovo dispositivo
        </h2>
        <p class="text-sm text-surface-500 mb-4">
            Il tuo dispositivo ti chiederà di usare Face ID, Touch ID o Windows Hello
            per creare una nuova passkey.
        </p>

        <!-- Nome opzionale per il dispositivo -->
        <div class="flex flex-col gap-2 mb-4">
            <label for="key-name" class="text-sm font-medium text-surface-700">
                Nome dispositivo <span class="text-surface-400">(opzionale)</span>
            </label>
            <InputText
                id="key-name"
                v-model="newKeyName"
                placeholder="es. MacBook Pro, iPhone 15..."
                class="w-full max-w-sm"
                :disabled="loading"
            />
        </div>

        <div class="flex gap-3">
            <Button
                label="Registra passkey"
                icon="pi pi-fingerprint"
                :loading="loading"
                @click="handleAddKey"
            />
            <Button
                label="Annulla"
                severity="secondary"
                text
                :disabled="loading"
                @click="showAddForm = false; newKeyName = ''"
            />
        </div>
    </div>

    <!-- Tabella passkey registrate -->
    <div class="bg-white rounded-xl border border-surface-200">
        <DataTable
            :value="keys"
            :loading="loading && keys.length === 0"
            striped-rows
        >
            <!-- Colonna nome/dispositivo con rinomina inline -->
            <Column field="name" header="Dispositivo">
                <template #body="{ data }">
                    <div class="flex items-center gap-2">
                        <i class="pi pi-mobile text-surface-400 shrink-0" />

                        <!-- Modalità modifica -->
                        <template v-if="editingId === data.id">
                            <InputText
                                v-model="editName"
                                size="small"
                                class="w-48"
                                autofocus
                                @keyup="(e: KeyboardEvent) => { if (e.key === 'Enter') confirmRename(data.id); else if (e.key === 'Escape') cancelRename() }"
                            />
                            <button
                                type="button"
                                class="text-green-600 hover:text-green-800 p-1"
                                title="Conferma"
                                @click="confirmRename(data.id)"
                            >
                                <i class="pi pi-check text-xs" />
                            </button>
                            <button
                                type="button"
                                class="text-surface-400 hover:text-surface-600 p-1"
                                title="Annulla"
                                @click="cancelRename"
                            >
                                <i class="pi pi-times text-xs" />
                            </button>
                        </template>

                        <!-- Modalità visualizzazione -->
                        <template v-else>
                            <span class="font-medium">{{ data.name }}</span>
                            <button
                                type="button"
                                class="text-surface-300 hover:text-surface-600 p-1 transition-colors"
                                title="Rinomina"
                                @click="startRename(data)"
                            >
                                <i class="pi pi-pencil text-xs" />
                            </button>
                        </template>
                    </div>
                </template>
            </Column>

            <!-- Colonna data registrazione -->
            <Column field="registered_at" header="Registrata il" class="text-surface-500" />

            <!-- Colonna azioni -->
            <Column header="" style="width: 56px">
                <template #body="{ data }">
                    <Button
                        icon="pi pi-trash"
                        severity="danger"
                        text
                        rounded
                        size="small"
                        :disabled="keys.length <= 1 && ! emailVerified"
                        v-tooltip.top="keys.length <= 1 && ! emailVerified
                            ? 'Verifica l\'email prima di eliminare l\'unica passkey'
                            : 'Elimina questo dispositivo'"
                        @click="handleRemoveKey(data)"
                    />
                </template>
            </Column>

            <!-- Stato vuoto -->
            <template #empty>
                <div class="text-center py-8 text-surface-400">
                    <i class="pi pi-key text-3xl mb-2 block" />
                    <p>Nessuna passkey registrata.</p>
                </div>
            </template>
        </DataTable>
    </div>

    <!-- Nota informativa -->
    <p class="text-xs text-surface-400 mt-4">
        Se perdi un dispositivo, eliminalo subito da questa lista per revocare l'accesso.
        Puoi sempre recuperare l'accesso tramite email dalla pagina di login.
    </p>
</template>
