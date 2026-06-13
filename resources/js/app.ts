import { createApp, h, type DefineComponent } from 'vue'
import { createInertiaApp }     from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import PrimeVue                 from 'primevue/config'
import Aura                     from '@primevue/themes/aura'
import { definePreset }         from '@primevue/themes'
import ToastService             from 'primevue/toastservice'
import ConfirmationService      from 'primevue/confirmationservice'
import Tooltip                  from 'primevue/tooltip'
import 'primeicons/primeicons.css'

// Tema: colore primario teal tramite token nativi della palette PrimeVue.
const SapioTheme = definePreset(Aura, {
    semantic: {
        primary: {
            50:  '{teal.50}',
            100: '{teal.100}',
            200: '{teal.200}',
            300: '{teal.300}',
            400: '{teal.400}',
            500: '{teal.500}',
            600: '{teal.600}',
            700: '{teal.700}',
            800: '{teal.800}',
            900: '{teal.900}',
            950: '{teal.950}',
        },
    },
})

const initialPageElement = document.getElementById('app') as HTMLElement | null
const initialPage = initialPageElement?.dataset.page
    ? JSON.parse(initialPageElement.dataset.page)
    : undefined

const storedTheme = localStorage.getItem('knowledge-ai-theme')
document.documentElement.classList.toggle('app-dark', storedTheme !== 'light')

createInertiaApp({
    page: initialPage,
    title: (title) => title ? `${title} — Sapio` : 'Sapio',
    resolve: (name) => resolvePageComponent(
        `./Pages/${name}.vue`,
        import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
    ),
    setup ({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(PrimeVue, {
                theme: {
                    preset: SapioTheme,
                    options: {
                        darkModeSelector: '.app-dark',
                        cssLayer: { name: 'primevue', order: 'theme, base, primevue' },
                    },
                },
            })
            .use(ToastService)
            .use(ConfirmationService)
            .directive('tooltip', Tooltip)
            .mount(el)
    },
    progress: {
        color: 'var(--p-primary-500)',
    },
})
