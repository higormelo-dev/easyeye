import axios from 'axios';
import { createSSRApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import mask from './directives/mask.js';
import { installTooltips } from './Support/tooltips.js';

// BUG — ícones invisíveis no /register (e demais telas de auth servidas pelo
// rootView 'app'): as páginas Auth/* usam <i class="ti ti-*"> (Tabler Icons),
// mas a webfont só era importada em vendor.css, que pertence aos rootViews
// panel-app/guest-app/portal-app. O rootView 'app' (site.scss + site.js) não
// a carregava — os <i> renderizavam como quadrados vazios. Importar aqui
// coloca o @font-face no bundle do site; o browser só baixa a fonte quando
// algum glifo ti-* aparece na página (páginas de marketing sem ícones não
// pagam o custo).
import '@tabler/icons-webfont/dist/tabler-icons.min.css';

// Configure axios CSRF for register form and other AJAX calls
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
const csrfMeta = document.querySelector('meta[name="csrf-token"]');
if (csrfMeta) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfMeta.getAttribute('content');
}
window.axios = axios;

// Tooltips do template (2FA, Portal de Parceiros); sem o CSS do Bootstrap
// (site de marketing) não liga e fica o title nativo.
installTooltips();

const appName = document.documentElement.dataset.app ?? 'EasyEye';

createInertiaApp({
    title: (title) => (title ? `${appName} — ${title}` : appName),

    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),

    setup({ el, App, props, plugin }) {
        createSSRApp({ render: () => h(App, props) })
            .use(plugin)
            .directive('mask', mask)
            .mount(el);
    },

    progress: {
        color: '#00B4D8',
    },
});
