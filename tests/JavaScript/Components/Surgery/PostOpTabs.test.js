import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PostOpTab from '@/Components/Surgery/Tabs/PostOpTab.vue';
import DischargeTab from '@/Components/Surgery/Tabs/DischargeTab.vue';
import EvaluationFormModal from '@/Components/Surgery/PostOp/EvaluationFormModal.vue';
import GuidanceModal from '@/Components/Surgery/PostOp/GuidanceModal.vue';

/**
 * Fase 4 / M5 — abas Pós-operatório e Alta e seus modais. O texto vem do servidor
 * (`t.postop.ui.*`); aqui o objeto devolve a própria chave, então o que se confere é
 * qual elemento aparece e qual requisição sai — nunca o idioma.
 */
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open'],
        template: '<div v-if="open" data-testid="modal"><slot name="header" /><slot /><slot name="footer" /></div>',
    },
}));
vi.mock('@/Components/Panel/TinyMceEditor.vue', () => ({
    default: { props: ['modelValue'], template: '<textarea data-testid="tiny" :value="modelValue" />' },
}));

const keys = new Proxy({}, { get: (_, key) => String(key) });
const t = { postop: { ui: keys } };

const urls = {
    evaluation_store: '/ev',
    evaluation_update: '/ev/__ID__',
    evaluation_destroy: '/ev/__ID__',
    evaluation_finalize: '/ev/__ID__/finalize',
    evaluation_addendum: '/ev/__ID__/addenda',
    returns_retry: '/retry',
    guidance_store: '/guidance',
    medicine_search: '/medicines',
    schedule_attend: '/attend/__ID__',
    schedule_show: '/show/__ID__',
};

const evaluation = (over = {}) => ({
    id: 'e1',
    milestone: { value: 'd1', short: 'D1', label: 'D1' },
    eye: { value: 'od', label: 'OD' },
    evaluated_at: '2026-10-16T09:00:00+00:00',
    va_uncorrected_id: null,
    va_corrected_id: null,
    va_uncorrected: '20/40',
    va_corrected: '20/20',
    iop_mmhg: '34.0',
    iop_attention: true,
    findings: [{ value: 'endophthalmitis_suspected', label: 'Endoftalmite', severe: true }],
    findings_notes: null,
    refraction_sphere: '-0.50',
    refraction_cylinder: '-1.00',
    refraction_axis: 90,
    spherical_equivalent: '-1.00',
    effective_equivalent: -1,
    complications: null,
    adherence: null,
    plan: 'Retorno amanhã',
    severe: true,
    status: { value: 'draft', label: 'Rascunho' },
    finalized: false,
    doctor: 'DR. TESTE',
    schedule_id: null,
    revision: 3,
    can_edit: true,
    can_addendum: false,
    addenda: [],
    ...over,
});

const section = (over = {}) => ({
    returns: [
        {
            id: 'r1',
            milestone: { value: 'd1', short: 'D1', label: 'D1' },
            target_date: '2026-10-16',
            scheduled_for: '2026-10-19T08:00:00+00:00',
            deviated: true,
            status: { value: 'scheduled', label: 'Agendado' },
            failure_label: null,
            schedule: { id: 's1', situation: { label: 'Agendado' } },
        },
        {
            id: 'r2',
            milestone: { value: 'd7', short: 'D7', label: 'D7' },
            target_date: '2026-10-22',
            scheduled_for: null,
            deviated: false,
            status: { value: 'failed', label: 'Falhou' },
            failure_label: 'Sem horário',
            schedule: null,
        },
    ],
    evaluations: [evaluation()],
    alerts: [
        {
            type: 'severe',
            evaluation_id: 'e1',
            milestone: 'D1',
            eye: 'OD',
            findings: ['Endoftalmite'],
            urgent_return: null,
        },
        { type: 'return_failed', milestone: 'D7', reason: 'Sem horário' },
    ],
    documents: [],
    guidance_defaults: { category: 'cataract', html: '<ul><li>Não esfregue</li></ul>' },
    options: {
        milestones: [{ value: 'd1', label: 'D1' }],
        eyes: [{ value: 'od', label: 'OD' }],
        findings: [
            { value: 'corneal_edema', label: 'Edema', severe: false },
            { value: 'endophthalmitis_suspected', label: 'Endoftalmite', severe: true },
        ],
        adherence: [],
        visual_acuities: [{ id: 'va1', name: '20/20' }],
    },
    can: { evaluate: true, addendum: true, guidance: true, retry: true },
    urls,
    ...over,
});

