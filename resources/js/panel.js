import './bootstrap'; // define window.axios (+ X-Requested-With) — usado pelo Assistente de IA, SearchSelect, EyeImages
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import phoneMask from './directives/phoneMask.js';

createInertiaApp({
    resolve: name =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .directive('phone-mask', phoneMask)
            .mount(el);
    },

    progress: {
        color: '#00B4D8',
        showSpinner: false,
    },
});
