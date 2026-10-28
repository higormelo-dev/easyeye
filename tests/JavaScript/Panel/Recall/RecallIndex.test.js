import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () =>
    (await import('../Manager/RecallProtocols/support/inertiaMock.js')).buildInertiaMock(),
);
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div class="layout"><h1>{{ title }}</h1><slot /></div>' },
}));

import { forms, router, resetInertiaMock } from '../Manager/RecallProtocols/support/inertiaMock.js';
import Index from '@/Pages/Panel/Recall/Index.vue';
import recallListT from './support/recall_list.json';

// Em produção o ZiggyVue registra `route` como propriedade global (usada nos templates); no teste, só o global do script existe.
const global = {
    mocks: { route: (...args) => globalThis.route(...args) },
    stubs: { Teleport: true, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } },
};

const row = (overrides = {}) => ({
    id: 'rc-1',
    patient: {
        id: 'p-1',
        name: 'ANA SOARES',
        nickname: null,
        code: '00001',
        age: 75,
        phone: '(11) 98888-7777',
        whatsapp: true,
    },
    source: 'doctor_order',
    task_label: 'Retorno pedido pelo médico',
    condition: null,
    eye: null,
    due_at: '2026-02-19',
    days_to_due: 18,
    last_done_at: '2026-01-05',
    state: 'due_soon',
    state_label: 'A vencer',
    lifecycle: 'open',
    lifecycle_label: 'Em aberto',
    snoozed_until: null,
    dismissed_reason: null,
    closed_reason: null,
    closed_reason_label: null,
    rule_suspended: false,
    missing: null,
    capped: false,
    contact: { count: 0, last_at: null, last_outcome: null, outcome_label: null },
    schedule: null,
    blocks: [],
    ...overrides,
});

const paginator = (data) => ({
    data,
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: data.length,
    from: data.length ? 1 : null,
    to: data.length || null,
    links: [],
    prev_page_url: null,
    next_page_url: null,
});

function props(overrides = {}) {
    const data = overrides.data ?? [row()];
    delete overrides.data;

    return {
        breadcrumbs: [],
        view: 'to_contact',
        source: null,
        search: '',
        counts: {
            due_soon: 1,
            overdue: 2,
            lost: 3,
            scheduled: 4,
            snoozed: 5,
            needs_info: 0,
            suspended: 0,
            to_contact: 3,
        },
        rows: paginator(data),
        today: '2026-02-01',
        snoozeMaxDays: 365,
        contactOutcomes: [
            { value: 'reached', label: 'Falei com o paciente' },
            { value: 'no_answer', label: 'Não atendeu' },
        ],
        isDoctor: false,
        phonesVisible: true,
        setup: { doctor_order_enabled: true, protocols: 0 },
        t: recallListT,
        ...overrides,
    };
}

const mountPage = (overrides = {}) => mount(Index, { props: props(overrides), global });

