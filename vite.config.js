import path from 'path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // CSS carregado pelo template Inertia (resources/views/app.blade.php)
                'resources/css/app.css',

                // Entry point React / Inertia — único bundle JS
                'resources/js/app.jsx',
            ],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': path.resolve('./resources/js'),
        },
    },
});
