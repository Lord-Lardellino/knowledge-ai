import { reactive, computed, watch } from 'vue'
import type { ZodType } from 'zod'

/**
 * useZodForm — validazione di una form con uno schema Zod.
 *
 * Display nativo PrimeVue: `errors[campo]` con `:invalid` e `<Message>`.
 * Non gestisce i valori (restano i tuoi ref).
 *
 * Validazione LIVE per-campo: passa un getter che ritorna uno **snapshot** dei
 * dati (oggetto nuovo ad ogni chiamata, es. `() => ({ ...form.value })`). L'errore
 * di un campo appare solo dopo che è stato modificato (touched) e si aggiorna ad
 * ogni digitazione. Al submit (validate()) vengono mostrati tutti gli errori.
 *
 *   const { errors, validate } = useZodForm(clientSchema, () => ({ ...form.value }))
 *   if (! validate()) return
 */
export function useZodForm<T> (schema: ZodType<T>, getData?: () => Record<string, unknown>) {
    const raw     = reactive<Record<string, string>>({})   // errori completi (interni)
    const touched = reactive<Record<string, boolean>>({})   // campi modificati/visti

    // Errori visibili: solo per i campi già toccati.
    const errors = computed<Record<string, string>>(() => {
        const out: Record<string, string> = {}
        for (const k of Object.keys(raw)) {
            if (touched[k]) out[k] = raw[k]
        }
        return out
    })

    function run (data: unknown): boolean {
        for (const k of Object.keys(raw)) delete raw[k]
        const result = schema.safeParse(data)
        if (result.success) return true

        for (const issue of result.error.issues) {
            const key = issue.path.join('.') || '_'
            if (! raw[key]) raw[key] = issue.message
        }
        return false
    }

    function clear (): void {
        for (const k of Object.keys(raw)) delete raw[k]
        for (const k of Object.keys(touched)) delete touched[k]
    }

    /** Submit: valida tutto e marca tutti i campi come toccati (mostra ogni errore). */
    function validate (data?: Record<string, unknown>): boolean {
        const d = data ?? getData?.()
        const ok = run(d)
        if (d) for (const k of Object.keys(d)) touched[k] = true
        return ok
    }

    /** Marca un campo come toccato e rivalida — da agganciare al @blur dell'input. */
    function touch (name: string): void {
        touched[name] = true
        run(getData?.())
    }

    // Live per-campo: marca toccati solo i campi cambiati, poi rivalida.
    if (getData) {
        watch(getData, (newD, oldD) => {
            const n = (newD ?? {}) as Record<string, unknown>
            const o = (oldD ?? {}) as Record<string, unknown>
            for (const k of Object.keys(n)) {
                if (n[k] !== o[k]) touched[k] = true
            }
            run(n)
        }, { deep: true })
    }

    return { errors, validate, touch, clear }
}
