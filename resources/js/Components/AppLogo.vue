<script setup lang="ts">
defineProps<{ compact?: boolean }>()
</script>

<template>
    <div class="flex items-center gap-2.5">
        <div class="relative grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-primary-600 text-white shadow-sm shadow-primary-500/30">
            <i class="pi pi-sparkles text-sm" />
        </div>
        <div v-if="!compact" class="leading-tight">
            <p class="text-sm font-bold tracking-tight text-surface-900 dark:text-surface-0">Sapio</p>
            <p class="text-[11px] text-surface-400 dark:text-surface-500">Knowledge AI</p>
        </div>
    </div>
</template>
