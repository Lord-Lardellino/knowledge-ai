<script setup lang="ts">
import { ref, computed }      from 'vue'
import { Head }               from '@inertiajs/vue3'
import { usePasskeyRegister } from '@/composables/usePasskeyRegister'
import { useZodForm }         from '@/composables/useZodForm'
import { registerSchema, registerInviteSchema } from '@/validation/schemas'
import AuthSidePanel          from '@/Components/AuthSidePanel.vue'

const name    = ref('')
const email   = ref('')
const company = ref('')
const plan    = ref('base')

const inviteToken = new URLSearchParams(window.location.search).get('invite') ?? ''

// Piani selezionabili in fase di registrazione (solo per chi crea l'azienda).
const plans = [
    { key: 'base',       label: 'Base',       price: '19€/mese', note: '3 utenti · 14 giorni di prova gratuita' },
    { key: 'pro',        label: 'Pro',        price: '49€/mese', note: '10 utenti' },
    { key: 'enterprise', label: 'Enterprise', price: '149€/mese', note: '50 utenti' },
]

const { register, loading, error } = usePasskeyRegister()

// Finestra di conferma pre-registrazione (solo per chi crea l'azienda).
const confirmOpen = ref(false)

const selectedPlan = computed(() => plans.find(p => p.key === plan.value) ?? plans[0])
const isDefaultPlan = computed(() => plan.value === 'base')

const { errors, validate, touch } = useZodForm(
    inviteToken ? registerInviteSchema : registerSchema,
    () => inviteToken
        ? { name: name.value, email: email.value }
        : { name: name.value, email: email.value, company: company.value, plan: plan.value },
)

function onRegisterClick() {
    const data = inviteToken
        ? { name: name.value, email: email.value }
        : { name: name.value, email: email.value, company: company.value, plan: plan.value }

    if (! validate(data)) return

    if (inviteToken) {
        doRegister()        // invito: nessuna scelta piano, niente conferma
        return
    }
    confirmOpen.value = true
}

function doRegister() {
    // Invito → entra nel workspace esistente (niente scelta piano).
    // Altrimenti → dopo la registrazione: base = trial Stripe, pro/enterprise = checkout.
    const redirect = inviteToken ? '/dashboard' : `/billing/start/${plan.value}`

    register(name.value, email.value, redirect, {
        company:     company.value || undefined,
        inviteToken: inviteToken || undefined,
    })
}

function confirmRegister() {
    confirmOpen.value = false
    doRegister()
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
                        autocomplete="name" class="w-full" :invalid="!!errors.name" :disabled="loading"
                        @blur="touch('name')" @keyup.enter="onRegisterClick" />
                    <Message v-if="errors.name" severity="error" size="small" variant="simple">{{ errors.name }}</Message>
                </div>

                <div v-if="!inviteToken" class="flex flex-col gap-1.5 mb-4">
                    <label for="company" class="text-sm font-medium">Nome azienda</label>
                    <InputText id="company" v-model="company" type="text" placeholder="Acme Srl"
                        autocomplete="organization" class="w-full" :invalid="!!errors.company" :disabled="loading"
                        @blur="touch('company')" @keyup.enter="onRegisterClick" />
                    <Message v-if="errors.company" severity="error" size="small" variant="simple">{{ errors.company }}</Message>
                    <small v-else class="text-surface-400 text-xs">Crei lo spazio di lavoro: sarai l'amministratore.</small>
                </div>

                <div class="flex flex-col gap-1.5 mb-6">
                    <label for="email" class="text-sm font-medium">Email</label>
                    <InputText id="email" v-model="email" type="email" placeholder="nome@azienda.com"
                        autocomplete="email" class="w-full" :invalid="!!errors.email" :disabled="loading"
                        @blur="touch('email')" @keyup.enter="onRegisterClick" />
                    <Message v-if="errors.email" severity="error" size="small" variant="simple">{{ errors.email }}</Message>
                </div>

                <!-- Scelta piano (solo per chi crea l'azienda) -->
                <div v-if="!inviteToken" class="flex flex-col gap-2 mb-6">
                    <label class="text-sm font-medium">Scegli il piano</label>
                    <button v-for="p in plans" :key="p.key" type="button"
                        class="flex items-center justify-between text-left px-3 py-2.5 rounded-lg border transition-colors"
                        :class="plan === p.key
                            ? 'border-primary-500 bg-primary-50 dark:bg-primary-400/10'
                            : 'border-surface-200 dark:border-surface-700 hover:border-primary-300'"
                        :disabled="loading"
                        @click="plan = p.key">
                        <div>
                            <div class="font-medium text-sm">{{ p.label }} <span class="text-surface-500 font-normal">· {{ p.price }}</span></div>
                            <div class="text-xs text-surface-400">{{ p.note }}</div>
                        </div>
                        <i v-if="plan === p.key" class="pi pi-check-circle text-primary-500" />
                        <i v-else class="pi pi-circle text-surface-300" />
                    </button>
                </div>

                <Message v-if="error" severity="error" :closable="false" class="mb-4">{{ error }}</Message>

                <Button label="Registrati con passkey" icon="pi pi-fingerprint" class="w-full mb-3"
                    :loading="loading" @click="onRegisterClick" />

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

        <!-- Conferma piano prima della registrazione -->
        <Dialog v-model:visible="confirmOpen" modal header="Conferma il piano" :style="{ width: '28rem' }"
            :breakpoints="{ '640px': '95vw' }">
            <div class="flex flex-col gap-4">
                <div class="flex items-center justify-between rounded-lg border border-surface-200 dark:border-surface-700 px-3 py-2.5">
                    <div>
                        <div class="font-medium text-sm">{{ selectedPlan.label }}</div>
                        <div class="text-xs text-surface-400">{{ selectedPlan.price }}</div>
                    </div>
                    <i class="pi pi-check-circle text-primary-500" />
                </div>

                <Message v-if="isDefaultPlan" severity="info" :closable="false" class="text-sm">
                    Prova gratuita di 14 giorni, poi addebito automatico. Su Stripe ti verrà chiesta
                    la carta e l'email <strong>{{ email || 'inserita' }}</strong> sarà bloccata: non potrai
                    pagare con un'altra email.
                </Message>
                <Message v-else severity="warn" :closable="false" class="text-sm">
                    Pagamento immediato e attivazione subito. Su Stripe l'email
                    <strong>{{ email || 'inserita' }}</strong> sarà bloccata.
                </Message>
            </div>

            <template #footer>
                <Button label="Annulla" severity="secondary" text @click="confirmOpen = false" />
                <Button label="Conferma e registrati" icon="pi pi-fingerprint" :loading="loading" @click="confirmRegister" />
            </template>
        </Dialog>

    </div>
</template>