describe('Retornos — a lista', () => {
    beforeEach(() => {
        resetInertiaMock();
    });

    it('mostra os números como visões, a linha com o que falta, o vencimento e o estado em TEXTO', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="recall-view-to_contact"] button').text()).toContain('3');
        expect(wrapper.find('[data-testid="recall-view-to_contact"] button').text()).toContain(
            '1 a vencer · 2 vencidos',
        );
        expect(wrapper.find('[data-testid="recall-view-lost"] button').text()).toContain('3');
        expect(wrapper.find('[data-testid="recall-view-to_contact"] button').attributes('aria-pressed')).toBe('true');
        expect(wrapper.find('[data-testid="recall-view-lost"] button').attributes('aria-pressed')).toBe('false');

        const first = wrapper.find('[data-testid="recall-row"]');
        expect(first.text()).toContain('ANA SOARES');
        expect(first.text()).toContain('75 anos');
        expect(first.text()).toContain('(11) 98888-7777');
        expect(first.text()).toContain('Retorno pedido pelo médico');
        expect(first.text()).toContain('Vence em 18 dias');
        expect(first.text()).toContain('A vencer');
        expect(first.text()).toContain('Sem contato ainda');
    });

    it('vencimento: hoje, singular, atraso', () => {
        const wrapper = mountPage({
            data: [
                row({ id: 'a', days_to_due: 0 }),
                row({ id: 'b', days_to_due: 1 }),
                row({ id: 'c', days_to_due: -1, state: 'due', state_label: 'Vencido' }),
                row({ id: 'd', days_to_due: -45, state: 'overdue', state_label: 'Em atraso' }),
            ],
        });
        const text = wrapper.findAll('[data-testid="recall-row"]').map((entry) => entry.text());

        expect(text[0]).toContain('Vence hoje');
        expect(text[1]).toContain('Vence em 1 dia');
        expect(text[2]).toContain('Venceu há 1 dia');
        expect(text[3]).toContain('Venceu há 45 dias');
        expect(text[3]).toContain('Em atraso');
    });

    it('clicar numa visão pede a lista dela (sem recarregar a página, sem empilhar histórico)', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="recall-view-lost"] button').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/_routes/panel.recall.index',
            expect.objectContaining({ view: 'lost' }),
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('as visões extras: histórico sempre; "falta informação" e "regra suspensa" só quando há', () => {
        const withNone = mountPage();
        expect(withNone.find('[data-testid="recall-tab-history"]').exists()).toBe(true);
        expect(withNone.find('[data-testid="recall-tab-needs_info"]').exists()).toBe(false);
        expect(withNone.find('[data-testid="recall-tab-suspended"]').exists()).toBe(false);

        const withThem = mountPage({
            counts: {
                due_soon: 0,
                overdue: 0,
                lost: 0,
                scheduled: 0,
                snoozed: 0,
                needs_info: 2,
                suspended: 1,
                to_contact: 0,
            },
        });
        expect(withThem.find('[data-testid="recall-tab-needs_info"]').text()).toContain('2');
        expect(withThem.find('[data-testid="recall-tab-suspended"]').text()).toContain('1');
    });

    it('a condição clínica só aparece para o médico', () => {
        const entry = row({
            source: 'protocol',
            condition: 'Glaucoma',
            eye: 'both',
            task_label: 'Exame: Campo visual',
        });

        expect(mountPage({ data: [entry], isDoctor: false }).text()).not.toContain('Glaucoma');
        expect(mountPage({ data: [entry], isDoctor: true }).text()).toContain('Condição: Glaucoma');
    });

    it('bloqueio de contato vira aviso em texto; prazo limitado e regra suspensa também', () => {
        const wrapper = mountPage({
            data: [
                row({
                    blocks: [{ reason: 'do_not_contact', label: 'Pediu para não ser contatado' }],
                    capped: true,
                }),
                row({ id: 'rc-2', rule_suspended: true }),
            ],
        });

        expect(wrapper.text()).toContain('Não ligue/envie: Pediu para não ser contatado');
        expect(wrapper.text()).toContain('Prazo limitado ao máximo validado');
        expect(wrapper.text()).toContain('A EasyEye suspendeu a regra');
    });

    it('linha suspensa não tem nenhuma ação; encerrada/falta informação também não', () => {
        const wrapper = mountPage({
            data: [
                row({ id: 's', rule_suspended: true }),
                row({ id: 'c', lifecycle: 'closed', closed_reason: 'completed', closed_reason_label: 'Concluído' }),
                row({ id: 'n', lifecycle: 'needs_info', due_at: null, state: null }),
            ],
        });

        for (const entry of wrapper.findAll('[data-testid="recall-row"]')) {
            expect(entry.find('[data-testid="recall-contact"]').exists()).toBe(false);
            expect(entry.find('[data-testid="recall-reopen"]').exists()).toBe(false);
        }
    });

    it('linha adiada com a regra suspensa não pode ser reaberta', () => {
        const wrapper = mountPage({
            data: [row({ lifecycle: 'snoozed', snoozed_until: '2026-03-01', rule_suspended: true })],
        });

        expect(wrapper.find('[data-testid="recall-reopen"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="recall-contact"]').exists()).toBe(false);
    });

    it('"Reabrir" só em adiado ou dispensado; "Adiar" só em aberto', () => {
        const wrapper = mountPage({
            data: [
                row({ id: 'o' }),
                row({ id: 'z', lifecycle: 'snoozed', snoozed_until: '2026-03-01' }),
                row({ id: 'd', lifecycle: 'dismissed', dismissed_reason: 'Mudou de cidade' }),
            ],
        });
        const rows = wrapper.findAll('[data-testid="recall-row"]');

        expect(rows[0].find('[data-testid="recall-snooze"]').exists()).toBe(true);
        expect(rows[0].find('[data-testid="recall-reopen"]').exists()).toBe(false);
        expect(rows[1].find('[data-testid="recall-snooze"]').exists()).toBe(false);
        expect(rows[1].find('[data-testid="recall-contact"]').exists()).toBe(true);
        expect(rows[1].find('[data-testid="recall-reopen"]').exists()).toBe(true);
        expect(rows[1].text()).toContain('Adiado até');
        expect(rows[2].find('[data-testid="recall-contact"]').exists()).toBe(false);
        expect(rows[2].find('[data-testid="recall-reopen"]').exists()).toBe(true);
        expect(rows[2].text()).toContain('Dispensado: Mudou de cidade');
    });

    it('"Agendar" leva à Agenda com o retorno; some quando já há agendamento', () => {
        const wrapper = mountPage({
            data: [row(), row({ id: 'rc-2', schedule: { id: 's-1', date_at: '2026-02-10T09:00:00-03:00' } })],
        });
        const rows = wrapper.findAll('[data-testid="recall-row"]');

        expect(rows[0].find('[data-testid="recall-schedule"]').attributes('href')).toBe(
            '/_routes/panel.schedules.index?new=1&recall=rc-1',
        );
        expect(rows[1].find('[data-testid="recall-schedule"]').exists()).toBe(false);
        expect(rows[1].text()).toContain('Agendado para');
    });
});

