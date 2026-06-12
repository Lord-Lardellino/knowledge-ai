<script setup lang="ts">
/**
 * TotpChallenge.vue — inserimento codice TOTP dopo il login con passkey
 *
 * QUANDO VIENE MOSTRATA:
 *   Dopo che la passkey è stata verificata con successo, se l'utente ha il TOTP
 *   attivo e il suo ruolo è in 'totp_required_roles', usePasskey naviga qui
 *   invece che al dashboard.
 *
 * FLUSSO:
 *   1. Utente inserisce il codice 6 cifre dall'app TOTP (Google Authenticator, ecc.)
 *   2. POST /auth/totp/challenge { "code": "123456" }
 *   3. Se corretto: sessione totp_verified = true → redirect al dashboard
 *   4. Se sbagliato: messaggio di errore, riprova
 *   5. "Usa codice di recovery" → campo alternativo per i codici XXXXX-XXXXX
 */
import { ref }         from 'vue'
import { Head }        from '@inertiajs/vue3'
import InputText       from 'primevue/inputtext'
import Button          from 'primevue/button'
import Message         from 'primevue/message'
import { useTotp }     from '@/composables/useTotp'

const { verifyCode, loading, error } = useTotp()

const code          = ref('')
const useRecovery   = ref(false)

async function submit (): Promise<void> {
    if (! code.value.trim()) return
    await verifyCode(code.value.trim())
}
</script>

<template>
    <Head title="Verifica in due passaggi" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50">
        <div class="w-full max-w-sm">

            <!-- Intestazione -->
            <div class="text-center mb-8">
                <div class="w-14 h-14 bg-primary-50 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="pi pi-shield text-primary text-2xl" />
                </div>
                <h1 class="text-2xl font-bold text-surface-900">Verifica in due passaggi</h1>
                <p class="text-surface-500 mt-2 text-sm">
                    {{ useRecovery
                        ? 'Inserisci uno dei codici di recovery che hai salvato al momento dell\'attivazione.'
                        : 'Apri la tua app di autenticazione (Google Authenticator, Authy, 1Password) e inserisci il codice.' }}
                </p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-surface-200 p-8">

                <!-- Campo codice -->
                <div class="flex flex-col gap-2 mb-6">
                    <label class="text-sm font-medium text-surface-700">
                        {{ useRecovery ? 'Codice di recovery' : 'Codice a 6 cifre' }}
                    </label>
                    <InputText
                        v-model="code"
                        :placeholder="useRecovery ? 'XXXXX-XXXXX' : '000000'"
                        :maxlength="useRecovery ? 11 : 6"
                        :inputmode="useRecovery ? 'text' : 'numeric'"
                        autocomplete="one-time-code"
                        class="w-full text-center text-xl tracking-widest"
                        :disabled="loading"
                        @keyup.enter="submit"
                    />
                </div>

                <!-- Errore -->
                <Message v-if="error" severity="error" :closable="false" class="mb-4">
                    {{ error }}
                </Message>

                <!-- Pulsante verifica -->
                <Button
                    label="Verifica"
                    icon="pi pi-check"
                    class="w-full mb-4"
                    :loading="loading"
                    :disabled="! code.trim()"
                    @click="submit"
                />

                <!-- Toggle recovery code -->
                <button
                    type="button"
                    class="w-full text-sm text-surface-400 hover:text-surface-600 transition-colors"
                    @click="useRecovery = ! useRecovery; code = ''"
                >
                    {{ useRecovery
                        ? '← Torna al codice TOTP'
                        : 'Non hai accesso all\'app? Usa un codice di recovery' }}
                </button>

            </div>

        </div>
    </div>
</template>