const mountTab = (sec = section(), can = { perform: true, manage: true }) =>
    mount(PostOpTab, {
        props: { section: sec, surgicalCase: { id: 'c1', revision: 5 }, can, urls: {}, options: {}, t },
    });

beforeEach(() => {
    globalThis.fetch = vi.fn(() =>
        Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ message: 'ok', id: 'e1', revision: 1 }),
        }),
    );
    window.confirm = vi.fn(() => true);
});

describe('PostOpTab', () => {
    it('mostra os retornos, o desvio da data-alvo e o motivo da falha', () => {
        const w = mountTab();
        const rows = w.findAll('[data-testid="po-return-row"]');

        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('deviated');
        expect(rows[1].text()).toContain('Sem horário');
    });

    it('destaca a complicação grave e o retorno que falhou como alertas', () => {
        const w = mountTab();

        expect(w.find('[data-testid="po-severe-alert"]').attributes('role')).toBe('alert');
        expect(w.find('[data-testid="po-severe-alert"]').text()).toContain('Endoftalmite');
        expect(w.find('[data-testid="po-return-failed"]').text()).toContain('D7');
    });

    it('esconde as ações clínicas de quem não pode (secretária) e mantém o "tentar de novo"', () => {
        const sec = section({
            evaluations: [evaluation({ can_edit: false })],
            can: { evaluate: false, addendum: false, guidance: false, retry: true },
        });
        const w = mountTab(sec, { perform: false, manage: true });

        expect(w.find('[data-testid="po-new"]').exists()).toBe(false);
        expect(w.find('[data-testid="po-finalize"]').exists()).toBe(false);
        expect(w.find('[data-testid="po-guidance"]').exists()).toBe(false);
        expect(w.find('[data-testid="po-retry"]').exists()).toBe(true);
    });

    it('"Finalizar" envia o rascunho com a revisão e recarrega a tela', async () => {
        const w = mountTab();

        await w.find('[data-testid="po-finalize"]').trigger('click');
        await flushPromises();

        const [url, init] = globalThis.fetch.mock.calls[0];
        const body = JSON.parse(init.body);

        expect(url).toBe('/ev/e1/finalize');
        expect(init.method).toBe('POST');
        expect(init.headers['X-CSRF-TOKEN']).toBe('test-csrf-token');
        expect(body).toMatchObject({
            milestone: 'd1',
            eye: 'od',
            expected_revision: 3,
            findings: ['endophthalmitis_suspected'],
        });
        expect(w.emitted('reload')).toHaveLength(1);
    });

    it('mostra o erro devolvido pelo servidor sem recarregar', async () => {
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({ ok: false, status: 409, json: () => Promise.resolve({ message: 'Regra do servidor' }) }),
        );
        const w = mountTab();

        await w.find('[data-testid="po-finalize"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-testid="po-failure"]').text()).toBe('Regra do servidor');
        expect(w.emitted('reload')).toBeUndefined();
    });

    it('"Tentar de novo" pede à Agenda e recarrega', async () => {
        const w = mountTab();

        await w.find('[data-testid="po-retry"]').trigger('click');
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][0]).toBe('/retry');
        expect(w.emitted('reload')).toHaveLength(1);
    });
});

describe('EvaluationFormModal', () => {
    const mountModal = (props = {}) =>
        mount(EvaluationFormModal, {
            props: { open: true, evaluation: null, section: section(), t: t.postop, ...props },
        });

    it('mostra o equivalente esférico calculado e avisa quando marca achado grave', async () => {
        const w = mountModal();

        await w.find('#po-sph').setValue('-0.5');
        await w.find('#po-cyl').setValue('-1');
        expect(w.find('[data-testid="po-se"]').text()).toContain('-1');

        expect(w.find('[data-testid="po-severe-warning"]').exists()).toBe(false);
        await w.find('#po-f-endophthalmitis_suspected').setValue(true);
        expect(w.find('[data-testid="po-severe-warning"]').exists()).toBe(true);
    });

    it('"Salvar e finalizar" cria o rascunho e depois finaliza com a revisão devolvida', async () => {
        const w = mountModal();

        await w.find('#po-iop').setValue('14');
        await w.find('[data-testid="po-save-finalize"]').trigger('click');
        await flushPromises();

        expect(globalThis.fetch.mock.calls.map((c) => c[0])).toEqual(['/ev', '/ev/e1/finalize']);
        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body).evaluated_at).toMatch(
            /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/,
        );
        expect(JSON.parse(globalThis.fetch.mock.calls[1][1].body).expected_revision).toBe(1);
        expect(w.emitted('saved')).toHaveLength(1);
    });

    it('erro de validação aparece no modal e nada fecha', async () => {
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({
                ok: false,
                status: 422,
                json: () => Promise.resolve({ errors: { iop_mmhg: ['PIO inválida'] } }),
            }),
        );
        const w = mountModal();

        await w.find('[data-testid="po-save-draft"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-testid="po-error"]').text()).toBe('PIO inválida');
        expect(w.emitted('saved')).toBeUndefined();
    });
});

