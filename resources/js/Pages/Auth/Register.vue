<script setup lang="ts">
import { ref }                from 'vue'
import { Head }               from '@inertiajs/vue3'
import AppLogo                from '@/Components/AppLogo.vue'
import { usePasskeyRegister } from '@/composables/usePasskeyRegister'

const name    = ref('')
const email   = ref('')
const company = ref('')

const inviteToken = new URLSearchParams(window.location.search).get('invite') ?? ''

const { register, loading, error } = usePasskeyRegister()

function handleRegister() {
    register(name.value, email.value, '/dashboard', {
        company:     company.value || undefined,
        inviteToken: inviteToken || undefined,
    })
}
</script>

<template>
    <Head title="Registrati" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-900">
        <div class="w-full max-w-sm px-4">

            <div class="flex justify-center mb-8">
                <AppLogo />
            </div>

            <Card class="shadow-lg">
                <template #content>
                    <div class="flex flex-col gap-2 mb-4">
                        <label for="name" class="text-sm font-medium">Nome completo</label>
                        <InputText
                            id="name"
                            v-model="name"
                            type="text"
                            placeholder="Mario Rossi"
                            autocomplete="name"
                            class="w-full"
                            :disabled="loading"
                            @keyup.enter="handleRegister"
                        />
                    </div>

                    <div v-if="!inviteToken" class="flex flex-col gap-2 mb-4">
                        <label for="company" class="text-sm font-medium">Nome azienda</label>
                        <InputText
                            id="company"
                            v-model="company"
                            type="text"
                            placeholder="La tua azienda"
                            autocomplete="organization"
                            class="w-full"
                            :disabled="loading"
                            @keyup.enter="handleRegister"
                        />
                        <small class="text-surface-400 text-xs">Crea lo spazio di lavoro: sarai l'amministratore.</small>
                    </div>

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
                            @keyup.enter="handleRegister"
                        />
                    </div>

                    <Message v-if="error" severity="error" :closable="false" class="mb-4">
                        {{ error }}
                    </Message>

                    <Button
                        label="Registrati con passkey"
                        icon="pi pi-fingerprint"
                        class="w-full"
                        :loading="loading"
                        @click="handleRegister"
                    />

                    <p class="text-xs text-surface-400 text-center mt-4">
                        Face ID, Touch ID o Windows Hello — nessuna password da impostare.
                    </p>

                    <div class="text-center mt-6 pt-6 border-t border-surface-200 dark:border-surface-700 text-sm">
                        <span class="text-surface-500">Hai già un account? </span>
                        <Button as="a" href="/login" label="Accedi" link class="p-0" />
                    </div>
                </template>
            </Card>

        </div>
    </div>
</template>
