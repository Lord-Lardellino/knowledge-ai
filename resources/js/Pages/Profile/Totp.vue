<script setup lang="ts">
/**
 * Profile/Totp.vue — gestione TOTP (autenticazione a due fattori) dal profilo
 *
 * STATI DELLA PAGINA:
 *
 *   1. TOTP disattivo (totp_enabled = false)
 *      → bottone "Attiva autenticazione a due fattori"
 *      → clic → chiama fetchSetup() → mostra QR code + segreto
 *
 *   2. TOTP in setup (qrUrl != null)
 *      → l'utente scansiona il QR con l'app TOTP
 *      → inserisce il primo codice per confermare
 *      → clic "Conferma" → chiama confirmCode()
 *
 *   3. TOTP appena attivato (recoveryCodes.length > 0)
 *      → mostra i codici di recovery IN CHIARO (unica volta!)
 *      → avviso: salvarli, non verranno mostrati di nuovo
 *
 *   4. TOTP attivo (totp_enabled = true, nessun QR, nessun recovery code da mostrare)
 *      → badge "Attivo"
 *      → form per disabilitare (richiede codice corrente)
 *
 * FLUSSO SECURITY:
 *   - La disattivazione richiede il codice TOTP (prevenzione session hijack)
 *   - I recovery code vengono mostrati SOLO dopo la prima attivazione
 *   - Il segreto (secret) è cifrato nel DB via EncryptedString cast
 */
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { Head }                        from '@inertiajs/vue3'
import QRCode                          from 'qrcode'
import { useToast }                    from 'primevue/usetoast'
import Button                          from 'primevue/button'
import InputText                       from 'primevue/inputtext'
import Message                         from 'primevue/message'
import Tag                             from 'primevue/tag'
import Divider                         from 'primevue/divider'
import AppLayout                       from '@/Layouts/AppLayout.vue'
import { useTotp }                     from '@/composables/useTotp'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    auth:         { user: { name: string; email: string } }
    totp_enabled: boolean
}>()

const toast   = useToast()
const qrCanvas = ref<HTMLCanvasElement | null>(null)
const { loading, error, secret, qrUrl, recoveryCodes, fetchSetup, confirmCode, disableTotp } = useTotp()

// Quando qrUrl diventa disponibile, renderizza il QR sul canvas
watch(qrUrl, async (url) => {
    if (! url) return
    await nextTick()
    if (qrCanvas.value) {
        await QRCode.toCanvas(qrCanvas.value, url, { width: 200, margin: 2 })
    }
})

// Stato reattivo locale — la prop totp_enabled è il valore iniziale dal server
const isEnabled       = ref(props.totp_enabled)
const confirmCodeVal  = ref('')
const disableCodeVal  = ref('')

// Derivato: siamo in fase "mostra QR per conferma"?
const isInSetup   = computed(() => qrUrl.value !== null)
// Derivato: siamo nella schermata "salva i recovery codes"?
const showCodes   = computed(() => recoveryCodes.value.length > 0)

onMounted(() => {
    // Nessuna azione automatica — l'utente decide quando avviare il setup
})

async function startSetup (): Promise<void> {
    await fetchSetup()
}

async function confirm (): Promise<void> {
    const ok = await confirmCode(confirmCodeVal.value.trim())
    if (ok) {
        isEnabled.value     = true
        confirmCodeVal.value = ''
        toast.add({ severity: 'success', summary: 'TOTP attivato', detail: 'Salva i codici di recovery ora.', life: 8000 })
    }
}

async function disable (): Promise<void> {
    const ok = await disableTotp(disableCodeVal.value.trim())
    if (ok) {
        isEnabled.value    = false
        disableCodeVal.value = ''
        toast.add({ severity: 'info', summary: 'TOTP disattivato', detail: 'Il secondo fattore è stato rimosso.', life: 4000 })
    }
}

function copySecret (): void {
    if (secret.value) {
        navigator.clipboard.writeText(secret.value)
        toast.add({ severity: 'success', summary: 'Copiato', detail: 'Segreto copiato negli appunti.', life: 2000 })
    }
}

function copyCodes (): void {
    const text = recoveryCodes.value.join('\n')
    navigator.clipboard.writeText(text)
    toast.add({ severity: 'success', summary: 'Copiato', detail: 'Codici copiati negli appunti.', life: 2000 })
}
</script>

