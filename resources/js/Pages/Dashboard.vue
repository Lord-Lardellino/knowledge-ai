<script setup lang="ts">
import { Head }   from '@inertiajs/vue3'
import AppLayout  from '@/Layouts/AppLayout.vue'
import MetricCard from '@/Components/MetricCard.vue'

defineOptions({ layout: AppLayout })

defineProps<{
    auth: { user: { name: string; email: string } }
}>()

const metrics = [
    { label: 'Documenti indicizzati', value: '1.248', icon: 'pi pi-file',      color: 'cyan'    as const, trend: '↑ +18% questa settimana', time: '09:30, 12 Giu' },
    { label: 'Risposte AI',           value: '8.934', icon: 'pi pi-comments',  color: 'emerald' as const, trend: '94% risolte senza escalation', time: '10:15, 12 Giu' },
    { label: 'Template generati',     value: '126',   icon: 'pi pi-sparkles',  color: 'amber'   as const, trend: '↑ +5 questa settimana',      time: '08:00, 12 Giu' },
    { label: 'Utenti attivi',         value: '12',    icon: 'pi pi-users',     color: 'violet'  as const, trend: '3 online adesso',            time: 'Ora' },
]

const modules = [
    { title: 'Knowledge Base', description: 'Indicizza PDF, DOCX, XLSX e TXT per reparto. Ricerca semantica su tutti i documenti.',     icon: 'pi pi-folder-open', status: 'Core',         available: true  },
    { title: 'Chat AI',        description: 'Domande operative su procedure, policy e clienti con risposte contestuali tracciabili.',   icon: 'pi pi-comments',    status: 'Prossimamente', available: false },
    { title: 'Template AI',    description: 'Genera preventivi, contratti e verbali partendo dai template aziendali esistenti.',        icon: 'pi pi-sparkles',    status: 'Prossimamente', available: false },
    { title: 'Email AI',       description: 'Riassunto automatico, classificazione e bozze di risposta per Gmail e Outlook.',           icon: 'pi pi-envelope',    status: 'Prossimamente', available: false },
]

const activity = [
    { title: 'Procedura onboarding aggiornata',  detail: 'HR · 12 documenti collegati',       time: '2 ore fa', icon: 'pi pi-refresh',      tone: 'cyan'    as const },
    { title: 'Template preventivo generato',      detail: 'Commerciale · pronto per revisione',time: '5 ore fa', icon: 'pi pi-file-edit',    tone: 'emerald' as const },
    { title: 'Policy rimborso spese indicizzata', detail: 'Amministrazione · embedding 98%',   time: 'Ieri',     icon: 'pi pi-check-circle', tone: 'amber'   as const },
    { title: 'Nuovo utente aggiunto',             detail: 'marco@azienda.com · ruolo: user',   time: 'Ieri',     icon: 'pi pi-user-plus',    tone: 'violet'  as const },
]

const iconBg: Record<string, string> = {
    cyan:    'bg-cyan-500/15 text-cyan-400',
    emerald: 'bg-emerald-500/15 text-emerald-400',
    amber:   'bg-amber-500/15 text-amber-400',
    violet:  'bg-violet-500/15 text-violet-400',
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto max-w-6xl space-y-6 animate-fade-in">

        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">Ciao, {{ auth.user.name.split(' ')[0] }} 👋</h1>
                <p class="mt-0.5 text-sm text-surface-500">Panoramica del workspace — {{ new Date().toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' }) }}</p>
            </div>
            <Button label="Carica documenti" icon="pi pi-upload" />
        </div>

        <!-- Metric cards colorate -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <MetricCard v-for="m in metrics" :key="m.label" v-bind="m" />
        </div>

        <!-- Moduli + Attività -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_320px]">

            <!-- Moduli -->
            <Card>
                <template #header>
                    <div class="px-6 pt-5 pb-1 flex items-center justify-between">
                        <div>
                            <p class="font-semibold text-base">Moduli</p>
                            <p class="text-sm text-surface-500 mt-0.5">Funzionalità disponibili e in arrivo.</p>
                        </div>
                        <Tag value="1 attivo" severity="success" />
                    </div>
                </template>
                <template #content>
                    <div class="space-y-1">
                        <div
                            v-for="mod in modules"
                            :key="mod.title"
                            class="flex items-center gap-4 rounded-lg px-3 py-3 transition-colors"
                            :class="mod.available
                                ? 'hover:bg-surface-100 dark:hover:bg-surface-700 cursor-pointer'
                                : 'opacity-40 cursor-default'"
                        >
                            <div
                                class="grid h-10 w-10 shrink-0 place-items-center rounded-lg text-sm"
                                :class="mod.available
                                    ? 'bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400'
                                    : 'bg-surface-100 text-surface-400 dark:bg-surface-700 dark:text-surface-500'"
                            >
                                <i :class="mod.icon" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold">{{ mod.title }}</span>
                                    <Tag :value="mod.status" :severity="mod.available ? 'primary' : 'secondary'" class="text-[10px]!" />
                                </div>
                                <p class="text-xs text-surface-500 mt-0.5 leading-relaxed">{{ mod.description }}</p>
                            </div>
                            <i
                                class="text-xs shrink-0"
                                :class="mod.available ? 'pi pi-arrow-right text-surface-400' : 'pi pi-lock text-surface-300 dark:text-surface-600'"
                            />
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Attività recente -->
            <Card>
                <template #header>
                    <div class="px-6 pt-5 pb-1">
                        <p class="font-semibold text-base">Attività recente</p>
                        <p class="text-sm text-surface-500 mt-0.5">Ultimi aggiornamenti.</p>
                    </div>
                </template>
                <template #content>
                    <div class="space-y-3">
                        <div v-for="item in activity" :key="item.title" class="flex items-start gap-3">
                            <div class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-xs" :class="iconBg[item.tone]">
                                <i :class="item.icon" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium leading-snug">{{ item.title }}</p>
                                <p class="text-xs text-surface-500 mt-0.5">{{ item.detail }}</p>
                            </div>
                            <span class="shrink-0 text-xs text-surface-400 whitespace-nowrap">{{ item.time }}</span>
                        </div>
                    </div>
                    <div class="mt-4 pt-3 border-t border-surface-200 dark:border-surface-700">
                        <Button label="Vedi tutto →" link class="p-0 text-sm" />
                    </div>
                </template>
            </Card>

        </div>
    </div>
</template>
