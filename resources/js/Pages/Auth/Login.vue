<script setup lang="ts">
import { ref }        from 'vue'
import { Head }       from '@inertiajs/vue3'
import AppLogo        from '@/Components/AppLogo.vue'
import { usePasskey } from '@/composables/usePasskey'

const email = ref('')
const { login, loading, error } = usePasskey()
</script>

<template>
    <Head title="Accedi" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50 dark:bg-surface-900">
        <div class="w-full max-w-sm px-4">

            <div class="flex justify-center mb-8">
                <AppLogo />
            </div>

            <Card class="shadow-lg">
                <template #content>
                    <div class="flex flex-col gap-2 mb-6">
                        <label for="email" class="text-sm font-medium">Email</label>
                        <InputText
                            id="email"
                            v-model="email"
                            type="email"
                            placeholder="nome@azienda.com"
                            autocomplete="email webauthn"
                            class="w-full"
                            :disabled="loading"
                            @keyup.enter="login(email)"
                        />
                    </div>

                    <Message v-if="error" severity="error" :closable="false" class="mb-4">
                        {{ error }}
                    </Message>

                    <Button
                        label="Accedi con passkey"
                        icon="pi pi-fingerprint"
                        class="w-full"
                        :loading="loading"
                        @click="login(email)"
                    />

                    <p class="text-xs text-surface-400 text-center mt-4">
                        Useremo Face ID, Touch ID o Windows Hello — nessuna password.
                    </p>

                    <div class="flex justify-between mt-6 pt-6 border-t border-surface-200 dark:border-surface-700 text-sm">
                        <Button as="a" href="/register" label="Crea account" link class="p-0" />
                        <Button as="a" href="/recover" label="Dispositivo smarrito?" link severity="secondary" class="p-0" />
                    </div>
                </template>
            </Card>

        </div>
    </div>
</template>
