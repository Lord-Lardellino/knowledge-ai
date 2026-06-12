<script setup lang="ts">
import { ref }  from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLogo  from '@/Components/AppLogo.vue'

const email   = ref('')
const loading = ref(false)
const error   = ref<string | null>(null)
const sent    = ref(false)

async function sendRecoveryLink(): Promise<void> {
    if (!email.value) { error.value = 'Inserisci la tua email.'; return }

    loading.value = true
    error.value   = null

    try {
        const res = await fetch('/recover', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                'Accept':       'application/json',
            },
            body: JSON.stringify({ email: email.value }),
        })

        if (res.status === 429) {
            error.value = 'Troppi tentativi. Aspetta qualche minuto e riprova.'
            return
        }

        sent.value = true
    } catch {
        error.value = 'Errore di connessione. Riprova tra qualche secondo.'
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <Head title="Recupero accesso" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-900">
        <div class="w-full max-w-sm px-4">

            <div class="flex justify-center mb-8">
                <AppLogo />
            </div>

            <Card class="shadow-lg">
                <template #content>

                    <!-- Stato: link inviato -->
                    <template v-if="sent">
                        <div class="text-center">
                            <div class="grid h-14 w-14 place-items-center rounded-full bg-green-50 dark:bg-green-500/10 mx-auto mb-4">
                                <i class="pi pi-envelope-open text-2xl text-green-600 dark:text-green-400" />
                            </div>
                            <h2 class="text-lg font-semibold mb-2">Controlla la tua email</h2>
                            <p class="text-sm text-surface-500 mb-4">
                                Se <strong>{{ email }}</strong> è registrata, riceverai un link entro pochi secondi. Scade tra 15 minuti.
                            </p>
                            <p class="text-xs text-surface-400">Non trovi l'email? Controlla la cartella spam.</p>
                        </div>
                    </template>

                    <!-- Stato: form -->
                    <template v-else>
                        <p class="text-sm text-surface-500 mb-6">
                            Inserisci l'email del tuo account. Ti invieremo un link per accedere e registrare un nuovo dispositivo.
                        </p>

                        <div class="flex flex-col gap-2 mb-6">
                            <label for="email" class="text-sm font-medium">Email</label>
                            <InputText
                                id="email"
                                v-model="email"
                                type="email"
                                placeholder="nome@azienda.com"
                                autocomplete="email"
                                class="w-full"
                                :disabled="loading"
                                @keyup.enter="sendRecoveryLink"
                            />
                        </div>

                        <Message v-if="error" severity="error" :closable="false" class="mb-4">
                            {{ error }}
                        </Message>

                        <Button
                            label="Invia link di accesso"
                            icon="pi pi-send"
                            class="w-full"
                            :loading="loading"
                            @click="sendRecoveryLink"
                        />
                    </template>

                    <div class="text-center mt-6 pt-6 border-t border-surface-200 dark:border-surface-700">
                        <Button as="a" href="/login" label="← Torna al login" link class="p-0 text-sm" />
                    </div>

                </template>
            </Card>

        </div>
    </div>
</template>
