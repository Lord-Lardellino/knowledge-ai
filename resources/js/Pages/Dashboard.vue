<script setup lang="ts">
/**
 * Dashboard.vue — pagina principale dopo il login
 *
 * BACKEND (Laravel):
 *   use Inertia\Inertia;
 *   Route::get('/dashboard', function () {
 *       return Inertia::render('Dashboard', [
 *           'auth' => ['user' => auth()->user()],
 *       ]);
 *   })->middleware(['auth', 'tenant', 'session.hardener']);
 */
import AppLayout   from '@/Layouts/AppLayout.vue'
import Card        from 'primevue/card'
import { useToast } from 'primevue/usetoast'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    auth: { user: { name: string; email: string } }
}>()

// Esempio di notifica toast PrimeVue
const toast = useToast()
function showWelcome () {
    toast.add({
        severity: 'success',
        summary:  'Bentornato!',
        detail:   `Ciao ${props.auth.user.name}`,
        life:     3000,
    })
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="max-w-7xl mx-auto">
        <h1 class="text-2xl font-bold text-surface-900 mb-6">Dashboard</h1>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Card>
                <template #title>Benvenuto</template>
                <template #content>
                    <p class="text-surface-600">
                        Sei autenticato come <strong>{{ props.auth.user.email }}</strong>
                    </p>
                    <Button
                        label="Mostra toast"
                        icon="pi pi-bell"
                        text
                        class="mt-3"
                        @click="showWelcome"
                    />
                </template>
            </Card>
        </div>
    </div>
</template>
