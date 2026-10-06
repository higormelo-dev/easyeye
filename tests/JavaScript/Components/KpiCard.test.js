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

    it('ação (action): botão SEM aria-pressed (abre algo, não é filtro) e emite click', async () => {
        const wrapper = mount(KpiCard, { props: { label: 'Sem assinatura', value: '2', action: true } });

        expect(wrapper.element.tagName).toBe('BUTTON');
        expect(wrapper.attributes('aria-pressed')).toBeUndefined();

        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    it('estático não emite click', async () => {
        const wrapper = mount(KpiCard, { props: { label: 'X', value: '0' } });

        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toBeUndefined();
    });

    it('tinted: faixa, ícone em círculo e destaque ativo na cor do tom (tokens do tema)', () => {
        const props = {
            label: 'Em atraso',
            value: '3',
            icon: 'ti ti-alert-triangle',
            tone: 'warning',
            toggle: true,
            tinted: true,
        };
        const idle = mount(KpiCard, { props });
        const active = mount(KpiCard, { props: { ...props, active: true } });

        expect(idle.classes()).toEqual(expect.arrayContaining(['kpi-card--tinted', 'kpi-card--tone-warning']));
        expect(idle.attributes('style')).toContain('--kpi-tone-rgb: var(--warning-rgb)');
        expect(idle.get('.kpi-card__bubble').find('i.ti-alert-triangle').exists()).toBe(true);
        expect(idle.findAll('i.ti-alert-triangle')).toHaveLength(1);
        expect(idle.classes()).not.toContain('kpi-card--tinted-active');

        expect(active.classes()).toContain('kpi-card--tinted-active');
        expect(active.classes()).not.toContain('kpi-card--active');
    });

    it('tinted aceita cores extras do tema (ex.: orange); sem tinted elas caem no neutro', () => {
        const tinted = mount(KpiCard, { props: { label: 'X', value: '1', tone: 'orange', tinted: true } });
        const plain = mount(KpiCard, { props: { label: 'X', value: '1', tone: 'orange' } });

        expect(tinted.attributes('style')).toContain('--kpi-tone-rgb: var(--orange-rgb)');
        expect(plain.classes()).toContain('border-secondary');
    });

    it('sem tinted o visual padrão não muda (outras telas)', () => {
        const wrapper = mount(KpiCard, {
            props: { label: 'X', value: '1', icon: 'ti ti-cash', tone: 'success', toggle: true, active: true },
        });

        expect(wrapper.find('.kpi-card__bubble').exists()).toBe(false);
        expect(wrapper.classes()).toContain('kpi-card--active');
        expect(wrapper.attributes('style')).toBeUndefined();
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