describe('GuidanceModal', () => {
    it('conflito de alergia pede o motivo e reenvia com ele', async () => {
        globalThis.fetch = vi
            .fn()
            .mockResolvedValueOnce({
                ok: false,
                status: 422,
                json: () =>
                    Promise.resolve({
                        allergy_conflicts: [{ message: 'Alergia a neomicina (urticária)' }],
                        errors: { allergy_override_reason: ['Informe o motivo'] },
                    }),
            })
            .mockResolvedValueOnce({ ok: true, status: 201, json: () => Promise.resolve({ id: 'd1' }) });

        const w = mount(GuidanceModal, {
            props: { open: false, section: section(), eyeOptions: [{ value: 'od', label: 'OD' }], t: t.postop },
        });
        await w.setProps({ open: true });
        await flushPromises();

        await w.find('#g-n-0').setValue('Maxitrol');
        await w.find('#g-p-0').setValue('1 gota 4x/dia');
        await w.find('[data-testid="g-issue"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-testid="g-conflicts"]').text()).toContain('neomicina');

        await w.find('#g-reason').setValue('Benefício supera o risco');
        await w.find('[data-testid="g-issue"]').trigger('click');
        await flushPromises();

        const body = JSON.parse(globalThis.fetch.mock.calls[1][1].body);

        expect(body.allergy_override_reason).toBe('Benefício supera o risco');
        expect(body.eyedrops).toHaveLength(1);
        expect(w.emitted('saved')).toHaveLength(1);
    });
});

describe('DischargeTab', () => {
    const base = {
        discharged: false,
        blockers: [],
        can_discharge: true,
        is_surgeon: true,
        document: null,
        urls: { discharge: '/discharge' },
    };
    const mountDischarge = (over = {}) =>
        mount(DischargeTab, {
            props: { section: { ...base, ...over }, surgicalCase: { revision: 7 }, can: {}, urls: {}, options: {}, t },
        });

    it('lista o que falta e trava o botão', () => {
        const w = mountDischarge({ blockers: ['Falta o D1', 'Falta a descrição'] });

        expect(w.find('[data-testid="dc-blockers"]').findAll('li')).toHaveLength(2);
        expect(w.find('[data-testid="dc-submit"]').attributes('disabled')).toBeDefined();
    });

    it('com tudo pronto envia a decisão com a revisão e recarrega', async () => {
        const w = mountDischarge();

        await w.find('#dc-text').setValue('Evolução sem intercorrências.');
        await w.find('#dc-plan').setValue('Retorno anual');
        await w.find('form').trigger('submit');
        await flushPromises();

        const [url, init] = globalThis.fetch.mock.calls[0];

        expect(url).toBe('/discharge');
        expect(JSON.parse(init.body)).toEqual({
            discharge_text: 'Evolução sem intercorrências.',
            follow_up_plan: 'Retorno anual',
            expected_revision: 7,
        });
        expect(w.emitted('reload')).toHaveLength(1);
    });

    it('quem não é o cirurgião não vê o formulário; com alta dada mostra o documento', () => {
        const other = mountDischarge({ can_discharge: false, is_surgeon: false });

        expect(other.find('form').exists()).toBe(false);

        const done = mountDischarge({
            discharged: true,
            discharged_at: '2026-10-20T10:00:00+00:00',
            document: { doctor: 'DR. TESTE', discharge_text: 'Alta ok', follow_up_plan: 'Anual', pdf_url: '/pdf' },
        });

        expect(done.find('a[href="/pdf"]').exists()).toBe(true);
        expect(done.text()).toContain('Alta ok');
    });
});