describe('Retornos — estados vazios', () => {
    beforeEach(() => resetInertiaMock());

    it('tudo em dia na visão principal', () => {
        const wrapper = mountPage({ data: [] });

        expect(wrapper.find('[data-testid="recall-empty"]').text()).toContain('Tudo em dia');
        expect(wrapper.find('[data-testid="recall-table"]').exists()).toBe(false);
    });

    it('com filtro ativo: "nada encontrado" e como limpar', async () => {
        const wrapper = mountPage({ data: [], search: 'xyz' });

        expect(wrapper.find('[data-testid="recall-empty"]').text()).toContain('Nada encontrado');
        expect(wrapper.find('[data-testid="recall-empty"]').text()).toContain('Limpar filtros');
    });

    it('sem protocolo e com o retorno do médico desligado: explica o motivo', () => {
        const wrapper = mountPage({ data: [], setup: { doctor_order_enabled: false, protocols: 0 } });

        expect(wrapper.find('[data-testid="recall-empty"]').text()).toContain('desligado');
    });
});

describe('Retornos — filtros', () => {
    beforeEach(() => {
        resetInertiaMock();
        vi.useFakeTimers();
    });
    afterEach(() => vi.useRealTimers());

    it('a busca espera o fim da digitação (350 ms) e volta à página 1', async () => {
        const wrapper = mountPage();

        await wrapper.find('input[type="text"]').setValue('ana');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(349);
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(2);
        expect(router.get).toHaveBeenCalledTimes(1);
        expect(router.get.mock.calls[0][1]).toMatchObject({ view: 'to_contact', search: 'ana' });
        expect(router.get.mock.calls[0][1]).not.toHaveProperty('page', 3);
    });

    it('a origem filtra', async () => {
        const wrapper = mountPage();

        await wrapper.find('#recall-source').setValue('doctor_order');

        expect(router.get.mock.calls.at(-1)[1]).toMatchObject({ source: 'doctor_order' });
    });
});

