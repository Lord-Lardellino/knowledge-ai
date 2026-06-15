<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import AppLayout from '@/Layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

interface Member { id: number; name: string; email: string; role: string }
interface Invite { id: number; email: string; role: string; expires_at: string }

const props = defineProps<{
    auth: { user: { name: string; email: string }; tenant?: { id: number; name: string } | null }
    members:        Member[]
    invites:        Invite[]
    seatsUsed:      number
    seatLimit:      number
    invitableRoles: string[]
}>()

const toast   = useToast()
const confirm = useConfirm()
const page    = usePage()

const email   = ref('')
const role    = ref(props.invitableRoles[props.invitableRoles.length - 1] ?? 'user') // default 'user'
const loading = ref(false)
const lastLink = ref<string | null>(null)

const seatsFull = computed(() => props.seatsUsed >= props.seatLimit)

const roleOptions = computed(() =>
    props.invitableRoles.map(r => ({ label: r.charAt(0).toUpperCase() + r.slice(1), value: r }))
)

function csrf (): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

async function sendInvite (): Promise<void> {
    if (!email.value) return
    loading.value = true
    lastLink.value = null

    try {
        const res = await fetch('/team/invites', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            body: JSON.stringify({ email: email.value, role: role.value }),
        })
        const body = await res.json().catch(() => ({}))

        if (!res.ok) {
            toast.add({ severity: body.seats_full ? 'warn' : 'error', summary: 'Invito non inviato', detail: body.message ?? 'Errore.', life: 7000 })
            return
        }

        lastLink.value = body.link ?? null
        email.value = ''
        toast.add({ severity: 'success', summary: 'Invito creato', detail: body.message, life: 5000 })
        router.reload({ only: ['invites', 'seatsUsed'] })
    } catch {
        toast.add({ severity: 'error', summary: 'Errore', detail: 'Connessione fallita.', life: 6000 })
    } finally {
        loading.value = false
    }
}

function copyLink (): void {
    if (lastLink.value) {
        navigator.clipboard.writeText(lastLink.value)
        toast.add({ severity: 'info', summary: 'Copiato', detail: 'Link invito copiato.', life: 3000 })
    }
}

function revoke (invite: Invite): void {
    confirm.require({
        message:     `Revocare l'invito a ${invite.email}?`,
        header:      'Revoca invito',
        icon:        'pi pi-exclamation-triangle',
        rejectLabel: 'Annulla',
        acceptLabel: 'Revoca',
        acceptClass: 'p-button-danger',
        accept: async () => {
            const res = await fetch(`/team/invites/${invite.id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            })
            if (res.ok) {
                toast.add({ severity: 'success', summary: 'Revocato', detail: `Invito a ${invite.email} revocato.`, life: 4000 })
                router.reload({ only: ['invites', 'seatsUsed'] })
            } else {
                toast.add({ severity: 'error', summary: 'Errore', detail: 'Revoca fallita.', life: 5000 })
            }
        },
    })
}

const roleSeverity: Record<string, string> = { owner: 'warn', admin: 'info', user: 'secondary' }
</script>

<template>
    <Head title="Team" />

    <div class="mx-auto max-w-4xl space-y-6">

        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">Team</h1>
                <p class="mt-0.5 text-sm text-surface-500">
                    {{ auth.tenant?.name }} · <strong>{{ seatsUsed }}/{{ seatLimit }}</strong> posti utilizzati
                </p>
            </div>
        </div>

        <!-- Posti esauriti -->
        <Message v-if="seatsFull" severity="warn" :closable="false">
            Hai raggiunto il limite di posti del tuo piano.
            <a href="/billing" class="font-semibold underline">Passa a un piano superiore</a> per invitare altri utenti.
        </Message>

        <!-- Form invito -->
        <Card>
            <template #title>Invita un collega</template>
            <template #content>
                <div class="flex flex-col sm:flex-row gap-3">
                    <InputText v-model="email" type="email" placeholder="collega@azienda.com"
                        class="flex-1" :disabled="loading || seatsFull" @keyup.enter="sendInvite" />
                    <Select v-model="role" :options="roleOptions" optionLabel="label" optionValue="value"
                        class="sm:w-40" :disabled="loading || seatsFull" />
                    <Button label="Invita" icon="pi pi-send" :loading="loading" :disabled="seatsFull || !email"
                        @click="sendInvite" />
                </div>

                <!-- Link da condividere (fallback se l'email non arriva) -->
                <div v-if="lastLink" class="mt-4 flex items-center gap-2 p-3 rounded-lg bg-surface-100 dark:bg-surface-800">
                    <i class="pi pi-link text-surface-400" />
                    <span class="flex-1 text-xs font-mono truncate">{{ lastLink }}</span>
                    <Button label="Copia" icon="pi pi-copy" size="small" text @click="copyLink" />
                </div>
            </template>
        </Card>

        <!-- Membri -->
        <Card>
            <template #title>Membri</template>
            <template #content>
                <DataTable :value="members" dataKey="id">
                    <Column field="name" header="Nome" />
                    <Column field="email" header="Email" />
                    <Column field="role" header="Ruolo">
                        <template #body="{ data }">
                            <Tag :value="data.role" :severity="roleSeverity[data.role] ?? 'secondary'" rounded />
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <!-- Inviti pendenti -->
        <Card v-if="invites.length">
            <template #title>Inviti in sospeso</template>
            <template #content>
                <DataTable :value="invites" dataKey="id">
                    <Column field="email" header="Email" />
                    <Column field="role" header="Ruolo">
                        <template #body="{ data }">
                            <Tag :value="data.role" :severity="roleSeverity[data.role] ?? 'secondary'" rounded />
                        </template>
                    </Column>
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <Button icon="pi pi-trash" severity="danger" text rounded size="small"
                                v-tooltip.top="'Revoca'" @click="revoke(data)" />
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

    </div>
</template>
