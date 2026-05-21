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

export default defineConfig(({ command, mode, ssrBuild }) => {
    const isSsr = ssrBuild || process.argv.includes('--ssr');
    
    return {
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
                    'resources/js/panel.js',
                    // Painel inteiro migrado para Vue/Inertia (Auth + Catálogos +
                    // Patients + Doctors + Users + Setting + MedicalRecord).
                    // Alpine.js foi removido — não há mais entry app.js.
                ],
                refresh: true,
                ssr: 'resources/js/ssr.js',
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
                    banner: isSsr ? '' : 'var jQuery=window.jQuery,$=window.jQuery;',
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
    };
});
