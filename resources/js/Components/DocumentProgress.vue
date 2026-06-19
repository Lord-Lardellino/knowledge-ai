<script setup lang="ts">
import { computed } from 'vue'
import DocumentStatusTag from '@/Components/DocumentStatusTag.vue'
import type { DocumentEntry } from '@/composables/useDocuments'

const props = defineProps<{ doc: DocumentEntry }>()

// Percentuale di indicizzazione (chunk embeddati / totali). Null = indeterminata.
const indexPct = computed<number | null>(() => {
    if (props.doc.chunk_count > 0 && props.doc.embedded_chunks > 0) {
        return Math.min(100, Math.round((props.doc.embedded_chunks / props.doc.chunk_count) * 100))
    }
    return null
})

// Stato della fase metadati (dopo l'indicizzazione).
const metaBusy = computed(() => props.doc.metadata_status === 'processing' || props.doc.metadata_status === 'pending')
</script>

<template>
    <!-- Errore indicizzazione -->
    <DocumentStatusTag v-if="doc.status === 'failed'" :status="doc.status" />

    <!-- Indicizzazione in corso -->
    <div v-else-if="doc.status !== 'indexed'" class="w-40">
        <div class="mb-1 flex items-center justify-between text-xs text-surface-500">
            <span><i class="pi pi-spin pi-spinner mr-1 text-[10px]" />Indicizzazione</span>
            <span v-if="indexPct !== null">{{ indexPct }}%</span>
        </div>
        <ProgressBar
            v-if="indexPct !== null"
            :value="indexPct"
            :showValue="false"
            style="height: 6px"
        />
        <ProgressBar v-else mode="indeterminate" style="height: 6px" />
    </div>

    <!-- Indicizzato: mostra eventuale avanzamento metadati -->
    <div v-else-if="metaBusy" class="w-40">
        <div class="mb-1 flex items-center gap-1 text-xs text-surface-500">
            <i class="pi pi-spin pi-spinner text-[10px]" />Metadati
        </div>
        <ProgressBar mode="indeterminate" style="height: 6px" />
    </div>

    <!-- Completato -->
    <div v-else class="flex items-center gap-2">
        <DocumentStatusTag :status="doc.status" />
        <i
            v-if="doc.metadata_status === 'failed'"
            class="pi pi-exclamation-triangle text-amber-500"
            v-tooltip.top="'Metadati non estratti'"
        />
    </div>
</template>
