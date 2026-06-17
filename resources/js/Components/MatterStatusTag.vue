<script setup lang="ts">
import { computed } from 'vue'
import type { MatterStatus } from '@/composables/useMatters'

const props = defineProps<{ status: MatterStatus }>()

// Mappa stato pratica → aspetto del Tag PrimeVue (severity + label + icona).
const map: Record<MatterStatus, { label: string; severity: string; icon: string }> = {
    open:      { label: 'Aperta',      severity: 'success',   icon: 'pi pi-folder-open' },
    suspended: { label: 'Sospesa',     severity: 'warn',      icon: 'pi pi-pause' },
    closed:    { label: 'Chiusa',      severity: 'info',      icon: 'pi pi-check' },
    archived:  { label: 'Archiviata',  severity: 'secondary', icon: 'pi pi-inbox' },
}

const cfg = computed(() => map[props.status] ?? map.open)
</script>

<template>
    <Tag :severity="cfg.severity" :value="cfg.label" :icon="cfg.icon" rounded />
</template>
