<script setup lang="ts">
import { computed } from 'vue'
import type { DocumentStatus } from '@/composables/useDocuments'

const props = defineProps<{ status: DocumentStatus }>()

// Mappa stato → aspetto del Tag PrimeVue (severity + label + icona).
const map: Record<DocumentStatus, { label: string; severity: string; icon: string }> = {
    pending:    { label: 'In attesa',    severity: 'secondary', icon: 'pi pi-clock' },
    processing: { label: 'Elaborazione', severity: 'info',      icon: 'pi pi-spin pi-spinner' },
    indexed:    { label: 'Indicizzato',  severity: 'success',   icon: 'pi pi-check' },
    failed:     { label: 'Errore',       severity: 'danger',    icon: 'pi pi-times' },
}

const cfg = computed(() => map[props.status] ?? map.pending)
</script>

<template>
    <Tag :severity="cfg.severity" :value="cfg.label" :icon="cfg.icon" rounded />
</template>
