<script setup lang="ts">
import { ref, computed }   from 'vue'
import { Head, router }    from '@inertiajs/vue3'
import AppLayout           from '@/Layouts/AppLayout.vue'
import ClientFormDialog    from '@/Components/ClientFormDialog.vue'
import { useClients }      from '@/composables/useClients'
import type { ClientEntry } from '@/composables/useClients'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    auth: { user: { name: string; email: string }; tenant?: { id: number; name: string } | null }
    initialClients: ClientEntry[]
}>()

const { clients, reload } = useClients(props.initialClients)

const dialogOpen = ref(false)
const editing    = ref<ClientEntry | null>(null)
const filter     = ref('')

const filtered = computed(() => {
    const q = filter.value.trim().toLowerCase()
    if (! q) return clients.value
    return clients.value.filter(c =>
        c.name.toLowerCase().includes(q)
        || c.email?.toLowerCase().includes(q),
    )
})

function openCreate (): void {
    editing.value = null
    dialogOpen.value = true
}

function openEdit (c: ClientEntry): void {
    editing.value = c
    dialogOpen.value = true
}

function openClient (c: ClientEntry): void {
    router.visit(`/clients/${c.id}`)
}
</script>

<template>
    <Head title="Clienti" />

    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Clienti</h1>
                <p class="mt-0.5 text-sm text-surface-500">Anagrafica clienti dello studio.</p>
            </div>
            <Button icon="pi pi-plus" label="Nuovo cliente" @click="openCreate" />
        </div>

        <IconField>
            <InputIcon class="pi pi-search" />
            <InputText v-model="filter" placeholder="Cerca per nome o email…" class="w-full" />
        </IconField>

        <Card>
            <template #content>
                <DataTable :value="filtered" dataKey="id" :rows="10" paginator removableSort
                    selectionMode="single" @row-click="openClient($event.data)" class="cursor-pointer">
                    <template #empty>
                        <div class="text-center py-10 text-surface-400">
                            <i class="pi pi-id-card text-3xl mb-2 block" />
                            <p>Nessun cliente. Creane uno con “Nuovo cliente”.</p>
                        </div>
                    </template>

                    <Column field="name" header="Cliente" sortable>
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <i :class="data.type === 'company' ? 'pi pi-building text-surface-400' : 'pi pi-user text-surface-400'" />
                                <span class="font-medium">{{ data.name }}</span>
                            </div>
                        </template>
                    </Column>
                    <Column field="email" header="Email" sortable>
                        <template #body="{ data }"><span class="text-surface-500">{{ data.email ?? '—' }}</span></template>
                    </Column>
                    <Column field="phone" header="Telefono">
                        <template #body="{ data }"><span class="text-surface-500">{{ data.phone ?? '—' }}</span></template>
                    </Column>
                    <Column header="Pratiche" sortable sortField="matters_count">
                        <template #body="{ data }"><span class="text-surface-500">{{ data.matters_count ?? 0 }}</span></template>
                    </Column>
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <Button icon="pi pi-pencil" severity="secondary" text rounded size="small"
                                v-tooltip.top="'Modifica'" @click.stop="openEdit(data)" />
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <ClientFormDialog v-model:visible="dialogOpen" :client="editing" @saved="reload" />
    </div>
</template>
