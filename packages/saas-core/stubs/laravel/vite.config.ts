import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import { resolve } from 'path'

/**
 * Vite config per Laravel + Inertia.js + Vue 3
 *
 * HERD:
 *   Herd serve l'app su http://nomeapp.test (porta 80).
 *   Vite gira separatamente sulla porta 5173 per l'HMR (hot reload).
 *   Il plugin laravel-vite-plugin gestisce l'inject automatico degli
 *   script nel Blade layout — non devi fare nulla di speciale.
 *
 * ALIAS:
 *   '@' → resources/js/   (es: import Button from '@/Components/Button.vue')
 */
export default defineConfig({
    plugins: [
        laravel({
            // Entrypoint principale: carica Vue + Inertia + PrimeVue
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            // Aggiorna la pagina quando i file Blade cambiano
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    // Permette a Vite di processare asset nei template Vue
                    // es: <img src="@/assets/logo.png">
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],

    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },

    // Necessario per far funzionare l'HMR con Herd su HTTPS locale.
    // Se usi Herd Pro con certificati automatici, rimuovi questo blocco.
    server: {
        hmr: {
            host: 'localhost',
        },
    },
})
