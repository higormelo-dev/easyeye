import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

/**
 * Onda 4, C2 — SearchSelect com busca remota debounced.
 *
 * Quando `remoteSearchUrl` é passado, o componente faz GET no servidor depois
 * de `remoteMinChars` (default 2) com debounce de 300ms, e substitui options
 * por `data.data`.
 */
// Helper para esperar X ms reais (debounce 300ms é compatível com testes).
const waitMs = (ms) => new Promise((r) => setTimeout(r, ms));

describe('SearchSelect — busca remota', () => {
    beforeEach(() => {
        globalThis.window = globalThis.window ?? {};
        globalThis.window.axios = {
            get: vi.fn(() => Promise.resolve({
                data: { data: [
                    { id: 'p-1', label: 'Maria Silva',  sub_label: 'PAC-001' },
                    { id: 'p-2', label: 'Maria Santos', sub_label: 'PAC-002' },
                ] },
            })),
        };
    });

    function mountSelect(props = {}) {
        return mount(SearchSelect, {
            props: {
                modelValue: null,
                options:    [],
                valueKey:   'id',
                labelKey:   'label',
                remoteSearchUrl: '/_routes/search?q=__Q__',
                ...props,
            },
        });
    }

    it('chama axios.get com __Q__ substituído quando digita >= remoteMinChars', async () => {
        const wrapper = mountSelect();

        // Simula digitação no campo via método onSearchChange (compatível com Multiselect)
        wrapper.vm.onSearchChange('mar');

        // Avança o debounce de 300ms
        await waitMs(350);
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledTimes(1);
        const url = window.axios.get.mock.calls[0][0];
        expect(url).toContain('q=mar');
    });

    it('não dispara busca abaixo de remoteMinChars', async () => {
        const wrapper = mountSelect({ remoteMinChars: 3 });

        wrapper.vm.onSearchChange('ma');
        await waitMs(350);
        await flushPromises();

        expect(window.axios.get).not.toHaveBeenCalled();
    });

    it('debounce: duas digitações rápidas geram 1 chamada', async () => {
        const wrapper = mountSelect();

        wrapper.vm.onSearchChange('ma');
        await waitMs(100);
        wrapper.vm.onSearchChange('mari');
        await waitMs(350);
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledTimes(1);
        expect(window.axios.get.mock.calls[0][0]).toContain('q=mari');
    });

    it('substitui as options pelas vindas do servidor', async () => {
        const wrapper = mountSelect();

        wrapper.vm.onSearchChange('mar');
        await waitMs(350);
        await flushPromises();

        // effectiveOptions agora vem do remote
        expect(wrapper.vm.effectiveOptions.length).toBe(2);
        expect(wrapper.vm.effectiveOptions[0].label).toBe('Maria Silva');
    });

    it('sem remoteSearchUrl mantém comportamento legado (options local)', async () => {
        const wrapper = mountSelect({
            remoteSearchUrl: '',
            options: [{ id: 'a', label: 'A' }, { id: 'b', label: 'B' }],
        });

        wrapper.vm.onSearchChange('a');
        await waitMs(350);
        await flushPromises();

        expect(window.axios.get).not.toHaveBeenCalled();
        expect(wrapper.vm.effectiveOptions.length).toBe(2);
    });
});
