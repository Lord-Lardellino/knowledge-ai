<script setup lang="ts">
import { ref }          from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout        from '@/Layouts/AppLayout.vue'
import ClientFormDialog from '@/Components/ClientFormDialog.vue'
import MatterStatusTag  from '@/Components/MatterStatusTag.vue'
import type { ClientEntry } from '@/composables/useClients'
import type { MatterStatus } from '@/composables/useMatters'

defineOptions({ layout: AppLayout })

interface ClientDetail extends ClientEntry {
    tax_code: string | null
    vat:      string | null
    notes:    string | null
    matters: Array<{
        id: number; title: string; status: MatterStatus
        value_cents: number | null; opened_at: string | null
        type?: { id: number; label: string } | null
    }>
}

const props = defineProps<{
    auth: { user: { name: string; email: string }; tenant?: { id: number; name: string } | null }
    client: ClientDetail
}>()

const editOpen = ref(false)

function formatEuro (cents: number | null): string {
    if (cents == null) return '—'
    return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(cents / 100)
}
function formatDate (iso: string | null): string {
    if (! iso) return '—'
    return new Date(iso).toLocaleDateString('it-IT', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>

<template>
    <Head :title="client.name" />

    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-start gap-3 min-w-0">
                <Button icon="pi pi-arrow-left" severity="secondary" text rounded @click="router.visit('/clients')" />
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <i :class="client.type === 'company' ? 'pi pi-building' : 'pi pi-user'" class="text-surface-400" />
                        <h1 class="text-2xl font-semibold truncate">{{ client.name }}</h1>
                    </div>
                </div>
            </div>
            <Button icon="pi pi-pencil" label="Modifica" severity="secondary" outlined @click="editOpen = true" />
        </div>

        <!-- Anagrafica -->
        <Card>
            <template #content>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div><p class="text-surface-400 mb-0.5">Email</p><p>{{ client.email ?? '—' }}</p></div>
                    <div><p class="text-surface-400 mb-0.5">Telefono</p><p>{{ client.phone ?? '—' }}</p></div>
                    <div><p class="text-surface-400 mb-0.5">Cod. fiscale</p><p>{{ client.tax_code ?? '—' }}</p></div>
                    <div><p class="text-surface-400 mb-0.5">P. IVA</p><p>{{ client.vat ?? '—' }}</p></div>
                </div>
                <div v-if="client.notes" class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700">
                    <p class="text-surface-400 text-sm mb-1">Note</p>
                    <p class="whitespace-pre-line text-surface-700 dark:text-surface-200">{{ client.notes }}</p>
                </div>
            </template>
        </Card>

        <!-- Pratiche del cliente -->
        <Card>
            <template #title>Pratiche</template>
            <template #content>
                <DataTable :value="client.matters" dataKey="id" :rows="10" paginator
                    selectionMode="single" @row-click="router.visit(`/matters/${$event.data.id}`)" class="cursor-pointer">
                    <template #empty>
                        <div class="text-center py-8 text-surface-400">
                            <i class="pi pi-briefcase text-2xl mb-2 block" />
                            <p>Nessuna pratica per questo cliente.</p>
                        </div>
                    </template>
                    <Column field="title" header="Pratica" sortable>
                        <template #body="{ data }"><span class="font-medium">{{ data.title }}</span></template>
                    </Column>
                    <Column header="Materia">
                        <template #body="{ data }"><span class="text-surface-500">{{ data.type?.label ?? '—' }}</span></template>
                    </Column>
                    <Column field="status" header="Stato" sortable>
                        <template #body="{ data }"><MatterStatusTag :status="data.status" /></template>
                    </Column>
                    <Column field="value_cents" header="Valore" sortable>
                        <template #body="{ data }"><span class="text-surface-500">{{ formatEuro(data.value_cents) }}</span></template>
                    </Column>
                    <Column field="opened_at" header="Apertura" sortable>
                        <template #body="{ data }"><span class="text-surface-500">{{ formatDate(data.opened_at) }}</span></template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <ClientFormDialog v-model:visible="editOpen" :client="client" @saved="router.reload({ only: ['client'] })" />
    </div>
</template>
