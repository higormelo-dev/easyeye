import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axios from 'axios';
import MedicineFormModal from '@/Pages/Panel/Manager/Medicines/MedicineFormModal.vue';

/**
 * Formulário do catálogo de medicamentos: "Gerar com IA" é opcional, só
 * preenche os campos de posologia (com desfazer) e nunca salva sozinho.
 */
vi.mock('axios', () => ({ default: { post: vi.fn() } }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        useForm: (data) =>
            reactive({
                ...data,
                errors: {},
                processing: false,
                clearErrors: vi.fn(),
                transform() {
                    return this;
                },
                post: vi.fn(),
                put: vi.fn(),
            }),
    };
});
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open'],
        template: '<div v-if="open"><slot name="header" /><slot /><slot name="footer" /></div>',
    },
}));

const t = new Proxy({}, { get: (o, k) => (typeof k === 'string' ? k : o[k]) });
const cmed = { id: 'cmed-1', name: 'PREDOPTIC', source: 'cmed', dosage: '', frequency: '' };
const suggestion = {
    dosage: '1 gota no olho afetado',
    frequency: 'de 6/6h',
    duration: '7 dias',
    instructions: 'Agitar antes de usar.',
    note: 'Dose varia conforme a indicação.',
};

const ONE_AI = [{ code: 'openai', label: 'OpenAI', model: 'gpt-4o' }];
const MANY_AI = [
    { code: 'openai', label: 'OpenAI', model: 'gpt-4o' },
    { code: 'gemini', label: 'Google (Gemini)', model: 'gemini-2.5-pro' },
];

async function mountForm(props = {}) {
    const wrapper = mount(MedicineFormModal, {
        props: { open: false, medicine: cmed, aiProviders: ONE_AI, t, ...props },
    });
    await wrapper.setProps({ open: true }); // o watch de "open" carrega o item
    return wrapper;
}

const aiButton = (wrapper) => wrapper.findAll('button').find((b) => b.text().includes('ai_generate'));
const value = (wrapper, id) => wrapper.find(`#${id}`).element.value;

