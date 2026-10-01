import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import ProcedurePricesIndex from '@/Pages/Panel/Financial/ProcedurePrices/Index.vue';

/**
 * Tabela de Preços com o SearchSelect REAL (@vueform/multiselect): a lib guarda o
 * item escolhido internamente e só ressincroniza quando o v-model muda. Ao cancelar
 * a troca de convênio o v-model não muda — o seletor ficava mostrando o convênio
 * novo enquanto a grade e o Salvar continuavam no anterior.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    const pageProps = reactive({ locale: 'pt_BR', flash: {}, errors: {} });

    return {
        usePage: () => ({ props: pageProps }),
        router: { get: vi.fn(), post: vi.fn(), on: vi.fn(() => vi.fn()) },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({ default: { template: '<div><slot name="actions" /></div>' } }));
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open', 'size'],
        emits: ['close'],
        template: '<div v-if="open" class="modal-stub"><slot /><slot name="footer" /></div>',
    },
}));

const covenants = [
    { id: 'c1', name: 'Particular', tiss: false },
    { id: 'c2', name: 'Unimed', tiss: true },
];
const procedures = [{ id: 'p1', code: '10101012', name: 'Consulta' }];

let wrapper;

beforeEach(() => {
    vi.mocked(router.get).mockClear();
    vi.mocked(router.post).mockClear();
});
afterEach(() => {
    wrapper?.unmount();
});

describe('Financial/ProcedurePrices/Index — seletor de convênio real', () => {
    it('"Continuar editando" faz o seletor voltar a mostrar o convênio da grade', async () => {
        wrapper = mount(ProcedurePricesIndex, {
            attachTo: document.body,
            props: { covenants, procedures, selectedCovenantId: 'c1', prices: {}, t: {} },
        });

        await wrapper.find('[data-test="price-row"] [data-test="price-input"]').setValue('80');

        wrapper.findComponent({ name: 'Multiselect' }).vm.select(covenants[1]);
        await nextTick();
        expect(wrapper.find('.multiselect-single-label').text()).toBe('Unimed');
        expect(wrapper.find('.modal-stub').exists()).toBe(true);

        await wrapper.find('[data-test="discard-cancel"]').trigger('click');
        await nextTick();

        expect(wrapper.find('.multiselect-single-label').text()).toBe('Particular');
        expect(router.get).not.toHaveBeenCalled();

        await wrapper.find('[data-test="save"]').trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            expect.any(String),
            // Particular não tem operadora TISS: nunca cobra por guia.
            { covenant_id: 'c1', items: [{ procedure_id: 'p1', price: 80, charging: false }] },
            expect.any(Object),
        );
    });
});
