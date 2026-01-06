import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/system/auxiliary_functions.js',
                'resources/js/system/patients.js',
                'resources/js/system/doctors.js',
                'resources/js/system/users.js',
            ],
            refresh: true,
        }),
    ],
});
