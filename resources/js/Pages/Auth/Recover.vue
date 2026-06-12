<script setup lang="ts">
/**
 * Recover.vue — pagina di recupero accesso (device smarrito)
 *
 * BACKEND (Laravel):
 *   Route::get('/recover', fn () => Inertia::render('Auth/Recover'));
 *
 * FLUSSO:
 *   1. L'utente inserisce la sua email
 *   2. POST /recover → il server invia un magic link valido 15 minuti
 *   3. L'utente clicca il link nell'email → viene autenticato automaticamente
 *   4. Viene redirectato a /profile/passkeys?recovery=1 per aggiungere un nuovo device
 *
 * SICUREZZA UX:
 *   Il server risponde sempre con lo stesso messaggio di successo,
 *   anche se l'email non è registrata — così non si può capire quali
 *   email sono presenti nel sistema (enumerazione account).
 *
 * QUESTA PAGINA NON HA LAYOUT (pagina pubblica, nessun menu).
 */
import { ref }        from 'vue'
import { Head }       from '@inertiajs/vue3'
import InputText      from 'primevue/inputtext'
import Button         from 'primevue/button'
import Message        from 'primevue/message'

const email   = ref('')
const loading = ref(false)
const error   = ref<string | null>(null)
// sent=true nasconde il form e mostra il messaggio di conferma
const sent    = ref(false)

async function sendRecoveryLink (): Promise<void> {
    if (! email.value) {
        error.value = 'Inserisci la tua email.'
        return
    }

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
            // Rate limit superato: 3 tentativi ogni 10 minuti
            error.value = 'Troppi tentativi. Aspetta qualche minuto e riprova.'
            return
        }

        // Il server risponde sempre 200 anche se l'email non esiste
        // (per non rivelare quali email sono registrate)
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

    <div class="min-h-screen flex items-center justify-center bg-surface-50">
        <div class="w-full max-w-sm">

            <!-- Titolo -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-surface-900">SaaS</h1>
                <p class="text-surface-500 mt-1">Recupero accesso</p>
            </div>

            <!-- Card -->
            <div class="bg-white rounded-xl shadow-sm border border-surface-200 p-8">

                <!-- Stato: link inviato -->
                <template v-if="sent">
                    <div class="text-center">
                        <!-- Icona conferma -->
                        <div class="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="pi pi-envelope-open text-2xl text-green-600" />
                        </div>
                        <h2 class="text-lg font-semibold text-surface-900 mb-2">
                            Controlla la tua email
                        </h2>
                        <p class="text-sm text-surface-500 mb-6">
                            Se <strong>{{ email }}</strong> è registrata, riceverai un link
                            di accesso entro pochi secondi. Il link scade tra 15 minuti.
                        </p>
                        <p class="text-xs text-surface-400">
                            Non trovi l'email? Controlla la cartella spam.
                        </p>
                    </div>
                </template>

                <!-- Stato: form inserimento email -->
                <template v-else>

                    <p class="text-sm text-surface-600 mb-6">
                        Inserisci l'email del tuo account. Ti invieremo un link
                        per accedere senza passkey e registrare un nuovo dispositivo.
                    </p>

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
                            @keyup.enter="sendRecoveryLink"
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

                    <!-- Pulsante invia -->
                    <Button
                        label="Invia link di accesso"
                        icon="pi pi-send"
                        class="w-full"
                        :loading="loading"
                        @click="sendRecoveryLink"
                    />

                </template>

                <!-- Link torna al login (sempre visibile) -->
                <div class="text-center mt-6 pt-6 border-t border-surface-100">
                    <a
                        href="/login"
                        class="text-sm text-primary-600 hover:text-primary-700 font-medium"
                    >
                        ← Torna al login
                    </a>
                </div>

            </div>

        </div>
    </div>
</template>
