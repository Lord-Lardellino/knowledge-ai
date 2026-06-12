<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import { Head }                           from '@inertiajs/vue3'
import QRCode                             from 'qrcode'
import { useToast }                       from 'primevue/usetoast'
import AppLayout                          from '@/Layouts/AppLayout.vue'
import { useTotp }                        from '@/composables/useTotp'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    auth:         { user: { name: string; email: string } }
    totp_enabled: boolean
}>()

const toast    = useToast()
const qrCanvas = ref<HTMLCanvasElement | null>(null)
const { loading, error, secret, qrUrl, recoveryCodes, fetchSetup, confirmCode, disableTotp } = useTotp()

watch(qrUrl, async (url) => {
    if (!url) return
    await nextTick()
    if (qrCanvas.value) await QRCode.toCanvas(qrCanvas.value, url, { width: 200, margin: 2 })
})

const isEnabled      = ref(props.totp_enabled)
const confirmCodeVal = ref('')
const disableCodeVal = ref('')

const isInSetup = computed(() => qrUrl.value !== null)
const showCodes = computed(() => recoveryCodes.value.length > 0)

async function startSetup(): Promise<void> {
    await fetchSetup()
}

async function confirm(): Promise<void> {
    const ok = await confirmCode(confirmCodeVal.value.trim())
    if (ok) {
        isEnabled.value      = true
        confirmCodeVal.value = ''
        toast.add({ severity: 'success', summary: 'TOTP attivato', detail: 'Salva i codici di recovery ora.', life: 8000 })
    }
}

async function disable(): Promise<void> {
    const ok = await disableTotp(disableCodeVal.value.trim())
    if (ok) {
        isEnabled.value      = false
        disableCodeVal.value = ''
        toast.add({ severity: 'info', summary: 'TOTP disattivato', detail: 'Il secondo fattore è stato rimosso.', life: 4000 })
    }
}

function copySecret(): void {
    if (secret.value) {
        navigator.clipboard.writeText(secret.value)
        toast.add({ severity: 'success', summary: 'Copiato', detail: 'Segreto copiato negli appunti.', life: 2000 })
    }
}

function copyCodes(): void {
    navigator.clipboard.writeText(recoveryCodes.value.join('\n'))
    toast.add({ severity: 'success', summary: 'Copiato', detail: 'Codici copiati negli appunti.', life: 2000 })
}
</script>

