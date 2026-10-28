import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import ModelPriceDrawer from '@/Pages/Panel/Manager/AiProviders/ModelPriceDrawer.vue';
import { plain, priceRow } from './aiProvidersFixtures';

/** Detalhes do modelo: preços localizados, raciocínio "igual à saída", trava e situação. */
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { locale: 'pt_BR' } }) }));
vi.mock('@/Components/Panel/OffcanvasPanel.vue', () => ({
    default: {
        props: ['open'],
        emits: ['close'],
        template: `<aside v-if="open"><slot name="header" /><slot /><footer><slot name="footer" /></footer></aside>`,
    },
}));

describe('ModelPriceDrawer', () => {
    it('mostra preços, raciocínio igual à saída, trava, conferência e aviso de descontinuado', async () => {
        const wrapper = mount(ModelPriceDrawer, {
            props: {
                open: true,
                price: priceRow({ price_locked: true, synced_at: '03/10/2026 11:06', unlisted_at: '02/10/2026' }),
                t: { model_unlisted: 'Não oferecido desde :date.' },
            },
        });

        const text = plain(wrapper);
        expect(text).toContain('US$ 2,50');
        expect(text).toContain('Igual à saída');
        expect(text).toContain('Sim');
        expect(text).toContain('03/10/2026 11:06');
        expect(text).toContain('Não oferecido desde 02/10/2026.');

        await wrapper.find('[data-price-drawer-edit]').trigger('click');
        expect(wrapper.emitted('edit')[0][0].id).toBe('p1');
    });
});
