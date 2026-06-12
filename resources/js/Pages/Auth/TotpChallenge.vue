<script setup lang="ts">
import { ref }     from 'vue'
import { Head }    from '@inertiajs/vue3'
import AppLogo     from '@/Components/AppLogo.vue'
import { useTotp } from '@/composables/useTotp'

const { verifyCode, loading, error } = useTotp()

const code        = ref('')
const useRecovery = ref(false)

async function submit(): Promise<void> {
    if (!code.value.trim()) return
    await verifyCode(code.value.trim())
}
</script>

<template>
    <Head title="Verifica in due passaggi" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-900">
        <div class="w-full max-w-sm px-4">

            <div class="flex flex-col items-center mb-8 gap-3">
                <AppLogo />
                <div class="grid h-12 w-12 place-items-center rounded-full bg-primary-50 dark:bg-primary-400/10">
                    <i class="pi pi-shield text-primary-600 dark:text-primary-400 text-xl" />
                </div>
                <div class="text-center">
                    <h1 class="text-xl font-semibold">Verifica in due passaggi</h1>
                    <p class="text-sm text-surface-500 mt-1">
                        {{ useRecovery
                            ? 'Inserisci uno dei codici di recovery salvati.'
                            : 'Apri la tua app (Google Authenticator, Authy, 1Password) e inserisci il codice.' }}
                    </p>
                </div>
            </div>

            <Card class="shadow-lg">
                <template #content>
                    <div class="flex flex-col gap-2 mb-6">
                        <label class="text-sm font-medium">
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

                    <Message v-if="error" severity="error" :closable="false" class="mb-4">
                        {{ error }}
                    </Message>

                    <Button
                        label="Verifica"
                        icon="pi pi-check"
                        class="w-full mb-4"
                        :loading="loading"
                        :disabled="!code.trim()"
                        @click="submit"
                    />

                    <Button
                        :label="useRecovery ? '← Torna al codice TOTP' : 'Non hai accesso all\'app? Usa un codice di recovery'"
                        link
                        class="w-full text-sm"
                        @click="useRecovery = !useRecovery; code = ''"
                    />
                </template>
            </Card>

        </div>
    </div>
</template>
