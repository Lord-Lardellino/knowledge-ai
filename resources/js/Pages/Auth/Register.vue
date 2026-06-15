<script setup lang="ts">
import { ref }                from 'vue'
import { Head }               from '@inertiajs/vue3'
import { usePasskeyRegister } from '@/composables/usePasskeyRegister'
import AuthSidePanel          from '@/Components/AuthSidePanel.vue'

const name    = ref('')
const email   = ref('')
const company = ref('')

const inviteToken = new URLSearchParams(window.location.search).get('invite') ?? ''

const { register, loading, error } = usePasskeyRegister()

function handleRegister() {
    // Dopo la registrazione l'admin sceglie il piano (pricing), poi entra.
    register(name.value, email.value, '/billing', {
        company:     company.value || undefined,
        inviteToken: inviteToken || undefined,
    })
}
</script>

<template>
    <Head title="Registrati" />

    <div class="min-h-screen flex">

        <AuthSidePanel
            quote="Crea il tuo workspace aziendale in meno di un minuto."
            :steps="[
                { icon: 'pi pi-user',         text: 'Inserisci nome, email e azienda' },
                { icon: 'pi pi-fingerprint',  text: 'Registra la tua passkey (Face ID / Touch ID)' },
                { icon: 'pi pi-check-circle', text: 'Accedi subito — nessuna password' },
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

                <h1 class="text-2xl font-semibold mb-1">Crea il tuo account</h1>
                <p class="text-sm text-surface-500 mb-8">
                    {{ inviteToken ? 'Stai accettando un invito al workspace.' : 'Inizia la tua prova gratuita oggi.' }}
                </p>

                <div class="flex flex-col gap-1.5 mb-4">
                    <label for="name" class="text-sm font-medium">Nome completo</label>
                    <InputText id="name" v-model="name" type="text" placeholder="Mario Rossi"
                        autocomplete="name" class="w-full" :disabled="loading" @keyup.enter="handleRegister" />
                </div>

                <div v-if="!inviteToken" class="flex flex-col gap-1.5 mb-4">
                    <label for="company" class="text-sm font-medium">Nome azienda</label>
                    <InputText id="company" v-model="company" type="text" placeholder="Acme Srl"
                        autocomplete="organization" class="w-full" :disabled="loading" @keyup.enter="handleRegister" />
                    <small class="text-surface-400 text-xs">Crei lo spazio di lavoro: sarai l'amministratore.</small>
                </div>

                <div class="flex flex-col gap-1.5 mb-6">
                    <label for="email" class="text-sm font-medium">Email</label>
                    <InputText id="email" v-model="email" type="email" placeholder="nome@azienda.com"
                        autocomplete="email" class="w-full" :disabled="loading" @keyup.enter="handleRegister" />
                </div>

                <Message v-if="error" severity="error" :closable="false" class="mb-4">{{ error }}</Message>

                <Button label="Registrati con passkey" icon="pi pi-fingerprint" class="w-full mb-3"
                    :loading="loading" @click="handleRegister" />

                <p class="text-xs text-surface-400 text-center mb-8">
                    Face ID, Touch ID o Windows Hello — nessuna password da impostare.
                </p>

                <Divider />

                <p class="text-sm text-center mt-4 text-surface-500">
                    Hai già un account?
                    <Button as="a" href="/login" label="Accedi" link class="p-0 ml-1" />
                </p>

            </div>
        </div>

    </div>
</template>
