<script setup lang="ts">
import { ref }         from 'vue'
import { Head }        from '@inertiajs/vue3'
import { useZodForm }  from '@/composables/useZodForm'
import { recoverSchema } from '@/validation/schemas'
import AuthSidePanel   from '@/Components/AuthSidePanel.vue'

const email   = ref('')
const loading = ref(false)
const error   = ref<string | null>(null)
const sent    = ref(false)

const { errors, validate, touch } = useZodForm(recoverSchema, () => ({ email: email.value }))

async function sendRecoveryLink(): Promise<void> {
    if (! validate({ email: email.value })) return

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

    <div class="min-h-screen flex">

        <AuthSidePanel
            quote="Recupera l'accesso al tuo account in pochi secondi."
            :steps="[
                { icon: 'pi pi-envelope',    text: 'Inserisci la tua email aziendale' },
                { icon: 'pi pi-send',        text: 'Ricevi un link sicuro via email' },
                { icon: 'pi pi-fingerprint', text: 'Registra il nuovo dispositivo' },
            ]"
        />

        <!-- Right — form -->
        <div class="flex-1 flex items-center justify-center p-6 bg-surface-50 dark:bg-surface-950">
            <div class="w-full max-w-sm">

                <div class="flex items-center gap-2 mb-10 lg:hidden">
                    <div class="w-7 h-7 rounded-lg bg-primary-500 flex items-center justify-center">
                        <i class="pi pi-bolt text-white text-xs" />
                    </div>
                    <span class="font-bold text-base tracking-tight">Sapio</span>
                </div>

                <!-- Stato: inviato -->
                <template v-if="sent">
                    <div class="text-center">
                        <div class="w-16 h-16 rounded-full bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center mx-auto mb-6">
                            <i class="pi pi-envelope-open text-2xl text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <h1 class="text-2xl font-semibold mb-2">Controlla la tua email</h1>
                        <p class="text-sm text-surface-500 mb-2">
                            Se <strong>{{ email }}</strong> è registrata, riceverai un link entro pochi secondi.
                        </p>
                        <p class="text-xs text-surface-400 mb-8">Il link scade tra 15 minuti. Controlla anche la cartella spam.</p>
                        <Divider />
                        <Button as="a" href="/login" label="← Torna al login" link class="p-0 text-sm mt-4" />
                    </div>
                </template>

                <!-- Stato: form -->
                <template v-else>
                    <h1 class="text-2xl font-semibold mb-1">Recupera accesso</h1>
                    <p class="text-sm text-surface-500 mb-8">
                        Inserisci la tua email. Ti invieremo un link per accedere e registrare un nuovo dispositivo.
                    </p>

                    <div class="flex flex-col gap-1.5 mb-6">
                        <label for="email" class="text-sm font-medium">Email</label>
                        <InputText id="email" v-model="email" type="email" placeholder="nome@azienda.com"
                            autocomplete="email" class="w-full" :invalid="!!errors.email" :disabled="loading"
                            @blur="touch('email')" @keyup.enter="sendRecoveryLink" />
                        <Message v-if="errors.email" severity="error" size="small" variant="simple">{{ errors.email }}</Message>
                    </div>

                    <Message v-if="error" severity="error" :closable="false" class="mb-4">{{ error }}</Message>

                    <Button label="Invia link di accesso" icon="pi pi-send" class="w-full mb-8"
                        :loading="loading" @click="sendRecoveryLink" />

                    <Divider />

                    <div class="text-center mt-4">
                        <Button as="a" href="/login" label="← Torna al login" link class="p-0 text-sm" />
                    </div>
                </template>

            </div>
        </div>

    </div>
</template>
