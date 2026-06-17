<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { useToast }     from 'primevue/usetoast'
import { useConfirm }   from 'primevue/useconfirm'
import AppLayout        from '@/Layouts/AppLayout.vue'
import MatterStatusTag  from '@/Components/MatterStatusTag.vue'
import MatterFormDialog from '@/Components/MatterFormDialog.vue'
import { useMatters }   from '@/composables/useMatters'
import type { MatterEntry, MatterType } from '@/composables/useMatters'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    auth: { user: { name: string; email: string }; tenant?: { id: number; name: string } | null }
    initialMatters: MatterEntry[]
    matterTypes:    MatterType[]
}>()

const toast   = useToast()
const confirm = useConfirm()

const { matters, remove, reload } = useMatters(props.initialMatters)

const dialogOpen = ref(false)
const filter     = ref('')

const filtered = computed(() => {
    const q = filter.value.trim().toLowerCase()
    if (! q) return matters.value
    return matters.value.filter(m =>
        m.title.toLowerCase().includes(q)
        || m.client?.name?.toLowerCase().includes(q)
        || m.reference?.toLowerCase().includes(q),
    )
})

function openMatter (m: MatterEntry): void {
    router.visit(`/matters/${m.id}`)
}

function confirmRemove (m: MatterEntry): void {
    confirm.require({
        message:     `Eliminare la pratica "${m.title}"? I documenti resteranno, scollegati.`,
        header:      'Elimina pratica',
        icon:        'pi pi-exclamation-triangle',
        rejectLabel: 'Annulla',
        acceptLabel: 'Elimina',
        acceptClass: 'p-button-danger',
        accept: async () => {
            const ok = await remove(m.id)
            toast.add(ok
                ? { severity: 'success', summary: 'Eliminata', detail: `"${m.title}" rimossa.`, life: 3000 }
                : { severity: 'error', summary: 'Errore', detail: 'Eliminazione fallita.', life: 5000 })
        },
    })
}

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
    <Head title="Pratiche" />

    <div class="mx-auto max-w-6xl space-y-6">

        <!-- Header -->
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Pratiche</h1>
                <p class="mt-0.5 text-sm text-surface-500">
                    Gestisci clienti, controparti e documenti di ogni pratica.
                </p>
            </div>
            <Button icon="pi pi-plus" label="Nuova pratica" @click="dialogOpen = true" />
        </div>

        <!-- Filtro -->
        <IconField>
            <InputIcon class="pi pi-search" />
            <InputText v-model="filter" placeholder="Cerca per titolo, cliente o riferimento…" class="w-full" />
        </IconField>

        <!-- Lista pratiche -->
        <Card>
            <template #content>
                <DataTable :value="filtered" dataKey="id" :rows="10" paginator removableSort
                    selectionMode="single" @row-click="openMatter($event.data)" class="cursor-pointer">
                    <template #empty>
                        <div class="text-center py-10 text-surface-400">
                            <i class="pi pi-briefcase text-3xl mb-2 block" />
                            <p>Nessuna pratica. Creane una con “Nuova pratica”.</p>
                        </div>
                    </template>

                    <Column field="title" header="Pratica" sortable>
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="font-medium">{{ data.title }}</span>
                                <span v-if="data.reference" class="text-xs text-surface-400">{{ data.reference }}</span>
                            </div>
                        </template>
                    </Column>

                    <Column header="Cliente" sortable sortField="client.name">
                        <template #body="{ data }">
                            <span class="text-surface-600 dark:text-surface-300">{{ data.client?.name ?? '—' }}</span>
                        </template>
                    </Column>

                    <Column header="Materia">
                        <template #body="{ data }">
                            <span class="text-surface-500">{{ data.type?.label ?? '—' }}</span>
                        </template>
                    </Column>

                    <Column field="status" header="Stato" sortable>
                        <template #body="{ data }"><MatterStatusTag :status="data.status" /></template>
                    </Column>

                    <Column header="Documenti">
                        <template #body="{ data }">
                            <span class="text-surface-500">{{ data.documents_count ?? 0 }}</span>
                        </template>
                    </Column>

                    <Column field="value_cents" header="Valore" sortable>
                        <template #body="{ data }">
                            <span class="text-surface-500">{{ formatEuro(data.value_cents) }}</span>
                        </template>
                    </Column>

                    <Column field="opened_at" header="Apertura" sortable>
                        <template #body="{ data }">
                            <span class="text-surface-500">{{ formatDate(data.opened_at) }}</span>
                        </template>
                    </Column>

                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <Button icon="pi pi-trash" severity="danger" text rounded size="small"
                                v-tooltip.top="'Elimina'" @click.stop="confirmRemove(data)" />
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <MatterFormDialog
            v-model:visible="dialogOpen"
            :matter-types="matterTypes"
            @saved="reload"
        />
    </div>
</template>
