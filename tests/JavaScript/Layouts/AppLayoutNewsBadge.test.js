import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const initialRoute = globalThis.route;
const page = reactive({
    props: {
        auth: { entity: { name: 'Clínica Exemplo', logo_url: null, entities: [] }, user: {} },
        nav: [
            {
                key: 'news',
                label: 'Novidades',
                icon: 'ti ti-sparkles',
                route: 'panel.news.index',
                match: ['panel.news.*'],
                badge: 'novo',
            },
        ],
        locales: [],
        flash: {},
        aiAssistant: { enabled: false },
        tour: {},
    },
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: { post: vi.fn(), delete: vi.fn(), visit: vi.fn() },
}));
vi.mock('@/composables/usePanelTour.js', () => ({
    usePanelTour: () => ({ available: false, autoStart: vi.fn(), stop: vi.fn() }),
}));

describe('AppLayout — novidade no menu', () => {
    afterEach(() => {
        if (initialRoute === undefined) delete globalThis.route;
        else globalThis.route = initialRoute;
    });

    it('mostra o badge da navegação sem depender de texto fixo no layout', () => {
        globalThis.route = Object.assign(
            vi.fn(() => '/painel/novidades'),
            { current: vi.fn(() => 'panel.dashboard') },
        );

        const wrapper = mount(AppLayout, {
            props: { title: 'Painel' },
            global: {
                stubs: {
                    AiFloatingAssistant: true,
                    SubscriptionBanner: true,
                    SupportButton: true,
                },
            },
        });

        const link = wrapper.find('[data-tour="nav-news"]');
        expect(link.text()).toContain('Novidades');
        expect(link.find('.badge').text()).toBe('novo');
        expect(link.attributes('href')).toBe('/painel/novidades');

        wrapper.unmount();
    });
});
