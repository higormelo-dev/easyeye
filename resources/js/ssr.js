import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { MotionPlugin } from '@vueuse/motion';
import phoneMask from './directives/phoneMask.js';

const appName = 'EasyEye';

createServer(page =>
    createInertiaApp({
        page,
        render: renderToString,
        title: (title) => title ? `${appName} — ${title}` : appName,
        resolve: name =>
            resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
        setup({ App, props, plugin }) {
            return createSSRApp({ render: () => h(App, props) })
                .use(plugin)
                .use(MotionPlugin)
                .directive('phone-mask', phoneMask)
                .use(ZiggyVue, {
                    ...page.props.ziggy,
                    location: new URL(page.props.ziggy.location),
                });
        },
    }),
);
