<script setup lang="ts">
import { onMounted } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { useToast } from 'primevue/usetoast'
import AppLayout from '@/Layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

interface Plan {
    key:         string
    label:       string
    price_label: string
    description: string
    features:    string[]
    seats:       number
    buyable:     boolean
    highlight:   boolean
}

const props = defineProps<{
    auth: { user: { name: string; email: string }; tenant?: { id: number; name: string } | null }
    plans:         Plan[]
    isOwner:       boolean
    currentPlan:   string
    onTrial:       boolean
    trialDaysLeft: number
    seatsUsed:     number
    seatLimit:     number
}>()

const toast = useToast()
const page  = usePage()

// Mostra i flash message del backend (checkout/swap) come Toast.
onMounted(() => {
    const flash = page.props.flash as { success?: string; error?: string } | undefined
    if (flash?.success) toast.add({ severity: 'success', summary: 'Fatto', detail: flash.success, life: 5000 })
    if (flash?.error)   toast.add({ severity: 'error', summary: 'Attenzione', detail: flash.error, life: 7000 })
})

function subscribe (plan: Plan): void {
    if (!plan.buyable) {
        toast.add({ severity: 'warn', summary: 'Non disponibile', detail: 'Questo piano non è acquistabile al momento.', life: 5000 })
        return
    }
    router.post(`/billing/checkout/${plan.key}`)
}

function openPortal (): void {
    window.location.href = '/billing/portal'
}
</script>

<template>
    <Head title="Abbonamento" />

    <div class="mx-auto max-w-5xl space-y-8">

        <!-- Header -->
        <div class="text-center">
            <h1 class="text-2xl font-semibold">Scegli il tuo piano</h1>
            <p class="mt-1 text-sm text-surface-500">
                Workspace <strong>{{ auth.tenant?.name }}</strong> ·
                {{ seatsUsed }}/{{ seatLimit }} posti utilizzati
            </p>
        </div>

        <!-- Solo l'owner gestisce l'abbonamento -->
        <Message v-if="!isOwner" severity="warn" :closable="false">
            <i class="pi pi-lock mr-2" />
            Solo l'owner dell'azienda può gestire l'abbonamento. Contatta il tuo amministratore.
        </Message>

        <!-- Banner trial -->
        <Message v-if="onTrial" severity="info" :closable="false">
            <i class="pi pi-clock mr-2" />
            Sei in periodo di prova: <strong>{{ trialDaysLeft }} giorni</strong> rimanenti.
            Abbonati per continuare senza interruzioni.
        </Message>

        <!-- Piani -->
        <div class="grid gap-6 md:grid-cols-2">
            <Card
                v-for="plan in plans"
                :key="plan.key"
                :pt="{ root: { class: [
                    'border transition-shadow',
                    plan.highlight
                        ? 'border-primary-500 shadow-lg'
                        : 'border-surface-200 dark:border-surface-700',
                ] } }"
            >
                <template #title>
                    <div class="flex items-center justify-between">
                        <span>{{ plan.label }}</span>
                        <Tag v-if="currentPlan === plan.key" value="Piano attuale" severity="success" rounded />
                        <Tag v-else-if="plan.highlight" value="Consigliato" severity="info" rounded />
                    </div>
                </template>

                <template #subtitle>
                    <span class="text-2xl font-bold text-surface-900 dark:text-surface-0">{{ plan.price_label }}</span>
                </template>

                <template #content>
                    <p class="text-sm text-surface-500 mb-4">{{ plan.description }}</p>
                    <ul class="space-y-2">
                        <li v-for="f in plan.features" :key="f" class="flex items-center gap-2 text-sm">
                            <i class="pi pi-check text-primary-500 text-xs" />
                            {{ f }}
                        </li>
                    </ul>
                </template>

                <template #footer>
                    <Button
                        v-if="currentPlan === plan.key"
                        label="Gestisci abbonamento"
                        icon="pi pi-cog"
                        severity="secondary"
                        outlined
                        class="w-full"
                        :disabled="!isOwner"
                        @click="openPortal"
                    />
                    <Button
                        v-else
                        :label="'Passa a ' + plan.label"
                        icon="pi pi-arrow-right"
                        iconPos="right"
                        class="w-full"
                        :disabled="!isOwner"
                        @click="subscribe(plan)"
                    />
                </template>
            </Card>
        </div>

        <p class="text-center text-xs text-surface-400">
            Pagamento sicuro tramite Stripe. Puoi annullare in qualsiasi momento dal portale di gestione.
        </p>

    </div>
</template>
