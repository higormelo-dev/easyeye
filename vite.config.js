import path from 'path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // === Legado (Blade) — manter até migração completa ===
                'resources/css/vendor.css',
                'resources/css/app.css',
                'resources/css/dashboard.css',
                'resources/js/vendor.js',
                'resources/js/app.js',
                'resources/js/system/auxiliary_functions.js',
                'resources/js/auth/login.js',
                'resources/js/auth/register.js',
                'resources/js/auth/reset-password.js',
                'resources/js/system/patients.js',
                'resources/js/system/doctors.js',
                'resources/js/system/users.js',
                'resources/js/system/schedules.js',
                'resources/js/system/setting.js',
                'resources/js/system/skintypes.js',
                'resources/js/system/iristypes.js',
                'resources/js/system/visittypes.js',
                'resources/js/system/additiontypes.js',
                'resources/js/system/colorvisiontypes.js',
                'resources/js/system/nearpointconvergences.js',
                'resources/js/system/covenants.js',
                'resources/js/system/lenses.js',
                'resources/js/system/surgerytypes.js',
                'resources/js/system/covertesttypes.js',
                'resources/js/system/visualacuitytypes.js',
                'resources/js/auth/password-toggle.js',

                // === React / Inertia — novo entry point ===
                'resources/js/app.jsx',
            ],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': path.resolve('./resources/js'),
            // Single jQuery instance for all npm packages and template scripts.
            jquery: path.resolve('./resources/js/jquery-global.js'),
        },
    },
});
