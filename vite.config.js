import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: Number(process.env.VITE_PORT || 5173),
        strictPort: true,
        origin: `http://localhost:${process.env.VITE_PORT || 5173}`,
        hmr: {
            host: 'localhost',
            clientPort: Number(process.env.VITE_PORT || 5173),
        },
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