<template>
    <Head title="Autenticazione a due fattori" />

    <div class="max-w-xl mx-auto space-y-4">

        <!-- Header -->
        <div class="flex items-center gap-3">
            <div class="grid h-10 w-10 place-items-center rounded-lg bg-primary-50 dark:bg-primary-400/10">
                <i class="pi pi-shield text-primary-600 dark:text-primary-400 text-lg" />
            </div>
            <div class="flex-1">
                <h1 class="text-xl font-semibold text-surface-900 dark:text-surface-0">Autenticazione a due fattori</h1>
                <p class="text-sm text-surface-500">Aggiungi un secondo livello di sicurezza al tuo account</p>
            </div>
            <Tag v-if="isEnabled"  value="Attivo"     severity="success"   />
            <Tag v-else            value="Non attivo"  severity="secondary" />
        </div>

        <!-- STATO 1 — disattivo -->
        <Card v-if="!isEnabled && !isInSetup && !showCodes">
            <template #content>
                <p class="text-sm text-surface-600 dark:text-surface-300 leading-relaxed mb-4">
                    Con l'autenticazione a due fattori, dopo il login con passkey ti verrà chiesto
                    un codice temporaneo dalla tua app. Anche se qualcuno accede al tuo dispositivo,
                    non potrà entrare senza l'app.
                </p>
                <p class="text-sm text-surface-500 mb-6">
                    App supportate: Google Authenticator, Authy, 1Password, Microsoft Authenticator.
                </p>
                <Button label="Attiva autenticazione a due fattori" icon="pi pi-plus" :loading="loading" @click="startSetup" />
            </template>
        </Card>

        <!-- STATO 2 — setup QR -->
        <Card v-else-if="isInSetup">
            <template #title>Configura la tua app</template>
            <template #content>
                <ol class="list-decimal list-inside text-sm text-surface-600 dark:text-surface-300 space-y-1 mb-6">
                    <li>Apri l'app di autenticazione sul tuo telefono</li>
                    <li>Scansiona il QR oppure inserisci il segreto manualmente</li>
                    <li>Inserisci il codice a 6 cifre per confermare</li>
                </ol>

                <div class="flex flex-col items-center gap-3 mb-6 p-4 bg-surface-50 dark:bg-surface-800 rounded-lg border border-surface-200 dark:border-surface-700">
                    <p class="text-sm text-surface-500">Scansiona con la tua app di autenticazione</p>
                    <canvas v-if="qrUrl" ref="qrCanvas" class="rounded border border-surface-200 dark:border-surface-700" />
                    <p class="text-xs text-surface-400">Non riesci a scansionare? Inserisci manualmente:</p>
                    <div class="flex items-center gap-2 bg-surface-0 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded px-3 py-2 font-mono text-sm">
                        <span class="select-all tracking-wider text-surface-800 dark:text-surface-100">{{ secret }}</span>
                        <button type="button" class="text-surface-400 hover:text-surface-600 ml-2" @click="copySecret">
                            <i class="pi pi-copy text-xs" />
                        </button>
                    </div>
                </div>

                <div class="flex flex-col gap-2 mb-4">
                    <label class="text-sm font-medium">Inserisci il codice dall'app per confermare</label>
                    <InputText
                        v-model="confirmCodeVal"
                        placeholder="000000"
                        maxlength="6"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        class="text-center text-xl tracking-widest"
                        :disabled="loading"
                        @keyup.enter="confirm"
                    />
                </div>

                <Message v-if="error" severity="error" :closable="false" class="mb-4">{{ error }}</Message>

                <Button label="Conferma e attiva" icon="pi pi-check" :loading="loading" :disabled="confirmCodeVal.length < 6" @click="confirm" />
            </template>
        </Card>

        <!-- STATO 3 — recovery codes -->
        <Card v-else-if="showCodes">
            <template #content>
                <Message severity="warn" :closable="false" class="mb-4">
                    <strong>Salva questi codici ora.</strong> Non verranno mostrati di nuovo.
                    Sono l'unico modo per accedere se perdi l'app di autenticazione.
                </Message>

                <h2 class="font-semibold mb-2">Codici di recovery</h2>
                <p class="text-sm text-surface-500 mb-4">Ogni codice può essere usato una sola volta.</p>

                <div class="grid grid-cols-2 gap-2 mb-4 p-4 bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg font-mono text-sm">
                    <div v-for="code in recoveryCodes" :key="code" class="select-all py-1">{{ code }}</div>
                </div>

                <div class="flex gap-3">
                    <Button label="Copia tutti" icon="pi pi-copy" severity="secondary" @click="copyCodes" />
                    <Button label="Ho salvato i codici" icon="pi pi-check" @click="recoveryCodes.splice(0)" />
                </div>
            </template>
        </Card>

        <!-- STATO 4 — attivo -->
        <Card v-else-if="isEnabled">
            <template #content>
                <Message severity="success" :closable="false" class="mb-6">
                    Il secondo fattore è attivo. Dopo ogni login ti verrà chiesto il codice dall'app.
                </Message>

                <Divider />

                <h3 class="font-semibold mb-2">Disabilita il secondo fattore</h3>
                <p class="text-sm text-surface-500 mb-4">
                    Inserisci il codice attuale dalla tua app per disabilitare il TOTP.
                </p>

                <div class="flex flex-col gap-2 mb-4">
                    <label class="text-sm font-medium">Codice a 6 cifre</label>
                    <InputText
                        v-model="disableCodeVal"
                        placeholder="000000"
                        maxlength="6"
                        inputmode="numeric"
                        class="text-center text-xl tracking-widest max-w-xs"
                        :disabled="loading"
                        @keyup.enter="disable"
                    />
                </div>

                <Message v-if="error" severity="error" :closable="false" class="mb-4">{{ error }}</Message>

                <Button label="Disabilita" icon="pi pi-times" severity="danger" outlined :loading="loading" :disabled="disableCodeVal.length < 6" @click="disable" />
            </template>
        </Card>

    </div>
</template>