<template>
    <Head title="Autenticazione a due fattori" />

    <div class="max-w-2xl mx-auto">
        <!-- Intestazione -->
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 bg-primary-50 rounded-lg flex items-center justify-center">
                <i class="pi pi-shield text-primary text-lg" />
            </div>
            <div>
                <h1 class="text-xl font-bold text-surface-900">Autenticazione a due fattori</h1>
                <p class="text-sm text-surface-500">Aggiungi un secondo livello di sicurezza al tuo account</p>
            </div>
            <Tag
                v-if="isEnabled"
                value="Attivo"
                severity="success"
                class="ml-auto"
            />
            <Tag
                v-else
                value="Non attivo"
                severity="secondary"
                class="ml-auto"
            />
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-surface-200">

            <!-- ================================================================
                 STATO 1 — TOTP disattivo, nessun setup in corso
                 ================================================================ -->
            <div v-if="! isEnabled && ! isInSetup && ! showCodes" class="p-6">
                <p class="text-surface-600 text-sm mb-6 leading-relaxed">
                    Con l'autenticazione a due fattori, dopo il login con passkey ti verrà
                    chiesto un codice temporaneo dalla tua app di autenticazione. Anche se
                    qualcuno accede al tuo dispositivo, non potrà entrare senza l'app.
                </p>
                <p class="text-surface-500 text-sm mb-6">
                    App supportate: Google Authenticator, Authy, 1Password, Microsoft Authenticator.
                </p>
                <Button
                    label="Attiva autenticazione a due fattori"
                    icon="pi pi-plus"
                    :loading="loading"
                    @click="startSetup"
                />
            </div>

            <!-- ================================================================
                 STATO 2 — QR code da scansionare + conferma codice
                 ================================================================ -->
            <div v-else-if="isInSetup" class="p-6">
                <h2 class="font-semibold text-surface-800 mb-4">Configura la tua app</h2>

                <!-- Istruzioni -->
                <ol class="list-decimal list-inside text-sm text-surface-600 space-y-2 mb-6">
                    <li>Apri l'app di autenticazione sul tuo telefono</li>
                    <li>Scansiona il codice QR qui sotto oppure inserisci il segreto manualmente</li>
                    <li>Inserisci il codice a 6 cifre mostrato dall'app per confermare</li>
                </ol>

                <!-- QR Code — usa l'URL otosvc.app (formato standard) -->
                <div class="flex flex-col items-center gap-4 mb-6 p-4 bg-surface-50 rounded-lg border border-surface-200">
                    <div class="text-sm text-surface-500 text-center mb-2">
                        Scansiona con la tua app di autenticazione
                    </div>
                    <!-- QR generato client-side con la libreria qrcode — nessuna richiesta esterna -->
                    <canvas
                        v-if="qrUrl"
                        ref="qrCanvas"
                        class="rounded border border-surface-200"
                    />
                    <div class="text-xs text-surface-400 text-center">
                        Non riesci a scansionare? Inserisci manualmente:
                    </div>
                    <div class="flex items-center gap-2 bg-white border border-surface-200 rounded px-3 py-2 font-mono text-sm">
                        <span class="text-surface-800 select-all tracking-wider">{{ secret }}</span>
                        <button
                            type="button"
                            class="text-surface-400 hover:text-surface-600 ml-2"
                            title="Copia segreto"
                            @click="copySecret"
                        >
                            <i class="pi pi-copy text-xs" />
                        </button>
                    </div>
                </div>

                <!-- Campo conferma -->
                <div class="flex flex-col gap-2 mb-4">
                    <label class="text-sm font-medium text-surface-700">
                        Inserisci il codice dall'app per confermare
                    </label>
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

                <Message v-if="error" severity="error" :closable="false" class="mb-4">
                    {{ error }}
                </Message>

                <Button
                    label="Conferma e attiva"
                    icon="pi pi-check"
                    :loading="loading"
                    :disabled="confirmCodeVal.length < 6"
                    @click="confirm"
                />
            </div>

            <!-- ================================================================
                 STATO 3 — TOTP appena attivato: mostra recovery codes
                 ================================================================ -->
            <div v-else-if="showCodes" class="p-6">
                <div class="flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg mb-6">
                    <i class="pi pi-exclamation-triangle text-amber-500 mt-0.5" />
                    <div class="text-sm text-amber-800">
                        <strong>Salva questi codici ora.</strong> Sono l'unico modo per accedere
                        al tuo account se perdi l'accesso all'app di autenticazione.
                        Non verranno mostrati di nuovo.
                    </div>
                </div>

                <h2 class="font-semibold text-surface-800 mb-3">Codici di recovery</h2>
                <p class="text-sm text-surface-500 mb-4">
                    Ogni codice può essere usato una sola volta. Conservali in un posto sicuro
                    (gestore di password, documento stampato, ecc.).
                </p>

                <!-- Griglia codici -->
                <div class="grid grid-cols-2 gap-2 mb-4 p-4 bg-surface-50 border border-surface-200 rounded-lg font-mono text-sm">
                    <div
                        v-for="code in recoveryCodes"
                        :key="code"
                        class="text-surface-800 select-all py-1"
                    >
                        {{ code }}
                    </div>
                </div>

                <div class="flex gap-3">
                    <Button
                        label="Copia tutti"
                        icon="pi pi-copy"
                        severity="secondary"
                        @click="copyCodes"
                    />
                    <Button
                        label="Ho salvato i codici"
                        icon="pi pi-check"
                        @click="recoveryCodes.splice(0)"
                    />
                </div>
            </div>

            <!-- ================================================================
                 STATO 4 — TOTP attivo, nessun recovery code da mostrare
                 ================================================================ -->
            <div v-else-if="isEnabled" class="p-6">
                <div class="flex items-center gap-3 p-4 bg-green-50 border border-green-200 rounded-lg mb-6">
                    <i class="pi pi-check-circle text-green-500" />
                    <div class="text-sm text-green-800">
                        Il secondo fattore è attivo. Dopo ogni login con passkey ti verrà
                        chiesto il codice dall'app.
                    </div>
                </div>

                <Divider />

                <h3 class="font-semibold text-surface-800 mb-2">Disabilita il secondo fattore</h3>
                <p class="text-sm text-surface-500 mb-4">
                    Per disabilitare il TOTP inserisci il codice attuale dalla tua app di
                    autenticazione. Questa operazione rende il tuo account meno sicuro.
                </p>

                <div class="flex flex-col gap-2 mb-4">
                    <label class="text-sm font-medium text-surface-700">Codice a 6 cifre</label>
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

                <Message v-if="error" severity="error" :closable="false" class="mb-4">
                    {{ error }}
                </Message>

                <Button
                    label="Disabilita"
                    icon="pi pi-times"
                    severity="danger"
                    outlined
                    :loading="loading"
                    :disabled="disableCodeVal.length < 6"
                    @click="disable"
                />
            </div>

        </div>
    </div>
</template>
