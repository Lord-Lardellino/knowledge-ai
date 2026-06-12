import { createApp, h, type DefineComponent } from 'vue'
import { createInertiaApp }  from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import PrimeVue               from 'primevue/config'
import Aura                   from '@primevue/themes/aura'
import ToastService           from 'primevue/toastservice'
import ConfirmationService    from 'primevue/confirmationservice'
import 'primeicons/primeicons.css'

const initialPageElement = document.getElementById('app') as HTMLElement | null
const initialPage = initialPageElement?.dataset.page
    ? JSON.parse(initialPageElement.dataset.page)
    : undefined

/**
 * Bootstrap Inertia.js + Vue 3 + PrimeVue
 *
 * COME FUNZIONA INERTIA:
 *   - Laravel risponde con JSON (non Blade) per le navigazioni Inertia
 *   - Il layout non si ricarica: solo il componente Page cambia
 *   - Ogni Page (es. Pages/Dashboard.vue) riceve le props da Laravel
 *     tramite Inertia::render('Dashboard', ['user' => $user])
 *
 * PRIMEVUE v4 — STYLED MODE CON TEMA AURA:
 *   - Nessun import CSS manuale: PrimeVue inietta gli stili via JS
 *   - Cambia tema in app.css con le variabili CSS --p-*
 *   - Dark mode: aggiungi/rimuovi la classe .dark sull'elemento html
 *
 * SERVIZI REGISTRATI:
 *   ToastService      → $toast.add({ severity: 'success', summary: 'OK' })
 *   ConfirmationService → $confirm.require({ message: 'Sei sicuro?' })
 */
createInertiaApp({
    page: initialPage,
    // Titolo della pagina: "Dashboard — NomeSaaS"
    // Ogni Page può definire <Head title="Dashboard" />
    title: (title) => title ? `${title} — SaaS` : 'SaaS',

    // Risolve il componente Page dal nome che Laravel passa a Inertia::render()
    // Es: Inertia::render('Auth/Login') → Pages/Auth/Login.vue
    resolve: (name) => resolvePageComponent(
        `./Pages/${name}.vue`,
        import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
    ),

    setup ({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(PrimeVue, {
                theme: {
                    preset: Aura,
                    options: {
                        // La classe .dark sull'elemento html attiva il dark mode
                        darkModeSelector: '.dark',
                        // Tailwind v4 usa i layer: theme, base, (primevue), utilities
                        // Con questo ordine le utility Tailwind sovrascrivono sempre PrimeVue
                        // Docs: https://primevue.org/tailwind/
                        cssLayer: { name: 'primevue', order: 'theme, base, primevue' },
                    },
                },
            })
            .use(ToastService)
            .use(ConfirmationService)
            .mount(el)
    },

    // Barra di progresso durante la navigazione tra pagine
    progress: {
        color: 'var(--p-primary-500)',
    },
})
