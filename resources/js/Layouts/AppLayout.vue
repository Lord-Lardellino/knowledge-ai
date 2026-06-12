<script setup lang="ts">
/**
 * AppLayout — layout principale per le pagine autenticate
 *
 * Wrap ogni Page che richiede autenticazione.
 * Uso nel componente Page:
 *   defineOptions({ layout: AppLayout })
 *
 * COMPONENTI PRIMEVUE USATI:
 *   Toast    → notifiche globali ($toast.add(...))
 *   ConfirmDialog → dialog di conferma ($confirm.require(...))
 *   Menubar  → barra di navigazione superiore
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import Menubar       from 'primevue/menubar'
import Toast         from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'

const props = defineProps<{
    auth: { user: { name: string; email: string } }
}>()

function logout (): void {
    router.post('/logout')
}

const menuItems = ref([
    { label: 'Dashboard', icon: 'pi pi-home',  command: () => router.visit('/dashboard') },
    {
        label: 'Profilo',
        icon:  'pi pi-user',
        items: [
            { label: 'Passkey',         icon: 'pi pi-key',    command: () => router.visit('/profile/passkeys') },
            { label: 'Autenticazione',  icon: 'pi pi-shield', command: () => router.visit('/profile/totp') },
        ],
    },
])
</script>

<template>
    <div class="min-h-screen flex flex-col">
        <!-- Barra navigazione -->
        <Menubar :model="menuItems" class="border-0 border-b border-surface-200 rounded-none px-4">
            <template #start>
                <span class="font-bold text-primary text-xl">SaaS</span>
            </template>
            <template #end>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-surface-600">{{ props.auth.user.name }}</span>
                    <button
                        type="button"
                        class="flex items-center gap-1 text-sm text-surface-500 hover:text-surface-800 border border-surface-200 rounded px-3 py-1.5 hover:bg-surface-50 transition-colors"
                        @click="logout"
                    >
                        <i class="pi pi-sign-out text-xs" />
                        Esci
                    </button>
                </div>
            </template>
        </Menubar>

        <!-- Contenuto della Page corrente -->
        <main class="flex-1 p-6">
            <slot />
        </main>

        <!-- Servizi globali PrimeVue — vanno nel layout root, una volta sola -->
        <Toast />
        <ConfirmDialog />
    </div>
</template>
