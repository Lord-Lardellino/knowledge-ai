<script setup lang="ts">
import { computed } from 'vue'
import type { MetadataStatus } from '@/composables/useDocumentMetadata'

const props = defineProps<{ status: MetadataStatus }>()

// Mappa stato estrazione metadati → aspetto del Tag PrimeVue.
const map: Record<MetadataStatus, { label: string; severity: string; icon: string }> = {
    pending:    { label: 'In coda',     severity: 'secondary', icon: 'pi pi-clock' },
    processing: { label: 'Analisi AI',  severity: 'info',      icon: 'pi pi-spin pi-spinner' },
    ready:      { label: 'Da rivedere', severity: 'warn',      icon: 'pi pi-eye' },
    confirmed:  { label: 'Confermati',  severity: 'success',   icon: 'pi pi-verified' },
    failed:     { label: 'Errore',      severity: 'danger',    icon: 'pi pi-times' },
}

const cfg = computed(() => map[props.status] ?? map.pending)
</script>

<template>
    <Tag :severity="cfg.severity" :value="cfg.label" :icon="cfg.icon" rounded />
</template>
