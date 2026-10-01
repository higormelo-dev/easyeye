import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

/**
 * SearchSelect `multiple` (características de lente do prontuário): o campo
 * não cresce a cada escolha. Em vez de um chip por opção (modo "tags", que
 * empilhava chips e aumentava a altura), mostra uma linha só — nomes
 * separados por vírgula, cortados com reticências — e a quantidade escolhida.
 * A lista completa fica no hover (title), no texto para leitores de tela e no
 * dropdown, onde as opções escolhidas continuam visíveis para desmarcar.
 */
const LENSES = [
    { id: 1, name: 'ANTIRREFLEXO' },
    { id: 2, name: 'FILTRO DE LUZ AZUL' },
    { id: 3, name: 'FOTOSSENSÍVEL (PHOTOCHROMIC)' },
    { id: 4, name: 'MONOFOCAL' },
];

function mountSelect(props = {}) {
    return mount(SearchSelect, {
        props: { options: LENSES, multiple: true, modelValue: [], ...props },
        attachTo: document.body,
    });
}

describe('SearchSelect — seleção múltipla em uma linha', () => {
    it('não renderiza um chip por opção: resume numa linha com a quantidade', () => {
        const wrapper = mountSelect({ modelValue: [1, 2, 3] });

        expect(wrapper.findAll('.multiselect-tag')).toHaveLength(0);

        const summary = wrapper.find('.multiselect-multiple-label');
        expect(summary.exists()).toBe(true);
        expect(wrapper.find('.search-select__summary-text').text())
            .toBe('ANTIRREFLEXO, FILTRO DE LUZ AZUL, FOTOSSENSÍVEL (PHOTOCHROMIC)');
        expect(wrapper.find('.search-select__count').text()).toBe('3');
        // O número é só visual: o leitor de tela já recebe a lista inteira.
        expect(wrapper.find('.search-select__count').attributes('aria-hidden')).toBe('true');

        wrapper.unmount();
    });

    it('lista completa no hover e no texto assistivo', () => {
        const wrapper = mountSelect({ modelValue: [4, 1] });
        const full    = 'MONOFOCAL, ANTIRREFLEXO';

        expect(wrapper.find('.search-select').attributes('title')).toBe(full);
        expect(wrapper.find('.multiselect-assistive-text').text()).toBe(full);

        wrapper.unmount();
    });

    it('uma opção só: sem contador; nada escolhido: placeholder e sem title', () => {
        const one = mountSelect({ modelValue: [2] });
        expect(one.find('.search-select__summary-text').text()).toBe('FILTRO DE LUZ AZUL');
        expect(one.find('.search-select__count').exists()).toBe(false);
        one.unmount();

        const none = mountSelect({ modelValue: [], placeholder: '—' });
        expect(none.find('.multiselect-multiple-label').exists()).toBe(false);
        expect(none.find('.multiselect-placeholder').text()).toBe('—');
        expect(none.find('.search-select').attributes('title')).toBeUndefined();
        none.unmount();
    });

    it('opções escolhidas continuam no dropdown (para desmarcar) e ele não fecha a cada escolha', async () => {
        const wrapper = mountSelect({ modelValue: [1] });
        const select  = wrapper.findComponent({ name: 'Multiselect' });

        expect(select.props('mode')).toBe('multiple');
        expect(select.props('hideSelected')).toBe(false);
        expect(select.props('closeOnSelect')).toBe(false);
        expect(wrapper.findAll('.multiselect-option')).toHaveLength(LENSES.length);
        expect(wrapper.find('.multiselect-option.is-selected').text()).toBe('ANTIRREFLEXO');

        wrapper.unmount();
    });

    it('v-model continua array: marcar e desmarcar emitem a lista de ids', async () => {
        const wrapper = mountSelect({ modelValue: [1] });
        const select  = wrapper.findComponent({ name: 'Multiselect' });

        select.vm.select(LENSES[1]);
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([[1, 2]]);

        await wrapper.setProps({ modelValue: [1, 2] });
        select.vm.deselect(LENSES[0]);
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([[2]]);

        wrapper.unmount();
    });

    it('seleção simples não muda: sem resumo nem title', () => {
        const wrapper = mount(SearchSelect, { props: { options: LENSES, modelValue: 2 } });
        const select  = wrapper.findComponent({ name: 'Multiselect' });

        expect(select.props('mode')).toBe('single');
        expect(select.props('closeOnSelect')).toBe(true);
        expect(wrapper.find('.multiselect-single-label').text()).toBe('FILTRO DE LUZ AZUL');
        expect(wrapper.find('.search-select').attributes('title')).toBeUndefined();

        wrapper.unmount();
    });
});
