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
            // `vendor.js` é um bundle vendor deliberado (jQuery + plugins legados +
            // Bootstrap + DataTables) carregado uma vez. As Pages Vue já fazem
            // code-split (import.meta.glob lazy). Não quebramos o vendor em chunks
            // porque o banner injeta jQuery por chunk e reordenar IIFEs legadas é
            // frágil — então elevamos o limite do aviso em vez de fragmentar.
            chunkSizeWarningLimit: 900,
            rollupOptions: {
                output: {
                    // Declara jQuery/$ no topo de CADA chunk para que IIFEs legadas
                    // (morris.js, jquery-toast, jquery-sparkline, etc.) encontrem jQuery
                    // no escopo do chunk. window.jQuery é garantido pelo <script> síncrono
                    // no <head>. jquery-global.js NÃO pode declarar `const jQuery` no
                    // top-level pois conflita com este var — usa window.jQuery diretamente.
                    //
                    // EXCEÇÃO — chunks assíncronos (componentes carregados via
                    // defineAsyncComponent/import() dinâmico): nunca contêm as IIFEs
                    // legadas (essas só existem no bundle eager de vendor.js/panel.js),
                    // então pular o banner aqui é seguro E necessário: o minificador
                    // esbuild processa banner+código como UM programa e escolhe nomes
                    // curtos por chunk — se o chunk assíncrono empacota uma lib pesada
                    // com scope próprio grande (ex.: @fullcalendar/core, que embute o
                    // Preact internamente para renderizar), o nome minificado do `$`
                    // do banner pode colidir com uma função top-level da lib (visto em
                    // produção: CalendarView.vue -> "SyntaxError: Identifier 'ni' has
                    // already been declared", pois o Preact embutido do FullCalendar
                    // definia sua própria função top-level chamada igual ao `$`
                    // minificado). Sem o banner, o chunk não referencia bare `jQuery`/`$`
                    // mesmo assim (é código moderno), então nada quebra.
                    banner: isSsr
                        ? ''
                        : (chunk) => (chunk.isDynamicEntry ? '' : 'var jQuery=window.jQuery,$=window.jQuery;'),
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
