import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axios from 'axios';
import CovenantPlansSection from '@/Pages/Panel/Manager/Covenants/CovenantPlansSection.vue';

/**
 * Planos do convênio na gaveta do manager: ANS só leitura; manual com
 * cadastro, edição, ativação e exclusão com justificativa.
 */
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { locale: 'pt_BR' } }) }));
vi.mock('@/Components/Panel/ConfirmationWithReasonModal.vue', () => ({
    default: {
        props: ['open', 'message', 'error'],
        emits: ['confirm', 'close'],
        template: `<div class="reason-stub" :data-open="String(open)" :data-error="error">{{ message }}
            <button class="reason-confirm" @click="$emit('confirm', 'Cadastrado em duplicidade pelo suporte.')" /></div>`,
    },
}));

const t = new Proxy(
    { plans_count: ':active disponíveis de :total', plans_confirm_delete_text: 'Excluir ":name"?' },
    { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
);
const tp = new Proxy({}, { get: (o, k) => (typeof k === 'string' ? k : o[k]) });

const ansPlan = {
    id: 'p-ans',
    name: 'AMIL ONE S6500',
    ans_code: '471234567',
    sub_label: 'Coletivo empresarial · Ambulatorial + Hospitalar com obstetrícia',
    status_label: 'Ativo',
    ans_status: 'active',
    active: true,
    source: 'ans',
    source_label: 'ANS',
};
const manualPlan = {
    id: 'p-man',
    name: 'PLANO ESPECIAL',
    ans_code: null,
    sub_label: '',
    status_label: null,
    active: true,
    source: 'manual',
    source_label: 'Manual',
};

function page(data, extra = {}) {
    return {
        data: {
            data,
            total: data.length,
            from: 1,
            to: data.length,
            last_page: 1,
            counts: { active: 2, total: 3 },
            ...extra,
        },
    };
}

async function mountSection() {
    const wrapper = mount(CovenantPlansSection, { props: { covenant: { id: 'cov-1' }, t, tp } });
    await flushPromises();
    return wrapper;
}

describe('Manager → Convênios: planos do convênio', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axios.get.mockResolvedValue(page([ansPlan, manualPlan]));
    });
    afterEach(() => vi.useRealTimers());

    it('carrega os planos do convênio e mostra a contagem', async () => {
        const wrapper = await mountSection();

        expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('covenants.plans.index'), {
            params: { search: undefined, status: undefined, page: 1 },
        });
        expect(wrapper.text()).toContain('2 disponíveis de 3');
        expect(wrapper.text()).toContain('AMIL ONE S6500');
        expect(wrapper.text()).toContain('471234567');
        expect(wrapper.text()).toContain('Coletivo empresarial');
    });

    it('plano da ANS é só leitura; manual tem editar, desativar e excluir', async () => {
        const [ans, manual] = (await mountSection()).findAll('.cps-item');

        expect(ans.findAll('button')).toHaveLength(0);
        expect(manual.findAll('button').map((b) => b.attributes('title'))).toEqual(['edit', 'deactivate', 'delete']);
    });

    it('busca no servidor (com espera) e volta para a página 1', async () => {
        vi.useFakeTimers();
        const wrapper = await mountSection();
        axios.get.mockClear();

        await wrapper.find('input[type="search"]').setValue('one');
        vi.advanceTimersByTime(400);
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith(expect.any(String), {
            params: { search: 'one', status: undefined, page: 1 },
        });
    });

    it('cadastra plano manual; erro de validação aparece no campo', async () => {
        const wrapper = await mountSection();
        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('plans_new'))
            .trigger('click');
        await wrapper.find('#cps-name').setValue('plano novo');

        axios.post.mockRejectedValueOnce({
            response: { data: { errors: { name: ['Já existe um plano com este nome.'] } } },
        });
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(wrapper.find('#cps-name').classes()).toContain('is-invalid');
        expect(wrapper.text()).toContain('Já existe um plano com este nome.');

        axios.post.mockResolvedValueOnce({ data: {} });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenLastCalledWith(expect.stringContaining('covenants.plans.store'), {
            name: 'plano novo',
            ans_code: null,
            active: true,
        });
        expect(wrapper.find('#cps-name').exists()).toBe(false);
        expect(wrapper.emitted('changed')).toHaveLength(1);
    });

    it('desativar envia só o "active"', async () => {
        axios.put.mockResolvedValue({ data: {} });
        const manual = (await mountSection()).findAll('.cps-item')[1];

        await manual.find('button[title="deactivate"]').trigger('click');
        await flushPromises();

        expect(axios.put).toHaveBeenCalledWith(expect.stringContaining('p-man'), { active: false });
    });

    it('excluir pede justificativa; "em uso" volta no modal', async () => {
        const wrapper = await mountSection();
        await wrapper.findAll('.cps-item')[1].find('button[title="delete"]').trigger('click');

        expect(wrapper.find('.reason-stub').attributes('data-open')).toBe('true');
        expect(wrapper.find('.reason-stub').text()).toContain('Excluir "PLANO ESPECIAL"?');

        axios.delete.mockRejectedValueOnce({
            response: { data: { errors: { reason: ['Este plano está no cadastro de pacientes.'] } } },
        });
        await wrapper.find('.reason-confirm').trigger('click');
        await flushPromises();

        expect(axios.delete).toHaveBeenCalledWith(expect.stringContaining('p-man'), {
            data: { reason: 'Cadastrado em duplicidade pelo suporte.' },
        });
        expect(wrapper.find('.reason-stub').attributes('data-error')).toBe('Este plano está no cadastro de pacientes.');
    });

    it('falha ao carregar mostra aviso', async () => {
        axios.get.mockRejectedValueOnce(new Error('500'));
        const wrapper = await mountSection();

        expect(wrapper.text()).toContain('plans_failed');
    });
});
