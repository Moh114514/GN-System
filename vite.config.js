import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

const publicHost = process.env.VITE_PUBLIC_HOST || 'localhost';
const publicPort = Number(process.env.VITE_PUBLIC_PORT || 5173);

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: `http://${publicHost}:${publicPort}`,
        hmr: {
            host: publicHost,
            clientPort: publicPort,
        },
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
