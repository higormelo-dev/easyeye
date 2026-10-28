import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Dashboard from '@/Pages/Portal/Dashboard.vue';

vi.mock('@/Layouts/PortalLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

/**
 * EE-14: comissões pendentes/pagas usavam um helper `brl()` local com
 * 'R$ '/'pt-BR' fixos. Agora usa useLocaleFormat() — mesmo mecanismo do
 * resto do painel. Locale en é coberto no composable direto
 * (tests/JavaScript/composables/useLocaleFormatEnglish.test.js); aqui só
 * prova que a tela usa o composable (não o helper fixo removido).
 */
describe('Portal de Parceiros — Dashboard (EE-14)', () => {
    it('comissões pendentes/pagas em BRL via useLocaleFormat', () => {
        const wrapper = mount(Dashboard, {
            props: {
                metrics: {
                    total_leads: 10,
                    converted_leads: 3,
                    conversion_rate: 30,
                    pending_commissions: 1234.5,
                    paid_commissions: 0,
                },
            },
        });

        expect(wrapper.text()).toContain('R$');
        expect(wrapper.text()).toContain('1.234,50');
        wrapper.unmount();
    });

    it('sem comissões (null/undefined), mostra zero em vez de travessão', () => {
        const wrapper = mount(Dashboard, { props: { metrics: {} } });

        expect(wrapper.text()).toContain('0,00');
        expect(wrapper.text()).not.toContain('—');
        wrapper.unmount();
    });
});
