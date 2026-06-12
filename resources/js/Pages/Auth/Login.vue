<script setup lang="ts">
/**
 * Login.vue — pagina di login con passkey
 *
 * BACKEND (Laravel):
 *   Route::get('/login', fn () => Inertia::render('Auth/Login'));
 *
 * FLUSSO:
 *   1. Utente inserisce l'email
 *   2. Clicca "Accedi con passkey"
 *   3. Il browser mostra il prompt biometrico (Face ID / Touch ID / Windows Hello)
 *   4. Se la firma è valida, il backend crea la sessione e Inertia naviga al dashboard
 *
 * QUESTA PAGINA NON HA LAYOUT (è la pagina di login — nessun menu).
 * Le pagine del dashboard useranno: defineOptions({ layout: AppLayout })
 */
import { ref }         from 'vue'
import { Head }        from '@inertiajs/vue3'
import InputText       from 'primevue/inputtext'
import Button          from 'primevue/button'
import Message         from 'primevue/message'
import { usePasskey }  from '@/composables/usePasskey'

const email          = ref('')
const { login, loading, error } = usePasskey()
</script>

<template>
    <Head title="Accedi" />

    <div class="min-h-screen flex items-center justify-center bg-surface-50">
        <div class="w-full max-w-sm">

            <!-- Logo / nome app -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-surface-900">SaaS</h1>
                <p class="text-surface-500 mt-1">Accedi al tuo account</p>
            </div>

            <!-- Card login -->
            <div class="bg-white rounded-xl shadow-sm border border-surface-200 p-8">

                <!-- Campo email -->
                <div class="flex flex-col gap-2 mb-6">
                    <label for="email" class="text-sm font-medium text-surface-700">
                        Email
                    </label>
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

                <!-- Messaggio di errore -->
                <Message
                    v-if="error"
                    severity="error"
                    :closable="false"
                    class="mb-4"
                >
                    {{ error }}
                </Message>

                <!-- Pulsante passkey -->
                <Button
                    label="Accedi con passkey"
                    icon="pi pi-fingerprint"
                    class="w-full"
                    :loading="loading"
                    @click="login(email)"
                />

                <!-- Info passkey -->
                <p class="text-xs text-surface-400 text-center mt-4">
                    Useremo Face ID, Touch ID o Windows Hello —
                    nessuna password da ricordare.
                </p>

                <!-- Link registrazione e recupero accesso -->
                <div class="flex justify-between mt-6 pt-6 border-t border-surface-100 text-sm">
                    <a
                        href="/register"
                        class="text-primary-600 hover:text-primary-700 font-medium"
                    >
                        Crea account
                    </a>
                    <a
                        href="/recover"
                        class="text-surface-400 hover:text-surface-600"
                    >
                        Dispositivo smarrito?
                    </a>
                </div>

            </div>

        </div>
    </div>
</template>
