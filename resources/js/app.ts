import { createApp, h, type DefineComponent } from 'vue'
import { createInertiaApp }  from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import PrimeVue               from 'primevue/config'
import Aura                   from '@primevue/themes/aura'
import ToastService           from 'primevue/toastservice'
import ConfirmationService    from 'primevue/confirmationservice'
import Tooltip                from 'primevue/tooltip'
import 'primeicons/primeicons.css'

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
                    preset: Aura,
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
