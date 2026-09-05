import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: [
                'routes/**',
                'app/Http/**',
                'resources/views/**',
            ],
        }),
        react(),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            '@': resolve(import.meta.dirname, 'resources/js'),
            '@ui': resolve(import.meta.dirname, 'resources/js/DesignSystem'),
        },
    },

    server: {
        // Bound to all interfaces because Vite runs in its own container.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // The browser talks to the host, not the container network, so HMR
        // must advertise localhost rather than the Docker service name.
        hmr: { host: 'localhost' },
        watch: {
            // Windows bind mounts do not deliver inotify events reliably.
            usePolling: true,
            interval: 300,
        },
    },

    build: {
        // Source maps in production make a real stack trace out of a customer
        // bug report. They are served only to those who ask for them.
        sourcemap: true,
    },
});
