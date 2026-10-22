import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/theme.css',
                'resources/css/portal.css',
            ],
            refresh: true,
            fonts: [
                // IBM Plex Sans: plain, neutral, professional. It does not
                // perform a personality, which is what a trading site wants.
                bunny('IBM Plex Sans', {
                    weights: [400, 500, 600, 700],
                }),
                // Plex Mono is functional, not decorative: it is used for VINs,
                // prices and countdowns so digits line up and can be read off a
                // receipt. It is never used for prose.
                bunny('IBM Plex Mono', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
