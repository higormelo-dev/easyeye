import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PeriodFilter from '@/Components/Panel/PeriodFilter.vue';

const TODAY = '2026-03-10';

function mountFilter(props = {}) {
    return mount(PeriodFilter, {
        props: { from: '2026-03-01', to: '2026-03-10', today: TODAY, ...props },
        attachTo: document.body,
    });
}

describe('PeriodFilter', () => {
    it('mostra o atalho que corresponde ao intervalo atual', () => {
        const wrapper = mountFilter();

        expect(wrapper.get('[data-test="period-preset"]').element.value).toBe('month');
        wrapper.unmount();
    });

    it('atalho aplica na hora e emite o intervalo', async () => {
        const wrapper = mountFilter();

        await wrapper.get('[data-test="period-preset"]').setValue('last_month');

        expect(wrapper.emitted('update:from').at(-1)).toEqual(['2026-02-01']);
        expect(wrapper.emitted('update:to').at(-1)).toEqual(['2026-02-28']);
        expect(wrapper.emitted('change').at(-1)).toEqual([{ from: '2026-02-01', to: '2026-02-28', preset: 'last_month' }]);
        wrapper.unmount();
    });

    it('data digitada válida aplica no change', async () => {
        const wrapper = mountFilter();

        await wrapper.get('[data-test="period-from"]').setValue('2026-03-05');

        expect(wrapper.emitted('change').at(-1)).toEqual([{ from: '2026-03-05', to: '2026-03-10', preset: 'custom' }]);
        wrapper.unmount();
    });

    it('início depois do fim: avisa com role=alert, marca aria-invalid e não emite', async () => {
        const wrapper = mountFilter({ labels: { invalid_range: 'Início depois do fim.' } });

        await wrapper.get('[data-test="period-from"]').setValue('2026-03-20');

        const error = wrapper.get('[data-test="period-error"]');
        expect(error.attributes('role')).toBe('alert');
        expect(error.text()).toContain('Início depois do fim.');
        expect(wrapper.get('[data-test="period-from"]').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('[data-test="period-from"]').attributes('aria-describedby')).toBe(error.attributes('id'));
        expect(wrapper.emitted('change')).toBeUndefined();
        expect(wrapper.emitted('invalid').at(-1)).toEqual([{ reason: 'invalid_range', from: '2026-03-20', to: '2026-03-10' }]);
        wrapper.unmount();
    });

    it('respeita o limite máximo (ex.: fechamento não aceita futuro)', async () => {
        const wrapper = mountFilter({ max: TODAY, labels: { after_max: 'Máximo :date.' } });

        await wrapper.get('[data-test="period-to"]').setValue('2026-03-15');

        expect(wrapper.get('[data-test="period-error"]').text()).toContain('Máximo 10/03/2026.');
        expect(wrapper.get('[data-test="period-to"]').attributes('max')).toBe(TODAY);
        expect(wrapper.emitted('change')).toBeUndefined();
        wrapper.unmount();
    });

    it('textos vêm do chamador (idioma) e rótulos ficam associados aos campos', () => {
        const wrapper = mountFilter({
            labels: { label: 'Period', from: 'From', to: 'To', presets: { month: 'This month', custom: 'Custom' } },
        });

        const fromInput = wrapper.get('[data-test="period-from"]');
        expect(wrapper.get(`label[for="${fromInput.attributes('id')}"]`).text()).toBe('From');
        expect(wrapper.get('[data-test="period-preset"] option[value="month"]').text()).toBe('This month');
        expect(wrapper.get('[role="group"]').attributes('aria-label')).toBe('Period');
        wrapper.unmount();
    });

    it('modo compacto esconde os rótulos só visualmente', () => {
        const wrapper = mountFilter({ compact: true });

        expect(wrapper.findAll('label').every((label) => label.classes('visually-hidden'))).toBe(true);
        wrapper.unmount();
    });

    it('ano sendo digitado (Chrome: change a cada dígito) não aplica nem pisca erro; ao sair do campo vira erro', async () => {
        const wrapper = mountFilter();
        const from    = wrapper.get('[data-test="period-from"]');

        for (const partial of ['0002-03-01', '0020-03-01', '0202-03-01']) {
            await from.setValue(partial);
        }

        expect(wrapper.emitted('change')).toBeUndefined();
        expect(wrapper.find('[data-test="period-error"]').exists()).toBe(false);

        await from.setValue('2026-03-02');
        expect(wrapper.emitted('change')).toHaveLength(1);

        await from.setValue('0202-03-01');
        await from.trigger('blur');
        expect(wrapper.find('[data-test="period-error"]').exists()).toBe(true);
        expect(wrapper.emitted('invalid').at(-1)[0].reason).toBe('invalid_date');
        wrapper.unmount();
    });

    it('ano anterior a 1900 é inválido (mesma regra do backend) e o campo declara o mínimo', async () => {
        const wrapper = mountFilter();

        expect(wrapper.get('[data-test="period-from"]').attributes('min')).toBe('1900-01-01');

        await wrapper.get('[data-test="period-from"]').setValue('1899-12-31');

        expect(wrapper.emitted('change')).toBeUndefined();
        expect(wrapper.emitted('invalid').at(-1)[0].reason).toBe('invalid_date');
        wrapper.unmount();
    });

    it('sincroniza quando o período muda por fora (ex.: limpar filtros)', async () => {
        const wrapper = mountFilter();

        await wrapper.setProps({ from: '2026-03-10', to: '2026-03-10' });

        expect(wrapper.get('[data-test="period-preset"]').element.value).toBe('today');
        expect(wrapper.get('[data-test="period-from"]').element.value).toBe('2026-03-10');
        wrapper.unmount();
    });
});
