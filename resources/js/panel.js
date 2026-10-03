import './bootstrap'; // define window.axios (+ X-Requested-With) — usado pelo Assistente de IA, SearchSelect, EyeImages
import './echo'; // WebSocket (Reverb) — progresso das importações em tempo real
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import mask from './directives/mask.js';
import { installPopstateGuard } from './Support/popstateGuard.js';
import { installTooltips } from './Support/tooltips.js';

// Antes do createInertiaApp: o listener do Voltar/Avançar precisa rodar antes
// do Inertia (aviso de alterações não salvas — ver Support/popstateGuard.js).
installPopstateGuard();

// Tooltips do template em todo elemento com `title` (sob demanda — a
// inicialização do template rodava antes de o Vue desenhar a tela).
installTooltips();

createInertiaApp({
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .directive('mask', mask)
            .mount(el);
    },

    progress: {
        color: '#00B4D8',
        showSpinner: false,
    },
});
