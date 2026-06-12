<script setup lang="ts">
import { ref }          from 'vue'
import { Head }         from '@inertiajs/vue3'
import { usePasskey }   from '@/composables/usePasskey'
import AuthSidePanel    from '@/Components/AuthSidePanel.vue'

const email = ref('')
const { login, loading, error } = usePasskey()
</script>

<template>
    <Head title="Accedi" />

    <div class="min-h-screen flex">

        <AuthSidePanel
            quote="La conoscenza aziendale accessibile a tutti, in un secondo."
            :steps="[
                { icon: 'pi pi-folder-open', text: 'Knowledge Base semantica su PDF, DOCX, XLSX' },
                { icon: 'pi pi-comments',    text: 'Chat AI contestuale sui tuoi documenti' },
                { icon: 'pi pi-sparkles',    text: 'Template AI per preventivi e contratti' },
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

                <h1 class="text-2xl font-semibold mb-1">Bentornato</h1>
                <p class="text-sm text-surface-500 mb-8">Accedi al tuo workspace con la tua passkey.</p>

                <div class="flex flex-col gap-1.5 mb-4">
                    <label for="email" class="text-sm font-medium">Email</label>
                    <InputText id="email" v-model="email" type="email" placeholder="nome@azienda.com"
                        autocomplete="email webauthn" class="w-full" :disabled="loading" @keyup.enter="login(email)" />
                </div>

                <Message v-if="error" severity="error" :closable="false" class="mb-4">{{ error }}</Message>

                <Button label="Accedi con passkey" icon="pi pi-fingerprint" class="w-full mb-3"
                    :loading="loading" @click="login(email)" />

                <p class="text-xs text-surface-400 text-center mb-8">
                    Useremo Face ID, Touch ID o Windows Hello — nessuna password.
                </p>

                <Divider />

                <div class="flex justify-between text-sm mt-4">
                    <Button as="a" href="/register" label="Crea account" link class="p-0" />
                    <Button as="a" href="/recover" label="Dispositivo smarrito?" link severity="secondary" class="p-0" />
                </div>

            </div>
        </div>

    </div>
</template>
