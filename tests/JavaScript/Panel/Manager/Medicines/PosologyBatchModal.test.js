import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axios from 'axios';
import PosologyBatchModal from '@/Pages/Panel/Manager/Medicines/PosologyBatchModal.vue';

/**
 * Prévia/confirmação do lote "Gerar posologia com IA": usa os filtros
 * aplicados, mostra contagens, grupos (= chamadas), teto e custo; só envia
 * ao confirmar, com a IA escolhida.
 */
vi.mock('axios', () => ({ default: { get: vi.fn() } }));
const form = vi.hoisted(() => ({ current: null, transformed: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        usePage: () => ({ props: { locale: 'pt_BR' } }),
        useForm: (data) => {
            form.current = reactive({
                ...data,
                errors: {},
                processing: false,
                clearErrors: vi.fn(),
                transform(callback) {
                    form.transformed = callback;
                    return this;
                },
                post: vi.fn(),
            });
            return form.current;
        },
    };
});
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open'],
        template: '<div v-if="open"><slot name="header" /><slot /><slot name="footer" /></div>',
    },
}));

const t = new Proxy(
    {
        batch_cost_value: ':usd (≈ :brl)',
        batch_cap_notice: 'Teto de :cap chamadas: :remaining grupo(s) ficam para depois.',
        batch_cost_unavailable: 'Sem preço (serão :calls chamadas).',
        batch_filter_search: 'Busca: ":term"',
        source_cmed: 'CMED/Anvisa',
        filter_ophthalmic: 'Só oftálmicos',
    },
    { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
);

const ONE_AI = [{ code: 'openai', label: 'OpenAI', model: 'gpt-4o' }];
const MANY_AI = [
    { code: 'openai', label: 'OpenAI', model: 'gpt-4o' },
    { code: 'gemini', label: 'Google (Gemini)', model: 'gemini-2.5-flash' },
];

const preview = {
    medicines: 1234,
    groups: 350,
    batch_groups: 200,
    batch_medicines: 900,
    remaining_groups: 150,
    cap: 200,
    estimates: { openai: { usd: 0.1234, brl: 0.68 }, gemini: { usd: null, brl: null } },
    usd_brl: { rate: 5.5, is_fallback: false },
    running: null,
};

async function mountModal(props = {}) {
    const wrapper = mount(PosologyBatchModal, {
        props: { open: false, filters: {}, aiProviders: ONE_AI, t, ...props },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
    return wrapper;
}

const confirmButton = (wrapper) => wrapper.find('[data-test="confirm"]');

describe('PosologyBatchModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axios.get.mockResolvedValue({ data: preview });
        try {
            localStorage.removeItem('mgr_medicines_ai_provider');
        } catch {
            // sem armazenamento
        }
    });

    it('pede a prévia com os filtros aplicados (sem vazios) e mostra os chips', async () => {
        const wrapper = await mountModal({
            filters: { search: 'pred', source: 'cmed', status: '', ophthalmic: true, sort: 'name', direction: 'asc' },
        });

        expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('posology-batches.preview'), {
            params: { search: 'pred', source: 'cmed', ophthalmic: 1, sort: 'name', direction: 'asc' },
        });
        expect(wrapper.text()).toContain('Busca: "pred"');
        expect(wrapper.text()).toContain('CMED/Anvisa');
        expect(wrapper.text()).toContain('Só oftálmicos');
    });

    it('mostra contagens localizadas, teto e custo estimado em US$ e R$', async () => {
        const wrapper = await mountModal();

        expect(wrapper.find('[data-stat="medicines"]').text()).toBe('1.234');
        expect(wrapper.find('[data-stat="groups"]').text()).toBe('350');
        expect(wrapper.find('[data-stat="calls"]').text()).toBe('200');
        expect(wrapper.find('[data-stat="batch-medicines"]').text()).toBe('900');
        expect(wrapper.find('[data-test="cap"]').text()).toContain('Teto de 200 chamadas: 150 grupo(s)');

        const cost = wrapper.find('[data-test="cost"]').text();
        expect(cost).toContain('US$');
        expect(cost).toContain('0,1234');
        expect(cost).toContain('R$');
        expect(cost).toContain('0,68');
    });

    it('várias IAs: escolhe a IA (lembra a última) e o custo acompanha; sem preço mostra só as chamadas', async () => {
        localStorage.setItem('mgr_medicines_ai_provider', 'gemini');
        const wrapper = await mountModal({ aiProviders: MANY_AI });
        const select = wrapper.find('select#batch-provider');

        expect(select.element.value).toBe('gemini');
        expect(wrapper.find('[data-test="cost"]').text()).toContain('Sem preço (serão 200 chamadas).');

        await select.setValue('openai');
        expect(wrapper.find('[data-test="cost"]').text()).toContain('0,1234');
    });

    it('confirmar envia os filtros + a IA escolhida e avisa a página', async () => {
        const wrapper = await mountModal({ aiProviders: MANY_AI, filters: { source: 'cmed', ophthalmic: true } });
        await wrapper.find('select#batch-provider').setValue('gemini');

        await confirmButton(wrapper).trigger('click');

        expect(form.current.post).toHaveBeenCalledWith(
            expect.stringContaining('posology-batches.store'),
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(form.transformed({ provider: 'gemini' })).toEqual({ source: 'cmed', ophthalmic: 1, provider: 'gemini' });
        expect(localStorage.getItem('mgr_medicines_ai_provider')).toBe('gemini');

        form.current.post.mock.calls[0][1].onSuccess();
        expect(wrapper.emitted('started')).toHaveLength(1);
    });

    it('nada sem posologia no filtro: avisa e não deixa confirmar', async () => {
        axios.get.mockResolvedValue({
            data: { ...preview, medicines: 0, groups: 0, batch_groups: 0, remaining_groups: 0 },
        });
        const wrapper = await mountModal();

        expect(wrapper.find('[data-test="nothing"]').exists()).toBe(true);
        expect(confirmButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('já há lote rodando: fecha e entrega o progresso à página', async () => {
        const running = { id: 'b-1', progress: 30, is_done: false };
        axios.get.mockResolvedValue({ data: { ...preview, running } });
        const wrapper = await mountModal();

        expect(wrapper.emitted('running')[0]).toEqual([running]);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('erro do servidor ao iniciar aparece no modal', async () => {
        const wrapper = await mountModal();
        form.current.errors = { batch: 'Já existe um lote em andamento.' };
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toContain('Já existe um lote em andamento.');
    });

    it('falha ao calcular a prévia: mensagem e botão para tentar de novo', async () => {
        axios.get.mockRejectedValueOnce({ response: { status: 500 } });
        const wrapper = await mountModal();

        expect(wrapper.text()).toContain('batch_preview_failed');
        expect(confirmButton(wrapper).attributes('disabled')).toBeDefined();

        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('import_refresh_status'))
            .trigger('click');
        await flushPromises();
        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-stat="calls"]').text()).toBe('200');
    });
});
