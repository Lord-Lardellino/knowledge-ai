<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const props = defineProps<{
    auth: { user: { name: string; email: string }; tenant?: { name: string } }
}>()

type NavItem = { label: string; icon: string; href?: string; soon?: boolean }

const page = usePage()
const drawerOpen = ref(false)
const isDark = ref(true)
const search = ref('')

const mainNav: NavItem[] = [
    { label: 'Dashboard',      icon: 'pi pi-home',        href: '/dashboard' },
    { label: 'Knowledge Base', icon: 'pi pi-folder-open', href: '/knowledge' },
    { label: 'Chat AI',        icon: 'pi pi-comments',    soon: true },
    { label: 'Template AI',    icon: 'pi pi-sparkles',    soon: true },
]

const securityNav: NavItem[] = [
    { label: 'Abbonamento',    icon: 'pi pi-credit-card', href: '/billing' },
    { label: 'Passkey',        icon: 'pi pi-key',         href: '/profile/passkeys' },
    { label: 'Autenticazione', icon: 'pi pi-shield',      href: '/profile/totp' },
]

const currentPath = computed(() => {
    const url = typeof page.url === 'string' ? page.url : window.location.pathname
    return url.split('?')[0]
})

function isActive(item: NavItem) {
    return !!item.href && currentPath.value === item.href
}

function visit(item: NavItem) {
    if (!item.href || item.soon) return
    drawerOpen.value = false
    router.visit(item.href)
}

function applyTheme(value: boolean) {
    isDark.value = value
    document.documentElement.classList.toggle('app-dark', value)
    localStorage.setItem('knowledge-ai-theme', value ? 'dark' : 'light')
}

onMounted(() => {
    applyTheme(localStorage.getItem('knowledge-ai-theme') !== 'light')
})
</script>

<template>
    <div class="flex flex-col min-h-screen bg-surface-100 dark:bg-surface-950 text-surface-900 dark:text-surface-0">

        <!-- Appbar -->
        <div class="sticky top-0 z-30 flex items-center gap-3 h-14 px-3 md:px-5 border-b border-primary-400/40 dark:border-primary-700/40 backdrop-blur-xl bg-primary-500/10 dark:bg-primary-950/70">
            <!-- Start -->
            <div class="flex items-center gap-2 shrink-0">
                <Button icon="pi pi-bars" severity="secondary" text rounded @click="drawerOpen = true" />
                <span class="font-bold text-sm tracking-tight">Sapio</span>
                <span class="text-xs text-surface-400 hidden sm:block">Knowledge AI</span>
            </div>
            <!-- Center -->
            <div class="flex-1 flex justify-center">
                <IconField class="w-full max-w-md">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="search" placeholder="Cerca documenti, chat, template…" class="w-full rounded-full" />
                </IconField>
            </div>
            <!-- End -->
            <div class="flex items-center gap-1 shrink-0">
                <Button icon="pi pi-bell" severity="secondary" text rounded />
                <Button :icon="isDark ? 'pi pi-sun' : 'pi pi-moon'" severity="secondary" text rounded @click="applyTheme(!isDark)" />
            </div>
        </div>

        <!-- Drawer headless con #container -->
        <Drawer v-model:visible="drawerOpen" position="left">
            <template #container="{ closeCallback }">
                <div class="flex flex-col h-full bg-surface-0 dark:bg-surface-900">

                    <!-- Header drawer -->
                    <div class="flex items-center justify-between px-5 h-14 shrink-0 border-b border-surface-200 dark:border-surface-700">
                        <div class="flex flex-col min-w-0">
                            <span class="font-bold text-base tracking-tight leading-tight">{{ props.auth.tenant?.name ?? 'Sapio' }}</span>
                            <span class="text-xs text-surface-400 font-medium leading-tight">Knowledge AI</span>
                        </div>
                        <Button icon="pi pi-times" rounded variant="outlined" size="small" @click="closeCallback" />
                    </div>

                    <!-- Nav -->
                    <div class="flex-1 overflow-y-auto">
                        <ul class="list-none px-3 py-4 m-0 space-y-0.5">
                            <li v-for="item in mainNav" :key="item.label">
                                <a v-ripple
                                    class="flex items-center gap-2.5 h-9 px-3 rounded-lg text-sm font-medium cursor-pointer transition-colors duration-150"
                                    :class="item.soon ? 'cursor-default text-surface-400 dark:text-surface-500'
                                        : isActive(item) ? 'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-300'
                                        : 'text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'"
                                    @click="visit(item)">
                                    <i :class="item.icon" class="text-sm w-4 shrink-0" />
                                    <span class="flex-1">{{ item.label }}</span>
                                    <span v-if="item.soon" class="text-[10px] font-semibold uppercase rounded px-1.5 py-0.5 bg-surface-100 dark:bg-surface-700 text-surface-400 dark:text-surface-500">Soon</span>
                                </a>
                            </li>
                        </ul>

                        <div class="px-3">
                            <Divider />
                        </div>

                        <ul class="list-none px-3 pb-4 m-0 space-y-0.5">
                            <li>
                                <p class="mb-2 px-3 text-[11px] font-semibold uppercase tracking-widest text-surface-400 dark:text-surface-500">Sicurezza</p>
                            </li>
                            <li v-for="item in securityNav" :key="item.label">
                                <a v-ripple
                                    class="flex items-center gap-2.5 h-9 px-3 rounded-lg text-sm font-medium cursor-pointer transition-colors duration-150"
                                    :class="isActive(item) ? 'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-300'
                                        : 'text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'"
                                    @click="visit(item)">
                                    <i :class="item.icon" class="text-sm w-4 shrink-0" />
                                    <span>{{ item.label }}</span>
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- Footer utente -->
                    <div class="mt-auto shrink-0">
                        <Divider class="mx-4 my-0" />
                        <div class="flex items-center gap-3 m-4 p-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 cursor-pointer transition-colors">
                            <Avatar :label="props.auth.user.name.charAt(0).toUpperCase()" shape="circle"
                                :pt="{ root: { class: 'bg-primary-500 text-white font-bold shrink-0' } }" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold">{{ props.auth.user.name }}</p>
                                <p class="truncate text-xs text-surface-500">{{ props.auth.user.email }}</p>
                            </div>
                            <Button icon="pi pi-sign-out" severity="secondary" text rounded size="small"
                                v-tooltip.top="'Esci'" @click="router.post('/logout')" />
                        </div>
                    </div>

                </div>
            </template>
        </Drawer>

        <!-- Content -->
        <main class="flex-1 overflow-y-auto p-5 md:p-8">
            <slot />
        </main>

        <Toast />
        <ConfirmDialog />
    </div>
</template>
