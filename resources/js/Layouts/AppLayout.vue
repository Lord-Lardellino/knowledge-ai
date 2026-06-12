<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLogo from '@/Components/AppLogo.vue'

const props = defineProps<{
    auth: { user: { name: string; email: string } }
}>()

type NavItem = {
    label: string
    icon: string
    href?: string
    soon?: boolean
}

const page = usePage()
const sidebarOpen = ref(false)
const isDark = ref(true)

const mainNav: NavItem[] = [
    { label: 'Dashboard',      icon: 'pi pi-home',        href: '/dashboard' },
    { label: 'Knowledge Base', icon: 'pi pi-folder-open', soon: true },
    { label: 'Chat AI',        icon: 'pi pi-comments',    soon: true },
    { label: 'Template AI',    icon: 'pi pi-sparkles',    soon: true },
]

const securityNav: NavItem[] = [
    { label: 'Passkey',        icon: 'pi pi-key',    href: '/profile/passkeys' },
    { label: 'Autenticazione', icon: 'pi pi-shield', href: '/profile/totp' },
]

const currentPath = computed(() => {
    const url = typeof page.url === 'string' ? page.url : window.location.pathname
    return url.split('?')[0]
})

function isActive(item: NavItem): boolean {
    return !!item.href && currentPath.value === item.href
}

function visit(item: NavItem): void {
    if (!item.href || item.soon) return
    sidebarOpen.value = false
    router.visit(item.href)
}

function logout(): void {
    router.post('/logout')
}

function applyTheme(value: boolean): void {
    isDark.value = value
    document.documentElement.classList.toggle('app-dark', value)
    localStorage.setItem('knowledge-ai-theme', value ? 'dark' : 'light')
}

onMounted(() => {
    const stored = localStorage.getItem('knowledge-ai-theme')
    applyTheme(stored !== 'light')
})
</script>

<template>
    <div class="min-h-screen bg-surface-100 dark:bg-surface-950 text-surface-900 dark:text-surface-0">

        <!-- Mobile overlay -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-surface-950/60 backdrop-blur-sm lg:hidden"
            @click="sidebarOpen = false"
        />

        <div class="flex min-h-screen">

            <!-- ── Sidebar ─────────────────────────────────────────── -->
            <aside
                class="fixed left-0 top-0 z-40 flex h-screen w-60 flex-col bg-surface-0 dark:bg-surface-800 border-r border-surface-200 dark:border-surface-700 transition-transform duration-200 lg:static lg:translate-x-0"
                :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            >
                <!-- Logo -->
                <div class="flex h-14 shrink-0 items-center border-b border-surface-200 dark:border-surface-700 px-4">
                    <AppLogo />
                    <button
                        type="button"
                        class="ml-auto grid h-7 w-7 place-items-center rounded text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 lg:hidden"
                        @click="sidebarOpen = false"
                    >
                        <i class="pi pi-times text-xs" />
                    </button>
                </div>

                <!-- Nav principale -->
                <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-0.5">
                    <button
                        v-for="item in mainNav"
                        :key="item.label"
                        type="button"
                        class="flex h-9 w-full items-center gap-2.5 rounded-lg px-3 text-left text-sm font-medium transition-colors"
                        :class="item.soon
                            ? 'cursor-default text-surface-400 dark:text-surface-500'
                            : isActive(item)
                                ? 'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-300'
                                : 'text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-0'"
                        @click="visit(item)"
                    >
                        <i :class="item.icon" class="text-sm w-4 shrink-0" />
                        <span class="flex-1 truncate">{{ item.label }}</span>
                        <span
                            v-if="item.soon"
                            class="text-[10px] font-semibold uppercase tracking-wide rounded px-1.5 py-0.5 bg-surface-100 dark:bg-surface-700 text-surface-400 dark:text-surface-500"
                        >Soon</span>
                    </button>

                    <div class="my-3 h-px bg-surface-200 dark:bg-surface-700" />

                    <p class="mb-1 px-3 text-[11px] font-semibold uppercase tracking-widest text-surface-400 dark:text-surface-500">
                        Sicurezza
                    </p>

                    <button
                        v-for="item in securityNav"
                        :key="item.label"
                        type="button"
                        class="flex h-9 w-full items-center gap-2.5 rounded-lg px-3 text-left text-sm font-medium transition-colors"
                        :class="isActive(item)
                            ? 'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-300'
                            : 'text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-0'"
                        @click="visit(item)"
                    >
                        <i :class="item.icon" class="text-sm w-4 shrink-0" />
                        <span>{{ item.label }}</span>
                    </button>
                </nav>

                <!-- User footer -->
                <div class="shrink-0 border-t border-surface-200 dark:border-surface-700 p-3 space-y-2">
                    <div class="flex items-center gap-2.5 px-1 py-1">
                        <div class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary-500 text-sm font-bold text-white uppercase">
                            {{ props.auth.user.name.charAt(0) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-surface-900 dark:text-surface-0">{{ props.auth.user.name }}</p>
                            <p class="truncate text-xs text-surface-500 dark:text-surface-400">{{ props.auth.user.email }}</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-surface-200 dark:border-surface-700 py-1.5 text-xs font-medium text-surface-500 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                            @click="applyTheme(!isDark)"
                        >
                            <i :class="isDark ? 'pi pi-sun' : 'pi pi-moon'" class="text-xs" />
                            <span>{{ isDark ? 'Chiaro' : 'Scuro' }}</span>
                        </button>
                        <button
                            type="button"
                            class="flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-surface-200 dark:border-surface-700 py-1.5 text-xs font-medium text-surface-500 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                            @click="logout"
                        >
                            <i class="pi pi-sign-out text-xs" />
                            <span>Esci</span>
                        </button>
                    </div>
                </div>
            </aside>

            <!-- ── Main ──────────────────────────────────────────────── -->
            <div class="flex min-h-screen flex-1 flex-col min-w-0">

                <!-- Topbar -->
                <header class="flex h-14 shrink-0 items-center gap-4 border-b border-surface-200 dark:border-surface-700 bg-surface-0 dark:bg-surface-800 px-4 md:px-6">
                    <button
                        type="button"
                        class="grid h-8 w-8 place-items-center rounded-lg text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 lg:hidden transition-colors"
                        @click="sidebarOpen = true"
                    >
                        <i class="pi pi-bars text-sm" />
                    </button>
                    <div class="flex-1" />
                    <button
                        type="button"
                        class="grid h-8 w-8 place-items-center rounded-lg text-surface-500 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                    >
                        <i class="pi pi-bell text-sm" />
                    </button>
                </header>

                <!-- Content -->
                <main class="flex-1 overflow-y-auto p-5 md:p-8">
                    <slot />
                </main>
            </div>
        </div>

        <Toast />
        <ConfirmDialog />
    </div>
</template>
