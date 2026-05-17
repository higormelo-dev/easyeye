import path from 'path';
import fs from 'fs';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

// Coleta todos os arquivos de imagem de um ou mais diretórios para o input do Vite,
// garantindo que apareçam no manifest e que Vite::asset() funcione no Blade.
const getImageInputs = (...dirs) => {
    const exts = /\.(svg|png|jpg|jpeg|webp|gif|ico)$/;
    const result = [];
    const walk = (d) => {
        if (!fs.existsSync(d)) return;
        for (const entry of fs.readdirSync(d, { withFileTypes: true })) {
            const full = path.join(d, entry.name);
            if (entry.isDirectory()) walk(full);
            else if (exts.test(entry.name)) result.push(full);
        }
    };
    dirs.forEach(walk);
    return result;
};

export default defineConfig({
    server: {
        // Escuta em todas as interfaces dentro do container Docker
        host: '0.0.0.0',
        port: 5173,
        hmr: {
            // Host que o BROWSER usa para conectar ao HMR (porta mapeada no host)
            host: 'localhost',
            port: 5173,
        },
        // inotify não propaga eventos de volume montado no Docker/Linux — polling obrigatório
        watch: {
            usePolling: true,
            interval: 300,
        },
    },
    plugins: [
        vue(),
        laravel({
            input: [
                ...getImageInputs('resources/img/system', 'resources/img/site'),
                'resources/css/site.scss',
                'resources/css/system.scss',
                'resources/css/auth.scss',
                'resources/js/site.js',
                'resources/css/vendor.css',
                'resources/css/dashboard.css',
                'resources/css/manager-dashboard.css',
                'resources/js/vendor.js',
                'resources/js/app.js',
                'resources/js/panel.js',
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
                'resources/js/system/medical-records.js',
                'resources/js/auth/password-toggle.js',
            ],
            refresh: true,
        }),
    ],
    build: {
        rollupOptions: {
            output: {
                // Declara jQuery/$ no topo de CADA chunk para que IIFEs legadas
                // (morris.js, jquery-toast, jquery-sparkline, etc.) encontrem jQuery
                // no escopo do chunk. window.jQuery é garantido pelo <script> síncrono
                // no <head>. jquery-global.js NÃO pode declarar `const jQuery` no
                // top-level pois conflita com este var — usa window.jQuery diretamente.
                banner: 'var jQuery=window.jQuery,$=window.jQuery;',
            },
        },
    },

    resolve: {
        alias: {
            // Single jQuery instance for all npm packages and template scripts.
            // jquery-global.js imports from the npm package and exposes window.$ / window.jQuery.
            jquery: path.resolve('./resources/js/jquery-global.js'),
            '@img': path.resolve('./resources/img'),
        '@': path.resolve('./resources/js'),
        },
    },
});
