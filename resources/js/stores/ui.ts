import { ref } from 'vue'

/**
 * Stato UI condiviso fra componenti slegati nell'albero.
 *
 * fullscreenOverlay: true quando è aperto un overlay a tutto schermo (es. il
 * confronto documenti) → la chat flottante si nasconde per non sovrapporsi.
 */
export const fullscreenOverlay = ref(false)
