import { afterEach, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AppLayout from '@/Layouts/AppLayout.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            auth: { user: { name: 'Usuário sintético' }, entity: { name: 'Clínica sintética' } },
            locales: [
                { code: 'pt', native: 'Português' },
                { code: 'en', native: 'English' },
            ],
            t_ui: {
                open_menu: 'Open menu',
                toggle_sidebar: 'Toggle sidebar',
                close_menu: 'Close menu',
                language: 'Language',
                theme_toggle: 'Toggle theme',
                theme_dark: 'Dark mode',
                theme_light: 'Light mode',
            },
        },
    }),
    router: { on: () => () => {} },
}));
vi.mock('@/composables/usePanelTour.js', () => ({ usePanelTour: () => ({ available: false, stop() {}, start() {} }) }));
let wrapper;
afterEach(() => {
    wrapper?.unmount();
    document.documentElement.removeAttribute('data-bs-theme');
});
it('cabeçalho tem nomes traduzidos, relações e tema com estado anunciado', async () => {
    wrapper = mount(AppLayout, {
        global: { mocks: { route: globalThis.route }, stubs: { AiFloatingAssistant: true, SubscriptionBanner: true } },
    });
    for (const [selector, name] of [
        ['#mobile_btn', 'Open menu'],
        ['#toggle_btn', 'Toggle sidebar'],
        ['#toggle_btn2', 'Toggle sidebar'],
        ['.sidebar-close', 'Close menu'],
        ['[data-tour="locale"] button', 'Language'],
    ]) {
        const control = wrapper.get(selector);
        expect(control.attributes('aria-label')).toBe(name);
        expect(control.get('i').attributes('aria-hidden')).toBe('true');
    }
    const locale = wrapper.get('[data-tour="locale"] button');
    expect(locale.attributes('aria-haspopup')).toBe('true');
    expect(locale.attributes('aria-expanded')).toBe('false');
    const theme = wrapper.get('[data-tour="theme"] button');
    expect(theme.attributes('aria-label')).toBe('Toggle theme');
    expect(theme.attributes('aria-pressed')).toBe('false');
    expect(theme.attributes('title')).toBe('Dark mode');
    await theme.trigger('click');
    expect(theme.attributes('aria-pressed')).toBe('true');
    expect(theme.attributes('title')).toBe('Light mode');
});
