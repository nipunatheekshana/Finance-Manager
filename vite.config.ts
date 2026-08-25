import { defineConfig } from 'vite'
import { fileURLToPath, URL } from 'node:url'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: ['routes/**', 'app/**', 'resources/views/**'],
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
        VitePWA({
            registerType: 'prompt',
            injectRegister: null,
            // Laravel serves the shell, so the manifest and icons live in
            // public/ and are referenced from the Blade layout.
            manifest: false,
            srcDir: 'resources/js',
            filename: 'sw.ts',
            strategies: 'injectManifest',
            // The worker must sit at the document root: a worker served from
            // /build/ can only ever control /build/, so it could never manage
            // the app shell.
            outDir: 'public',
            injectManifest: {
                globDirectory: 'public/build',
                globPatterns: ['assets/**/*.{js,css,woff2}'],
                // Assets are globbed from public/build but served under /build.
                modifyURLPrefix: { '': '/build/' },
                // The shell is small; keep a generous ceiling for the JS bundle.
                maximumFileSizeToCacheInBytes: 5 * 1024 * 1024,
            },
            devOptions: {
                enabled: false,
            },
        }),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    // Chunking is left to the bundler. Chart.js is only reached through
    // lazily-loaded routes, so forcing it into a named chunk here made it a
    // static dependency of the entry and pulled it into the first paint.
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
})