describe('Manager → Medicamentos: posologia sugerida por IA', () => {
    beforeEach(() => vi.clearAllMocks());

    it('sem provedor de IA configurado o botão não aparece', async () => {
        expect(aiButton(await mountForm({ aiProviders: [] }))).toBeUndefined();
    });

    it('cadastro novo só habilita com o nome preenchido', async () => {
        const wrapper = await mountForm({ medicine: null });
        expect(aiButton(wrapper).attributes('disabled')).toBeDefined();

        await wrapper.find('#med-name').setValue('ACETAZOLAMIDA');
        expect(aiButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('item da CMED envia só o id, preenche os campos e mostra o aviso com a observação', async () => {
        axios.post.mockResolvedValue({ data: { suggestion } });
        const wrapper = await mountForm();

        await aiButton(wrapper).trigger('click');
        await flushPromises();

        // Uma IA configurada: gera direto com ela (sem perguntar).
        expect(axios.post).toHaveBeenCalledWith(expect.any(String), { provider: 'openai', medicine_id: 'cmed-1' });
        expect(value(wrapper, 'med-dosage')).toBe('1 gota no olho afetado');
        expect(value(wrapper, 'med-frequency')).toBe('de 6/6h');
        expect(value(wrapper, 'med-duration')).toBe('7 dias');
        expect(value(wrapper, 'med-instructions')).toBe('Agitar antes de usar.');
        expect(wrapper.text()).toContain('ai_filled');
        expect(wrapper.text()).toContain('Dose varia conforme a indicação.');
    });

    it('desfazer devolve o que estava digitado antes', async () => {
        axios.post.mockResolvedValue({ data: { suggestion } });
        const wrapper = await mountForm();
        await wrapper.find('#med-dosage').setValue('2 gotas');

        await aiButton(wrapper).trigger('click');
        await flushPromises();
        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('ai_undo'))
            .trigger('click');

        expect(value(wrapper, 'med-dosage')).toBe('2 gotas');
        expect(value(wrapper, 'med-frequency')).toBe('');
        expect(wrapper.text()).not.toContain('ai_filled');
    });

    it('falha mostra a mensagem do servidor; limite de uso tem mensagem própria', async () => {
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { message: 'Preencha manualmente.' } } });
        const wrapper = await mountForm();

        await aiButton(wrapper).trigger('click');
        await flushPromises();
        expect(wrapper.find('[role="alert"]').text()).toBe('Preencha manualmente.');
        expect(value(wrapper, 'med-dosage')).toBe('');

        axios.post.mockRejectedValueOnce({ response: { status: 429, data: {} } });
        await aiButton(wrapper).trigger('click');
        await flushPromises();
        expect(wrapper.find('[role="alert"]').text()).toBe('ai_rate_limited');
    });

    it('resposta que chega depois de trocar de item é descartada', async () => {
        let resolve;
        axios.post.mockReturnValue(new Promise((r) => (resolve = r)));
        const wrapper = await mountForm();

        await aiButton(wrapper).trigger('click');
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ medicine: { ...cmed, id: 'cmed-2', name: 'OUTRO' }, open: true });
        resolve({ data: { suggestion } });
        await flushPromises();

        expect(value(wrapper, 'med-dosage')).toBe('');
    });

    it('enquanto gera, campos de posologia e o salvar ficam bloqueados', async () => {
        axios.post.mockReturnValue(new Promise(() => {}));
        const wrapper = await mountForm();

        await aiButton(wrapper).trigger('click');

        expect(wrapper.find('#med-dosage').attributes('disabled')).toBeDefined();
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('ai_generating');
    });

    describe('com mais de uma IA configurada', () => {
        beforeEach(() => localStorage.removeItem('mgr_medicines_ai_provider'));

        it('o botão pergunta qual IA usar e só gera depois da escolha', async () => {
            axios.post.mockResolvedValue({
                data: { suggestion: { ...suggestion, provider_label: 'Google (Gemini)' } },
            });
            const wrapper = await mountForm({ aiProviders: MANY_AI });

            await aiButton(wrapper).trigger('click');
            expect(axios.post).not.toHaveBeenCalled();

            const menu = wrapper.find('[role="menu"]');
            expect(menu.exists()).toBe(true);
            expect(aiButton(wrapper).attributes('aria-expanded')).toBe('true');
            expect(menu.findAll('[role="menuitem"]').map((i) => i.text())).toEqual([
                'OpenAI · gpt-4o',
                'Google (Gemini) · gemini-2.5-pro',
            ]);

            await menu.find('[data-provider="gemini"]').trigger('click');
            await flushPromises();

            expect(axios.post).toHaveBeenCalledWith(expect.any(String), { provider: 'gemini', medicine_id: 'cmed-1' });
            expect(wrapper.find('[role="menu"]').exists()).toBe(false);
            // Aviso diz qual IA gerou.
            expect(wrapper.text()).toContain('ai_filled_by');
        });

        it('lembra a última IA escolhida (marcada no menu)', async () => {
            axios.post.mockResolvedValue({ data: { suggestion } });
            const first = await mountForm({ aiProviders: MANY_AI });
            await aiButton(first).trigger('click');
            await first.find('[data-provider="gemini"]').trigger('click');
            await flushPromises();

            const second = await mountForm({ aiProviders: MANY_AI });
            await aiButton(second).trigger('click');

            expect(second.find('[data-provider="gemini"]').text()).toContain('ai_last_used');
            expect(second.find('[data-provider="openai"]').text()).not.toContain('ai_last_used');
        });

        it('Esc fecha o menu sem gerar', async () => {
            const wrapper = await mountForm({ aiProviders: MANY_AI });
            await aiButton(wrapper).trigger('click');

            await wrapper.find('[role="menu"]').trigger('keydown', { key: 'Escape' });

            expect(wrapper.find('[role="menu"]').exists()).toBe(false);
            expect(axios.post).not.toHaveBeenCalled();
        });

        it('erro da IA escolhida aparece (ex.: escolha outra)', async () => {
            axios.post.mockRejectedValue({
                response: { status: 422, data: { message: 'Google (Gemini) não respondeu agora.' } },
            });
            const wrapper = await mountForm({ aiProviders: MANY_AI });
            await aiButton(wrapper).trigger('click');
            await wrapper.find('[data-provider="gemini"]').trigger('click');
            await flushPromises();

            expect(wrapper.text()).toContain('Google (Gemini) não respondeu agora.');
        });
    });

    it('lista de IAs desatualizada (provedores mudaram com a página aberta): pede para a tela recarregar', async () => {
        axios.post.mockRejectedValue({
            response: { status: 422, data: { message: 'A lista foi atualizada.', reason: 'stale_providers' } },
        });
        const wrapper = await mountForm();

        await aiButton(wrapper).trigger('click');
        await flushPromises();

        expect(wrapper.emitted('providersStale')).toHaveLength(1);
        expect(wrapper.text()).toContain('A lista foi atualizada.');
    });
});
