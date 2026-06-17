import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

/**
 * Bootstrap di Laravel Echo sul broadcaster Reverb (protocollo Pusher).
 * Le variabili arrivano da Vite (VITE_REVERB_*). L'auth dei canali privati passa
 * da POST /broadcasting/auth (registrato dal Core) con il cookie di sessione.
 */
declare global {
    interface Window {
        Pusher: typeof Pusher
        Echo: Echo<'reverb'>
    }
}

window.Pusher = Pusher

// build marker: reverb-tls-v2 (forza nuovo hash bundle / cache-bust)
export const echo = new Echo({
    broadcaster: 'reverb',
    key:        import.meta.env.VITE_REVERB_APP_KEY,
    wsHost:     import.meta.env.VITE_REVERB_HOST,
    wsPort:     Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort:    Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    forceTLS:   (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
})

window.Echo = echo
