import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axios from 'axios';
import CovenantPlansSection from '@/Pages/Panel/Manager/Covenants/CovenantPlansSection.vue';

/**
 * Planos do convênio na gaveta do manager: ANS só leitura; manual com
 * cadastro, edição, ativação e exclusão com justificativa. Filtro em chips
 * por situação (com contagem) e detalhes da ANS ao abrir o plano.
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
// Menu de ações sem teleporte: os itens ficam dentro da linha do plano.
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd-stub" :data-title="title"><slot /></div>' },
}));

const t = new Proxy(
    {
        plans_count: ':active de :total disponíveis no cadastro',
        plans_confirm_delete_text: 'Excluir ":name"?',
        plans_patients_one: '1 paciente',
        plans_patients_other: ':count pacientes',
    },
    { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
);
const tp = new Proxy({}, { get: (o, k) => (typeof k === 'string' ? k : o[k]) });

const ansPlan = {
    id: 'p-ans',
    name: 'AMIL ONE S6500',
    ans_code: '471234567',
    sub_label: 'Coletivo empresarial · Ambulatorial + Hospitalar com obstetrícia',
    contracting: 'Coletivo empresarial',
    segmentation: 'Ambulatorial + Hospitalar com obstetrícia',
    coverage_area: 'Nacional',
    accommodation: null,
    moderating_factor: 'Coparticipação',
    regulation_label: 'Regulamentado (Lei 9.656/98)',
    ans_status_at: '02/01/2024',
    ans_registered_at: '15/06/2010',
    status_label: 'Ativo',
    ans_status: 'active',
    active: true,
    source: 'ans',
    source_label: 'ANS',
    patients: 12,
    in_use: true,
};
const suspendedPlan = {
    ...ansPlan,
    id: 'p-sus',
    name: 'AMIL S380',
    status_label: 'Comercialização suspensa',
    ans_status: 'suspended',
    patients: 1,
};
const manualPlan = {
    id: 'p-man',
    name: 'PLANO ESPECIAL',
    ans_code: null,
    sub_label: '',
    status_label: null,
    ans_status: null,
    active: true,
    source: 'manual',
    source_label: 'Manual',
    patients: 0,
    in_use: false,
};

const COUNTS = {
    active: 2,
    total: 3,
    situations: { active: 2, suspended: 0, cancelled: 0, transferred: 0, inactive: 1 },
};

function page(data, extra = {}) {
    return {
        data: { data, total: data.length, from: 1, to: data.length, last_page: 1, counts: COUNTS, ...extra },
    };
}

async function mountSection() {
    const wrapper = mount(CovenantPlansSection, { props: { covenant: { id: 'cov-1' }, t, tp } });
    await flushPromises();
    return wrapper;
}

const chips = (wrapper) =>
    wrapper
        .findAll('[role="group"] button')
        .map((b) => [b.attributes('data-situation'), b.text().replace(/\s+/g, ' ')]);

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
        expect(wrapper.text()).toContain('2 de 3 disponíveis no cadastro');
        expect(wrapper.text()).toContain('AMIL ONE S6500');
        expect(wrapper.text()).toContain('471234567');
        expect(wrapper.text()).toContain('Coletivo empresarial');
        // A dica explica o que é "disponível" (comercialização suspensa continua).
        expect(wrapper.find('.cps-hint').attributes('title')).toBe('plans_available_hint');
    });

    it('plano da ANS é só leitura; manual tem editar, desativar e excluir no menu', async () => {
        const [ans, manual] = (await mountSection()).findAll('.cps-item');

        expect(ans.find('.dd-stub').exists()).toBe(false);
        expect(manual.find('.dd-stub').attributes('data-title')).toBe('more_actions: PLANO ESPECIAL');
        expect(manual.findAll('.dropdown-item').map((b) => b.text())).toEqual(['edit', 'deactivate', 'delete']);
    });

    it('busca no servidor (com espera) e volta para a página 1', async () => {
        vi.useFakeTimers();
        const wrapper = await mountSection();
        axios.get.mockClear();

        await wrapper.find('.input-group input').setValue('one');
        vi.advanceTimersByTime(400);
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith(expect.any(String), {
            params: { search: 'one', status: undefined, page: 1 },
        });
    });

    it('cadastra plano manual; formulário recebe o foco; erro de validação aparece no campo', async () => {
        const wrapper = mount(CovenantPlansSection, {
            props: { covenant: { id: 'cov-1' }, t, tp },
            attachTo: document.body,
        });
        await flushPromises();
        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('plans_new'))
            .trigger('click');
        await flushPromises();

        expect(document.activeElement?.id).toBe('cps-name');
        expect(wrapper.find('.cps-form__title').text()).toBe('plans_new');

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
        wrapper.unmount();
    });

    it('editar abre o formulário com o título de edição e os dados do plano', async () => {
        const wrapper = await mountSection();

        await wrapper
            .findAll('.cps-item')[1]
            .findAll('.dropdown-item')
            .find((b) => b.text() === 'edit')
            .trigger('click');

        expect(wrapper.find('.cps-form__title').text()).toBe('plans_edit');
        expect(wrapper.find('#cps-name').element.value).toBe('PLANO ESPECIAL');
    });

    it('desativar envia só o "active"', async () => {
        axios.put.mockResolvedValue({ data: {} });
        const manual = (await mountSection()).findAll('.cps-item')[1];

        await manual
            .findAll('.dropdown-item')
            .find((b) => b.text() === 'deactivate')
            .trigger('click');
        await flushPromises();

        expect(axios.put).toHaveBeenCalledWith(expect.stringContaining('p-man'), { active: false });
    });

    it('excluir pede justificativa; "em uso" volta no modal', async () => {
        const wrapper = await mountSection();
        await wrapper
            .findAll('.cps-item')[1]
            .findAll('.dropdown-item')
            .find((b) => b.text() === 'delete')
            .trigger('click');

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

    it('plano manual em uso: excluir já vem desabilitado, com o motivo (sem pedir justificativa à toa)', async () => {
        axios.get.mockResolvedValue(page([{ ...manualPlan, patients: 3, in_use: true }]));
        const wrapper = await mountSection();
        const remove = wrapper.find('.cps-delete');

        expect(remove.attributes('disabled')).toBeDefined();
        expect(remove.classes()).not.toContain('text-danger');
        expect(remove.attributes('title')).toBe('plans_in_use_hint');
        expect(remove.text()).toContain('plans_in_use_hint');
        expect(wrapper.find('.cps-tags').text()).toContain('3 pacientes');
    });

    it('falha ao carregar mostra aviso e permite tentar de novo', async () => {
        axios.get.mockRejectedValueOnce(new Error('500'));
        const wrapper = await mountSection();

        expect(wrapper.find('[role="alert"]').text()).toContain('plans_failed');

        await wrapper.find('[role="alert"] button').trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain('AMIL ONE S6500');
    });

    it('chips: Todos + só as situações que existem, com contagem; clicar filtra no servidor', async () => {
        const wrapper = await mountSection();

        expect(chips(wrapper)).toEqual([
            ['', 'plans_filter_all 3'],
            ['active', 'status_active 2'],
            ['inactive', 'status_inactive 1'],
        ]);
        expect(wrapper.find('[data-situation=""]').attributes('aria-pressed')).toBe('true');

        axios.get.mockClear();
        axios.get.mockResolvedValue(page([manualPlan], { counts: COUNTS }));
        await wrapper.find('[data-situation="inactive"]').trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith(expect.any(String), {
            params: { search: undefined, status: 'inactive', page: 1 },
        });
        expect(wrapper.find('[data-situation="inactive"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.find('[data-situation=""]').attributes('aria-pressed')).toBe('false');
    });

    it('situação escolhida segue no filtro mesmo zerada; lista vazia oferece limpar filtros', async () => {
        const wrapper = await mountSection();
        axios.get.mockResolvedValue(
            page([], { counts: { ...COUNTS, situations: { ...COUNTS.situations, inactive: 0 } } }),
        );

        await wrapper.find('[data-situation="inactive"]').trigger('click');
        await flushPromises();

        expect(chips(wrapper).map(([key]) => key)).toEqual(['', 'active', 'inactive']);
        expect(wrapper.text()).toContain('plans_empty');

        axios.get.mockClear();
        axios.get.mockResolvedValue(page([ansPlan, manualPlan]));
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'plans_clear_filters')
            .trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenLastCalledWith(expect.any(String), {
            params: { search: undefined, status: undefined, page: 1 },
        });
    });

    it('selo só na exceção: ativo tem ponto verde (texto para leitor de tela); suspenso mostra o selo', async () => {
        axios.get.mockResolvedValue(page([ansPlan, suspendedPlan]));
        const [active, suspended] = (await mountSection()).findAll('.cps-item');

        expect(active.find('.badge').exists()).toBe(false);
        expect(active.find('.cps-name .bg-success').exists()).toBe(true);
        expect(active.find('.cps-name .visually-hidden').text()).toBe('Ativo');

        expect(suspended.find('.badge').text()).toBe('Comercialização suspensa');
        expect(suspended.find('.badge').classes()).toContain('badge-soft-warning');
        expect(suspended.find('.cps-tags').text()).toContain('1 paciente');
    });

    it('com o filtro da situação, o selo some (o chip já diz) e o leitor de tela mantém o texto', async () => {
        axios.get.mockResolvedValue(
            page([suspendedPlan], { counts: { ...COUNTS, situations: { ...COUNTS.situations, suspended: 1 } } }),
        );
        const wrapper = await mountSection();
        expect(wrapper.find('.cps-item .badge').exists()).toBe(true);

        await wrapper.find('[data-situation="suspended"]').trigger('click');
        await flushPromises();

        const item = wrapper.find('.cps-item');
        expect(item.find('.badge').exists()).toBe(false);
        expect(item.find('.cps-name .bg-warning').exists()).toBe(true);
        expect(item.find('.cps-name .visually-hidden').text()).toBe('Comercialização suspensa');
    });

    it('abrir o plano mostra os dados da ANS e os pacientes (aria-expanded)', async () => {
        const wrapper = await mountSection();
        const summary = wrapper.findAll('.cps-summary')[0];
        const details = wrapper.find('#cps-details-p-ans');

        expect(summary.attributes('aria-expanded')).toBe('false');
        expect(summary.attributes('aria-controls')).toBe('cps-details-p-ans');
        expect(details.attributes('style')).toContain('display: none');

        await summary.trigger('click');

        expect(summary.attributes('aria-expanded')).toBe('true');
        expect(details.attributes('style') ?? '').not.toContain('display: none');
        const pairs = details.findAll('dt').map((dt, i) => [dt.text(), details.findAll('dd')[i].text()]);
        expect(pairs).toEqual([
            ['field_ans_code', '471234567'],
            ['field_contracting', 'Coletivo empresarial'],
            ['field_segmentation', 'Ambulatorial + Hospitalar com obstetrícia'],
            ['field_coverage_area', 'Nacional'],
            ['field_moderating_factor', 'Coparticipação'],
            ['field_regulation', 'Regulamentado (Lei 9.656/98)'],
            ['field_status', 'Ativo'],
            ['field_status_at', '02/01/2024'],
            ['field_registered_at', '15/06/2010'],
            ['col_source', 'ANS'],
            ['usage_patients', '12'],
        ]);

        await summary.trigger('click');
        expect(summary.attributes('aria-expanded')).toBe('false');
    });

    it('plano manual: detalhes usam "Situação" (não é situação da ANS)', async () => {
        const wrapper = await mountSection();
        const details = wrapper.find('#cps-details-p-man');

        expect(details.findAll('dt').map((dt) => dt.text())).toEqual(['filter_status', 'col_source', 'usage_patients']);
        expect(details.findAll('dd').map((dd) => dd.text())).toEqual(['status_active', 'Manual', '0']);
    });

    it('trocar de página leva o topo da seção à vista', async () => {
        axios.get.mockResolvedValue(page([ansPlan], { last_page: 3, total: 40, from: 1, to: 15 }));
        const wrapper = await mountSection();
        const scroll = vi.fn();
        wrapper.find('.cps').element.scrollIntoView = scroll;

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'next')
            .trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenLastCalledWith(expect.any(String), {
            params: { search: undefined, status: undefined, page: 2 },
        });
        expect(scroll).toHaveBeenCalledWith({ block: 'start' });
    });
});
