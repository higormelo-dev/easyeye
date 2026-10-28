import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import News from '@/Pages/Panel/News.vue';
vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn(), reload: vi.fn() } }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
describe('News', () => {
    it('renderiza HTML do servidor e marca como visto uma vez', () => {
        const wrapper = mount(News, {
            props: {
                version: '1.3.0',
                html: '<h2>Comercial</h2>',
                seenUrl: '/visto',
                t: { title: 'Novidades', subtitle: 'Atualizações', empty: 'Vazio' },
            },
        });
        expect(wrapper.html()).toContain('<h2>Comercial</h2>');
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith('/visto', {}, expect.any(Object));
    });
});
