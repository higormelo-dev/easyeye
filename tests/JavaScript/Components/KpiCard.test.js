import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import KpiCard from '@/Components/Panel/KpiCard.vue';

describe('KpiCard', () => {
    it('estático: rótulo, valor em text-body e cor só no ícone/borda', () => {
        const wrapper = mount(KpiCard, {
            props: { label: 'Recebido', value: 'R$ 10,00', icon: 'ti ti-cash', tone: 'success', testId: 'received' },
        });

        expect(wrapper.element.tagName).toBe('DIV');
        expect(wrapper.classes()).toContain('border-success');
        expect(wrapper.get('[data-test="kpi-received"]').classes()).toContain('text-body');
        expect(wrapper.get('i.ti-cash').classes()).toContain('text-success');
        expect(wrapper.text()).toContain('Recebido');
    });

    it('dica aparece como title e para leitor de tela', () => {
        const wrapper = mount(KpiCard, { props: { label: 'Saldo', value: '1', hint: 'Inclui pendentes' } });

        expect(wrapper.attributes('title')).toBe('Inclui pendentes');
        expect(wrapper.get('.visually-hidden').text()).toBe('Inclui pendentes');
    });

    it('filtro (toggle): botão com aria-pressed e emite click', async () => {
        const wrapper = mount(KpiCard, { props: { label: 'Vencidas', value: '3', toggle: true, active: true } });

        expect(wrapper.element.tagName).toBe('BUTTON');
        expect(wrapper.attributes('type')).toBe('button');
        expect(wrapper.attributes('aria-pressed')).toBe('true');

        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    it('tom desconhecido cai no neutro; carregando mostra placeholder sem valor', () => {
        const wrapper = mount(KpiCard, {
            props: { label: 'X', value: '9', tone: 'rainbow', loading: true, testId: 'x' },
        });

        expect(wrapper.classes()).toContain('border-secondary');
        expect(wrapper.find('[data-test="kpi-x"]').exists()).toBe(false);
        expect(wrapper.find('.placeholder').exists()).toBe(true);
    });

    it('não emite click quando não é filtro', async () => {
        const wrapper = mount(KpiCard, { props: { label: 'X', value: '1' } });

        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toBeUndefined();
    });
});
