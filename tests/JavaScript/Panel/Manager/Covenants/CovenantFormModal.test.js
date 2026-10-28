import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import CovenantFormModal from '@/Pages/Panel/Manager/Covenants/CovenantFormModal.vue';

/**
 * Formulário do catálogo global de convênios: cada tipo só envia o que o
 * servidor aceita alterar (manual = tudo; ANS = nome/cor/tabela/ativo;
 * PARTICULAR = cor/tabela).
 */
const form = vi.hoisted(() => ({ current: null, transformed: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
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

const t = new Proxy({}, { get: (o, k) => (typeof k === 'string' ? k : o[k]) });

const ans = {
    id: 'ans-1',
    name: 'UNIMED CAMPINAS',
    company_name: 'Unimed Campinas Cooperativa de Trabalho Médico',
    cnpj_formatted: '11.222.333/0001-81',
    national_registry: '11222333000181',
    ans_registry: '335690',
    ans_modality: 'Cooperativa Médica',
    city: 'Campinas',
    uf: 'SP',
    source: 'ans',
    color: '#22C55E',
    table: true,
    active: true,
    is_particular: false,
};
const particular = {
    id: 'p-1',
    name: 'PARTICULAR',
    source: 'manual',
    color: '#64748B',
    table: false,
    active: true,
    is_particular: true,
};

async function mountForm(covenant) {
    const wrapper = mount(CovenantFormModal, {
        props: { open: false, covenant, modalities: ['Cooperativa Médica', 'Autogestão'], ufs: ['SP', 'RJ'], t },
    });
    await wrapper.setProps({ open: true }); // o watch de "open" carrega o item
    return wrapper;
}

describe('Manager → Convênios: formulário', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        form.transformed = null;
    });

    it('novo convênio: dados oficiais opcionais visíveis e envio completo', async () => {
        const wrapper = await mountForm(null);

        expect(wrapper.text()).toContain('new_title');
        expect(wrapper.find('#cov-cnpj').exists()).toBe(true);
        expect(
            wrapper
                .find('#cov-uf')
                .findAll('option')
                .map((o) => o.text()),
        ).toEqual(['—', 'SP', 'RJ']);

        await wrapper.find('#cov-name').setValue('Convênio Local');
        await wrapper.find('#cov-cnpj').setValue('11.222.333/0001-81');
        await wrapper.find('form').trigger('submit');

        expect(form.current.post).toHaveBeenCalledWith(expect.stringContaining('covenants.store'), expect.any(Object));
        expect(form.transformed({ ...form.current })).toMatchObject({
            name: 'Convênio Local',
            national_registry: '11.222.333/0001-81',
            table: true,
            active: true,
        });
    });

    it('operadora da ANS: dados oficiais só leitura e envia só nome, cor, tabela e ativo', async () => {
        const wrapper = await mountForm(ans);

        expect(wrapper.text()).toContain('ans_readonly_hint');
        expect(wrapper.text()).toContain('Unimed Campinas Cooperativa de Trabalho Médico');
        expect(wrapper.text()).toContain('11.222.333/0001-81');
        expect(wrapper.find('#cov-cnpj').exists()).toBe(false);
        expect(wrapper.find('#cov-name').element.value).toBe('UNIMED CAMPINAS');

        await wrapper.find('#cov-name').setValue('UNIMED');
        await wrapper.find('form').trigger('submit');

        expect(form.current.put).toHaveBeenCalledWith(expect.stringContaining('ans-1'), expect.any(Object));
        expect(form.transformed({ ...form.current })).toEqual({
            name: 'UNIMED',
            color: '#22C55E',
            table: true,
            active: true,
        });
    });

    it('PARTICULAR: nome bloqueado, sem "ativo"; envia só cor e tabela', async () => {
        const wrapper = await mountForm(particular);

        expect(wrapper.text()).toContain('particular_hint');
        expect(wrapper.find('#cov-name').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#cov-active').exists()).toBe(false);

        await wrapper.find('#cov-table').setValue(true);
        await wrapper.find('form').trigger('submit');

        expect(form.transformed({ ...form.current })).toEqual({ color: '#64748B', table: true });
    });

    it('erros de validação aparecem no campo', async () => {
        const wrapper = await mountForm(null);
        form.current.errors = { name: 'Já existe um convênio com este nome no catálogo.' };
        await wrapper.vm.$nextTick();

        expect(wrapper.find('#cov-name').classes()).toContain('is-invalid');
        expect(wrapper.text()).toContain('Já existe um convênio com este nome no catálogo.');
    });
});
