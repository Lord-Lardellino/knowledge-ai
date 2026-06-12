<script setup lang="ts">
defineProps<{
    title: string
    description: string
    icon: string
    status?: string
}>()
</script>

<template>
    <div class="group rounded-lg border border-surface-200 bg-surface-0 p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-primary-200 hover:shadow-md dark:border-surface-800 dark:bg-surface-900 dark:hover:border-primary-500/40">
        <div class="flex items-start gap-4">
            <div class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-surface-100 text-surface-700 transition group-hover:bg-primary-50 group-hover:text-primary-700 dark:bg-surface-800 dark:text-surface-200 dark:group-hover:bg-primary-400/10 dark:group-hover:text-primary-300">
                <i :class="icon" class="text-lg" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="truncate text-base font-semibold text-surface-950 dark:text-surface-0">{{ title }}</h3>
                    <span v-if="status" class="rounded-md bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700 dark:bg-primary-400/10 dark:text-primary-300">{{ status }}</span>
                </div>
                <p class="mt-2 text-sm leading-6 text-surface-500 dark:text-surface-400">{{ description }}</p>
            </div>
        </div>
        <div v-if="$slots.default" class="mt-4 border-t border-surface-100 pt-4 dark:border-surface-800">
            <slot />
        </div>
    </div>
</template>
