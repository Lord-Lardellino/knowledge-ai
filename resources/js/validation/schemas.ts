import { z } from 'zod'

/**
 * Schemi Zod riusabili per la validazione delle form.
 * Usati con il composable useZodForm + display nativo PrimeVue (:invalid + Message).
 */

const email = z.string().min(1, 'Email obbligatoria').email('Email non valida')
const emailOptional = z.union([z.literal(''), z.string().email('Email non valida')]).optional().nullable()

// --- Auth ---
export const registerSchema = z.object({
    name:    z.string().min(1, 'Nome obbligatorio'),
    email,
    company: z.string().min(1, 'Nome azienda obbligatorio'),
    plan:    z.string().min(1, 'Seleziona un piano'),
})

// Registrazione tramite invito: nessuna azienda da creare.
export const registerInviteSchema = z.object({
    name: z.string().min(1, 'Nome obbligatorio'),
    email,
})

export const loginSchema   = z.object({ email })
export const recoverSchema = z.object({ email })

// --- Team ---
export const teamInviteSchema = z.object({
    email,
    role: z.string().min(1, 'Ruolo obbligatorio'),
})

// --- Verticale legale ---
export const clientSchema = z.object({
    name:  z.string().min(1, 'Nome obbligatorio'),
    type:  z.enum(['person', 'company']),
    email: emailOptional,
})

export const partySchema = z.object({
    name: z.string().min(1, 'Nome obbligatorio'),
    type: z.enum(['person', 'company']),
})

export const matterSchema = z.object({
    title:     z.string().min(1, 'Titolo obbligatorio'),
    client_id: z.number({ error: 'Cliente obbligatorio' }).int().positive('Cliente obbligatorio'),
    status:    z.enum(['open', 'suspended', 'closed', 'archived']),
})

// Crea pratica dai documenti: cliente per nome (creato se non esiste).
export const matterFromDocsSchema = z.object({
    title:       z.string().min(1, 'Titolo obbligatorio'),
    client_name: z.string().min(1, 'Cliente obbligatorio'),
})
