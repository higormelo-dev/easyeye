import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
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
            get: vi.fn(() =>
                Promise.resolve({
                    data: {
                        data: [
                            { id: 'p-1', label: 'Maria Silva', sub_label: 'PAC-001' },
                            { id: 'p-2', label: 'Maria Santos', sub_label: 'PAC-002' },
                        ],
                    },
                }),
            ),
        };
    });

    function mountSelect(props = {}) {
        return mount(SearchSelect, {
            props: {
                modelValue: null,
                options: [],
                valueKey: 'id',
                labelKey: 'label',
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
            options: [
                { id: 'a', label: 'A' },
                { id: 'b', label: 'B' },
            ],
        });

        wrapper.vm.onSearchChange('a');
        await waitMs(350);
        await flushPromises();

        expect(window.axios.get).not.toHaveBeenCalled();
        expect(wrapper.vm.effectiveOptions.length).toBe(2);
    });
});

describe('SearchSelect — estados e ordem das respostas', () => {
    let wrapper;
    const seed = { id: 'seed', label: 'Opção inicial' };
    async function render(extra = {}) {
        wrapper = mount(SearchSelect, {
            props: {
                modelValue: null,
                options: [seed],
                labelKey: 'label',
                remoteSearchUrl: '/search?q=__Q__',
                ...extra,
            },
            attachTo: document.body,
        });
        await wrapper.find('input').trigger('focus');
        return wrapper;
    }
    async function search(term) {
        wrapper.vm.onSearchChange(term);
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
    }
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        window.axios = { get: vi.fn() };
    });
    afterEach(() => {
        wrapper?.unmount();
        vi.useRealTimers();
    });
    it('aborta e ignora resposta antiga desde o debounce da nova busca', async () => {
        let old;
        window.axios.get
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        old = done;
                    }),
            )
            .mockResolvedValueOnce({ data: { data: [{ id: 'new', label: 'Atual' }] } });
        await render();
        await search('old');
        const signal = window.axios.get.mock.calls[0][1].signal;
        wrapper.vm.onSearchChange('new');
        expect(signal.aborted).toBe(true);
        old({ data: { data: [{ id: 'old', label: 'ANTIGA' }] } });
        await flushPromises();
        expect(wrapper.vm.effectiveOptions).toEqual([]);
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
        expect(wrapper.vm.effectiveOptions.map((o) => o.label)).toEqual(['Atual']);
    });
    it('erro permite retry, vazio não volta ao seed e limpar restaura opções iniciais', async () => {
        window.axios.get.mockRejectedValueOnce(new Error()).mockResolvedValueOnce({ data: { data: [] } });
        await render();
        await search('none');
        expect(wrapper.vm.remoteState).toBe('error');
        expect(wrapper.vm.effectiveOptions).toEqual([]);
        expect(wrapper.find('[role="alert"]').text()).toContain('Não foi possível');
        await wrapper.find('[role="alert"] button').trigger('click');
        await flushPromises();
        expect(window.axios.get).toHaveBeenCalledTimes(2);
        expect(wrapper.vm.remoteState).toBe('empty');
        expect(wrapper.vm.effectiveOptions).toEqual([]);
        wrapper.vm.onSearchChange('');
        await flushPromises();
        expect(wrapper.vm.effectiveOptions).toEqual([seed]);
    });
    it('selecionada preserva rótulo durante erro e expõe retry antes da lista', async () => {
        window.axios.get.mockRejectedValueOnce(new Error());
        await render({ modelValue: 'seed', options: [seed, { id: 'other', label: 'Outra opção inicial' }] });
        await search('none');
        expect(wrapper.vm.effectiveOptions).toEqual([seed]);
        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
        expect(wrapper.props('modelValue')).toBe('seed');
    });
    it('id, aria e focus apontam para o input interno e loading é anunciado', async () => {
        window.axios.get.mockImplementation(() => new Promise(() => {}));
        await render({ id: 'real-control', ariaLabelledby: 'label-id', ariaDescribedby: 'error-id', invalid: true });
        wrapper.vm.focus();
        const input = wrapper.get('#real-control');
        expect(document.activeElement).toBe(input.element);
        expect(input.attributes('aria-labelledby').split(' ')).toContain('label-id');
        expect(input.attributes('aria-describedby')).toBe('error-id');
        expect(input.attributes('aria-invalid')).toBe('true');
        await search('load');
        expect(input.attributes('aria-busy')).toBe('true');
        expect(wrapper.text()).toContain('Buscando');
    });
    it('unmount cancela timer e requisição, mesmo se o transporte ignorar signal', async () => {
        let finish;
        window.axios.get.mockImplementation(
            () =>
                new Promise((done) => {
                    finish = done;
                }),
        );
        await render();
        wrapper.vm.onSearchChange('pending');
        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(400);
        expect(window.axios.get).not.toHaveBeenCalled();
        await render();
        await search('active');
        const signal = window.axios.get.mock.calls[0][1].signal;
        wrapper.unmount();
        expect(signal.aborted).toBe(true);
        finish({ data: { data: [{ id: 'late', label: 'Tardio' }] } });
        await flushPromises();
        expect(wrapper.vm.effectiveOptions).not.toContainEqual(expect.objectContaining({ id: 'late' }));
    });
});
