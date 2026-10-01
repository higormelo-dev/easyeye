import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';

function mountMoney(props = {}, attrs = {}) {
    return mount(MoneyInput, { props: { modelValue: null, ...props }, attrs });
}

describe('MoneyInput', () => {
    it('vazio mostra placeholder localizado e o símbolo da moeda (idioma padrão pt-BR)', () => {
        const wrapper = mountMoney();

        expect(wrapper.get('input').element.value).toBe('');
        expect(wrapper.get('input').attributes('placeholder')).toBe('0,00');
        expect(wrapper.get('.input-group-text').text()).toBe('R$');
        expect(wrapper.get('input').attributes('inputmode')).toBe('decimal');
    });

    it('digitação emite número canônico; ao sair do campo reformata', async () => {
        const wrapper = mountMoney();
        const input = wrapper.get('input');

        await input.trigger('focus');
        await input.setValue('1.234,5');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([1234.5]);
        expect(input.element.value).toBe('1.234,5');

        await input.trigger('blur');
        expect(input.element.value).toBe('1.234,50');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([1234.5]);
    });

    it('valor vindo de fora (edição) aparece formatado; limpar emite null', async () => {
        const wrapper = mountMoney({ modelValue: '250.00' });
        const input = wrapper.get('input');

        expect(input.element.value).toBe('250,00');

        await input.setValue('');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([null]);
    });

    it('outro idioma (en) usa os separadores dele', async () => {
        const wrapper = mountMoney({ locale: 'en', modelValue: 1234.5 });

        expect(wrapper.get('input').element.value).toBe('1,234.50');
    });

    it('atributos vão para o <input> (id, aria) e inválido marca aria-invalid', () => {
        const wrapper = mountMoney(
            { invalid: true },
            { id: 'amount', 'aria-describedby': 'amount-error', 'data-test': 'amount' },
        );
        const input = wrapper.get('input');

        expect(input.attributes('id')).toBe('amount');
        expect(input.attributes('aria-describedby')).toBe('amount-error');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.classes()).toContain('is-invalid');
        expect(wrapper.find('div.money-input').attributes('id')).toBeUndefined();
    });

    it('não reescreve o texto enquanto o usuário digita', async () => {
        const wrapper = mountMoney();
        const input = wrapper.get('input');

        await input.trigger('focus');
        await input.setValue('10,');
        await wrapper.setProps({ modelValue: 10 });

        expect(input.element.value).toBe('10,');
    });
});
