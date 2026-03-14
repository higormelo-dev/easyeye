import path from 'path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/vendor.js',
                'resources/js/app.js',
                'resources/js/system/auxiliary_functions.js',
                'resources/js/auth/login.js',
                'resources/js/auth/register.js',
                'resources/js/auth/reset-password.js',
                'resources/js/system/patients.js',
                'resources/js/system/doctors.js',
                'resources/js/system/users.js',
            ],
            refresh: true,
        }),
    ],
    resolve: {
        alias: {
            // Re-use the global jQuery loaded via static <script> tag.
            // Prevents duplicate jQuery instances between npm packages and the
            // template scripts (sidebarmenu, waves, custom) that rely on window.$.
            jquery: path.resolve('./resources/js/jquery-global.js'),
        },
    },
});
