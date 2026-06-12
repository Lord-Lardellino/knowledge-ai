<script setup lang="ts">
/**
 * Register.vue — pagina di registrazione con passkey
 *
 * BACKEND (Laravel):
 *   Route::get('/register', fn () => Inertia::render('Auth/Register'));
 *
 * FLUSSO:
 *   1. Utente inserisce nome + email
 *   2. Clicca "Registrati con passkey"
 *   3. POST /auth/passkey/register/options  → il server crea l'utente e ritorna challenge
 *   4. Il browser mostra il prompt biometrico (Face ID / Touch ID / Windows Hello)
 *      — in questo step si CREA la passkey, non si usa una esistente
 *   5. POST /auth/passkey/register  → server verifica, salva chiave pubblica, autentica
 *   6. Inertia naviga al dashboard
 *
 * DIFFERENZA CON LOGIN:
 *   Login usa navigator.credentials.get() — firma con chiave ESISTENTE
 *   Registrazione usa navigator.credentials.create() — crea NUOVA coppia di chiavi
 *
 * QUESTA PAGINA NON HA LAYOUT (è la pagina pubblica di registrazione).
 */
import { ref }                   from 'vue'
import { Head }                  from '@inertiajs/vue3'
import InputText                 from 'primevue/inputtext'
import Button                    from 'primevue/button'
import Message                   from 'primevue/message'
import { usePasskeyRegister }    from '@/composables/usePasskeyRegister'

// Campi del form
const name    = ref('')
const email   = ref('')
const company = ref('')

// Invito: se l'URL è /register?invite=TOKEN l'utente entra in un tenant
// esistente — il campo azienda non serve e viene nascosto.
const inviteToken = new URLSearchParams(window.location.search).get('invite') ?? ''

// Composable: gestisce tutto il flusso WebAuthn di registrazione
const { register, loading, error } = usePasskeyRegister()

/** Avvia la registrazione — chiamato da pulsante e da tasto Enter */
function handleRegister () {
    register(name.value, email.value, '/dashboard', {
        company:     company.value || undefined,
        inviteToken: inviteToken || undefined,
    })
}
</script>

<template>
    <Head title="Registrati" />

    <!--
        Layout identico al Login per coerenza visiva.
        bg-surface-50 → sfondo neutro Tailwind/PrimeVue
        max-w-sm      → card stretta come il login
    -->
    <div class="min-h-screen flex items-center justify-center bg-surface-50">
        <div class="w-full max-w-sm">

            <!-- Logo / titolo -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-surface-900">SaaS</h1>
                <p class="text-surface-500 mt-1">Crea il tuo account</p>
            </div>

            <!-- Card registrazione -->
            <div class="bg-white rounded-xl shadow-sm border border-surface-200 p-8">

                <!-- Campo nome -->
                <div class="flex flex-col gap-2 mb-4">
                    <label for="name" class="text-sm font-medium text-surface-700">
                        Nome completo
                    </label>
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

                <!-- Campo azienda (self-signup) — nascosto se si arriva da un invito -->
                <div v-if="!inviteToken" class="flex flex-col gap-2 mb-4">
                    <label for="company" class="text-sm font-medium text-surface-700">
                        Nome azienda
                    </label>
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
                    <small class="text-surface-400">
                        Crea lo spazio di lavoro della tua azienda: sarai l'amministratore.
                    </small>
                </div>

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
                        autocomplete="email"
                        class="w-full"
                        :disabled="loading"
                        @keyup.enter="handleRegister"
                    />
                </div>

                <!-- Messaggio di errore dal composable -->
                <Message
                    v-if="error"
                    severity="error"
                    :closable="false"
                    class="mb-4"
                >
                    {{ error }}
                </Message>

                <!-- Pulsante principale: avvia navigator.credentials.create() -->
                <Button
                    label="Registrati con passkey"
                    icon="pi pi-fingerprint"
                    class="w-full"
                    :loading="loading"
                    @click="handleRegister"
                />

                <!-- Spiegazione per utenti non esperti -->
                <p class="text-xs text-surface-400 text-center mt-4">
                    Il tuo dispositivo ti chiederà di usare Face ID, Touch ID
                    o Windows Hello. Nessuna password da impostare o ricordare.
                </p>

                <!-- Link alla pagina di login -->
                <div class="text-center mt-6 pt-6 border-t border-surface-100">
                    <span class="text-sm text-surface-500">
                        Hai già un account?
                        <a
                            href="/login"
                            class="text-primary-600 hover:text-primary-700 font-medium ml-1"
                        >
                            Accedi
                        </a>
                    </span>
                </div>

            </div>

        </div>
    </div>
</template>
