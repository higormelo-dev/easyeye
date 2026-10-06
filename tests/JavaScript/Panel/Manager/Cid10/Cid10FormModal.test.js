import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Cid10FormModal from '@/Pages/Panel/Manager/Cid10/Cid10FormModal.vue';

/**
 * Formulário do código CID-10: novo = personalizado (aviso de TISS); código
 * oficial mostra o texto do DATASUS e pede justificativa ao mudar o texto;
 * código em uso não deixa trocar o código.
 */
const form = vi.hoisted(() => ({ current: null, transformed: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        usePage: () => ({ props: { locale: 'pt_BR', t_hardening: {} } }),
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
                put: vi.fn(),
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
    { edit_title: 'Editar :code', code_locked: 'Em uso por :count' },
    { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
);

const official = {
    id: 'c-1',
    code: 'H25.1',
    description: 'Catarata senil nuclear',
    official_description: 'Catarata senil nuclear',
    category: 'Cristalino',
    is_custom: false,
    is_edited: false,
    usage: { records: 0, exams: 0, clinics: 0, links: 0, total: 0 },
};

async function mountForm(code = null) {
    const wrapper = mount(Cid10FormModal, { props: { open: false, code, categories: ['Retina'], t } });
    await wrapper.setProps({ open: true }); // o watch de "open" carrega o item
    return wrapper;
}

const save = (wrapper) => wrapper.find('[data-test="save"]');
const REASON = 'Texto oficial confunde os médicos da clínica.';

describe('Manager → CID-10: formulário', () => {
    beforeEach(() => vi.clearAllMocks());

    it('novo código: aviso de fora da tabela oficial, sem justificativa; envia código normalizado', async () => {
        const wrapper = await mountForm();

        expect(wrapper.find('[data-test="custom-warning"]').text()).toBe('custom_warning');
        expect(wrapper.find('[data-test="reason"]').exists()).toBe(false);
        expect(save(wrapper).attributes('disabled')).toBeDefined();

        await wrapper.find('#cid-code').setValue(' h59.7 ');
        await wrapper.find('#cid-description').setValue('Complicação pós-operatória');
        await wrapper.find('form').trigger('submit');

        expect(form.current.post).toHaveBeenCalledWith('/_routes/manager.cid10.store', expect.any(Object));
        expect(form.transformed(form.current)).toEqual({
            code: 'H59.7',
            description: 'Complicação pós-operatória',
            category: '',
        });
    });

    it('código oficial: mostra o texto do DATASUS; mudar a descrição exige justificativa (mín. 20)', async () => {
        const wrapper = await mountForm(official);

        expect(wrapper.find('[data-test="custom-warning"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="official-box"]').text()).toContain('Catarata senil nuclear');
        expect(wrapper.find('[data-test="reason"]').exists()).toBe(false);

        await wrapper.find('#cid-description').setValue('Catarata nuclear');
        expect(wrapper.find('[data-test="reason"]').exists()).toBe(true);
        expect(save(wrapper).attributes('disabled')).toBeDefined();

        await wrapper.find('[data-test="reason"] textarea').setValue('curto');
        expect(save(wrapper).attributes('disabled')).toBeDefined();

        await wrapper.find('[data-test="reason"] textarea').setValue(REASON);
        expect(save(wrapper).attributes('disabled')).toBeUndefined();

        await wrapper.find('form').trigger('submit');
        expect(form.current.put).toHaveBeenCalledWith('/_routes/manager.cid10.update/c-1', expect.any(Object));
        expect(form.transformed(form.current)).toMatchObject({
            code: 'H25.1',
            description: 'Catarata nuclear',
            reason: REASON,
        });
    });

    it('"Usar o texto oficial" volta o texto (e a justificativa deixa de ser pedida)', async () => {
        const wrapper = await mountForm({ ...official, description: 'Texto da casa', is_edited: true });

        await wrapper.find('[data-test="use-official"]').trigger('click');

        expect(wrapper.find('#cid-description').element.value).toBe('Catarata senil nuclear');
        expect(wrapper.find('[data-test="reason"]').exists()).toBe(true); // mudou em relação ao salvo
        expect(wrapper.find('[data-test="use-official"]').exists()).toBe(false);
    });

    it('só a categoria: salva sem justificativa e sem mandar "reason"', async () => {
        const wrapper = await mountForm(official);
        await wrapper.find('#cid-category').setValue('Retina');
        await wrapper.find('form').trigger('submit');

        expect(form.transformed(form.current)).toEqual({
            code: 'H25.1',
            description: 'Catarata senil nuclear',
            category: 'Retina',
        });
    });

    it('código em uso: campo código travado com o motivo', async () => {
        const wrapper = await mountForm({
            ...official,
            usage: { records: 2, exams: 1, clinics: 1, links: 0, total: 3 },
        });

        expect(wrapper.find('#cid-code').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#cid-code-hint').text()).toBe('Em uso por 3');
    });

    it('trocar o código de um oficial: avisa que vira personalizado e pede justificativa', async () => {
        const wrapper = await mountForm(official);
        await wrapper.find('#cid-code').setValue('H25.7');

        expect(wrapper.find('[data-test="custom-warning"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('code_change_note');
        expect(wrapper.find('[data-test="reason"]').exists()).toBe(true);
    });

    it('erros do servidor aparecem nos campos', async () => {
        const wrapper = await mountForm();
        form.current.errors = { code: 'Este código já existe no catálogo.' };
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-test="error-code"]').text()).toBe('Este código já existe no catálogo.');
        expect(wrapper.find('#cid-code').classes()).toContain('is-invalid');
    });
});