describe('Retornos — ações', () => {
    beforeEach(() => resetInertiaMock());

    const open = async (wrapper, testId) => {
        await wrapper.find(`[data-testid="${testId}"]`).trigger('click');
        await nextTick();
    };

    it('contato: abre o formulário, envia o resultado e a observação para a rota da linha', async () => {
        const wrapper = mountPage();
        await open(wrapper, 'recall-contact');

        expect(wrapper.text()).toContain('Registrar contato');
        expect(wrapper.text()).toContain('Paciente: ANA SOARES');

        await wrapper.find('#recall-outcome').setValue('no_answer');
        await wrapper.find('#recall-note').setValue('Caixa postal');
        await wrapper.find('#recall-dialog-form').trigger('submit');

        const form = forms.find((entry) => entry.lastRequest);
        expect(form.lastRequest.method).toBe('post');
        expect(form.lastRequest.url).toBe('/_routes/panel.recall.contact/rc-1');
        expect(form.lastRequest.data).toEqual({ outcome: 'no_answer', note: 'Caixa postal' });
    });

    it('adiar: a data tem janela de amanhã até um ano', async () => {
        const wrapper = mountPage();
        await open(wrapper, 'recall-snooze');

        const input = wrapper.find('#recall-until');
        expect(input.attributes('min')).toBe('2026-02-02');
        expect(input.attributes('max')).toBe('2027-02-01');

        await input.setValue('2026-03-01');
        await wrapper.find('#recall-dialog-form').trigger('submit');

        const form = forms.find((entry) => entry.lastRequest);
        expect(form.lastRequest.url).toBe('/_routes/panel.recall.snooze/rc-1');
        expect(form.lastRequest.data).toEqual({ until: '2026-03-01', reason: '' });
    });

    it('dispensar: motivo obrigatório (campo required) e botão de perigo', async () => {
        const wrapper = mountPage();
        await open(wrapper, 'recall-dismiss');

        expect(wrapper.find('#recall-dismiss-reason').attributes('required')).toBeDefined();
        expect(wrapper.find('[data-testid="recall-dialog-submit"]').classes()).toContain('btn-danger');

        await wrapper.find('#recall-dismiss-reason').setValue('Foi morar em outro estado');
        await wrapper.find('#recall-dialog-form').trigger('submit');

        const form = forms.find((entry) => entry.lastRequest);
        expect(form.lastRequest.url).toBe('/_routes/panel.recall.dismiss/rc-1');
        expect(form.lastRequest.data).toEqual({ reason: 'Foi morar em outro estado' });
    });

    it('"feito fora": a data não pode ser futura', async () => {
        const wrapper = mountPage();
        await open(wrapper, 'recall-elsewhere');

        expect(wrapper.find('#recall-elsewhere-date').attributes('max')).toBe('2026-02-01');

        await wrapper.find('#recall-elsewhere-date').setValue('2026-01-20');
        await wrapper.find('#recall-dialog-form').trigger('submit');

        const form = forms.find((entry) => entry.lastRequest);
        expect(form.lastRequest.url).toBe('/_routes/panel.recall.done-elsewhere/rc-1');
        expect(form.lastRequest.data).toEqual({ date: '2026-01-20' });
    });

    it('reabrir: confirma e envia sem corpo', async () => {
        const wrapper = mountPage({ data: [row({ lifecycle: 'dismissed', dismissed_reason: 'x' })] });
        await open(wrapper, 'recall-reopen');

        expect(wrapper.text()).toContain('Reabrir o retorno');

        await wrapper.find('#recall-dialog-form').trigger('submit');

        const form = forms.find((entry) => entry.lastRequest);
        expect(form.lastRequest.url).toBe('/_routes/panel.recall.reopen/rc-1');
        expect(form.lastRequest.data).toEqual({});
    });

    it('erro de validação do servidor aparece no campo, em alerta, e o modal continua aberto', async () => {
        const wrapper = mountPage();
        await open(wrapper, 'recall-dismiss');
        await wrapper.find('#recall-dismiss-reason').setValue('ab');
        await wrapper.find('#recall-dialog-form').trigger('submit');

        const form = forms.find((entry) => entry.lastRequest);
        form.setError('reason', 'O motivo precisa ter ao menos 3 caracteres.');
        form.lastRequest.options.onError({ reason: 'x' });
        await nextTick();

        expect(wrapper.find('#recall-dialog-form [role="alert"]').text()).toContain('ao menos 3 caracteres');
        expect(wrapper.text()).toContain('Dispensar este retorno');
    });

    it('sucesso fecha o modal; recusa de negócio (sem erro de campo) também fecha — a lista explica pelo aviso', async () => {
        const wrapper = mountPage();
        await open(wrapper, 'recall-contact');
        await wrapper.find('#recall-outcome').setValue('reached');
        await wrapper.find('#recall-dialog-form').trigger('submit');

        const form = forms.find((entry) => entry.lastRequest);
        form.lastRequest.options.onSuccess();
        await nextTick();
        expect(wrapper.find('#recall-dialog-form').exists()).toBe(false);

        await open(wrapper, 'recall-contact');
        await wrapper.find('#recall-outcome').setValue('reached');
        await wrapper.find('#recall-dialog-form').trigger('submit');
        form.lastRequest.options.onError({});
        await nextTick();
        expect(wrapper.find('#recall-dialog-form').exists()).toBe(false);
    });

    it('cancelar fecha sem enviar', async () => {
        const wrapper = mountPage();
        await open(wrapper, 'recall-contact');

        const cancel = wrapper.findAll('button').find((button) => button.text() === 'Cancelar');
        await cancel.trigger('click');

        expect(wrapper.find('#recall-dialog-form').exists()).toBe(false);
        expect(forms.some((entry) => entry.lastRequest)).toBe(false);
    });
});
