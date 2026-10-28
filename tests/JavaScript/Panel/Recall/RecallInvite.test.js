import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () =>
    (await import('../Manager/RecallProtocols/support/inertiaMock.js')).buildInertiaMock(),
);
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div class="layout"><h1>{{ title }}</h1><slot /></div>' },
}));

import { forms, resetInertiaMock } from '../Manager/RecallProtocols/support/inertiaMock.js';
import Index from '@/Pages/Panel/Recall/Index.vue';
import RecallInviteStatus from '@/Components/Panel/Recall/RecallInviteStatus.vue';
import recallListT from './support/recall_list.json';

const global = {
    mocks: { route: (...args) => globalThis.route(...args) },
    stubs: { Teleport: true, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } },
};

const invite = (overrides = {}) => ({
    consent: true,
    opted_out: false,
    can_try: true,
    requested: false,
    last: null,
    ...overrides,
});

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
    invite: invite(),
    ...overrides,
});

const paginator = (data) => ({
    data,
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: data.length,
    from: 1,
    to: data.length,
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
            overdue: 0,
            lost: 0,
            scheduled: 0,
            snoozed: 0,
            needs_info: 0,
            suspended: 0,
            to_contact: 1,
        },
        rows: paginator(data),
        today: '2026-02-01',
        snoozeMaxDays: 365,
        contactOutcomes: [],
        isDoctor: false,
        phonesVisible: true,
        setup: { doctor_order_enabled: true, protocols: 0 },
        invites: { available: true, blocker: null, label: null },
        t: recallListT,
        ...overrides,
    };
}

const mountPage = (overrides = {}) => mount(Index, { props: props(overrides), global });

const preview = (overrides = {}) => ({
    can_send: true,
    blockers: [],
    phone: '(11) 9****-7777',
    recalls: 2,
    message: {
        body: 'Olá, Ana! A Clínica Visão lembra que chegou a hora de agendar o seu retorno de acompanhamento.',
        footer: 'Para não receber mais avisos, responda SAIR.',
        buttons: ['Quero agendar', 'Já agendei'],
    },
    last_invite_at: null,
    next_allowed_on: null,
    daily: { used: 3, cap: 5 },
    ...overrides,
});

/** fetch que resolve com a prévia dada (ou recusa). */
function stubPreview(result) {
    const fetchMock = vi.fn(() => Promise.resolve(result));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

const ok = (body) => ({ ok: true, status: 200, json: () => Promise.resolve(body) });

describe('Retornos — convite por WhatsApp', () => {
    beforeEach(() => resetInertiaMock());
    afterEach(() => vi.unstubAllGlobals());

    it('"Enviar convite" só aparece com o canal aberto, retorno em aberto e paciente que pode receber', () => {
        const wrapper = mountPage({
            data: [
                row({ id: 'a' }),
                row({ id: 'b', invite: invite({ can_try: false, consent: false }) }),
                row({ id: 'c', lifecycle: 'snoozed', snoozed_until: '2026-03-01' }),
                row({ id: 'd', rule_suspended: true }),
            ],
        });
        const rows = wrapper.findAll('[data-testid="recall-row"]');

        expect(rows[0].find('[data-testid="recall-invite"]').exists()).toBe(true);
        expect(rows[1].find('[data-testid="recall-invite"]').exists()).toBe(false);
        expect(rows[2].find('[data-testid="recall-invite"]').exists()).toBe(false);
        expect(rows[3].find('[data-testid="recall-invite"]').exists()).toBe(false);

        const closed = mountPage({ invites: { available: false, blocker: 'clinic_not_enabled', label: 'x' } });
        expect(closed.find('[data-testid="recall-invite"]').exists()).toBe(false);
    });

    it('abre a prévia: busca no servidor, mostra o texto exato, o telefone mascarado e o ritmo; envia para a rota da linha', async () => {
        let release;
        const pending = new Promise((resolve) => {
            release = resolve;
        });
        const fetchMock = stubPreview(pending);
        const wrapper = mountPage();

        await wrapper.find('[data-testid="recall-invite"]').trigger('click');

        expect(wrapper.find('[data-testid="invite-loading"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="recall-dialog-submit"]').attributes('disabled')).toBeDefined();
        expect(fetchMock).toHaveBeenCalledWith(
            '/_routes/panel.recall.invite-preview/rc-1',
            expect.objectContaining({ headers: expect.objectContaining({ Accept: 'application/json' }) }),
        );

        release(ok(preview()));
        await flushPromises();

        const dialog = wrapper.find('[data-testid="invite-dialog"]');
        expect(dialog.text()).toContain('Para: (11) 9****-7777');
        expect(dialog.text()).toContain('O convite cobre 2 retornos deste paciente.');
        expect(dialog.find('[data-testid="invite-message"]').text()).toContain('Olá, Ana! A Clínica Visão lembra');
        expect(dialog.find('[data-testid="invite-message"]').text()).toContain('responda SAIR');
        expect(dialog.text()).toContain('Quero agendar');
        expect(dialog.text()).toContain('Já agendei');
        expect(dialog.find('[data-testid="invite-pacing"]').text()).toContain('Hoje: 3 de 5 convites da clínica.');
        expect(dialog.text()).toContain('O texto não cita o motivo do acompanhamento');
        expect(wrapper.find('[data-testid="recall-dialog-submit"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.find('[data-testid="recall-dialog-submit"]').text()).toBe('Enviar convite');

        await wrapper.find('#recall-dialog-form').trigger('submit');

        const form = forms.find((entry) => entry.lastRequest);
        expect(form.lastRequest.method).toBe('post');
        expect(form.lastRequest.url).toBe('/_routes/panel.recall.invite/rc-1');
    });

    it('prévia sem teto: texto próprio; com último convite e próximo permitido, as datas', async () => {
        stubPreview(
            ok(
                preview({
                    daily: { used: 7, cap: null },
                    last_invite_at: '2026-01-10T10:00:00-03:00',
                    next_allowed_on: '2026-02-09',
                }),
            ),
        );
        const wrapper = mountPage();

        await wrapper.find('[data-testid="recall-invite"]').trigger('click');
        await flushPromises();

        const pacing = wrapper.find('[data-testid="invite-pacing"]').text();
        expect(pacing).toContain('Hoje: 7 convites da clínica.');
        expect(pacing).toContain('Último convite: 10/01/2026.');
        expect(pacing).toContain('Novo convite só a partir de 09/02/2026.');
    });

    it('prévia com impedimento: lista os motivos, orienta a ligar e NÃO deixa enviar', async () => {
        stubPreview(
            ok(
                preview({
                    can_send: false,
                    blockers: [
                        { code: 'no_consent', label: 'O paciente não autorizou lembretes de retorno por WhatsApp.' },
                    ],
                    phone: null,
                    message: null,
                }),
            ),
        );
        const wrapper = mountPage();

        await wrapper.find('[data-testid="recall-invite"]').trigger('click');
        await flushPromises();

        const blockers = wrapper.find('[data-testid="invite-blockers"]');
        expect(blockers.attributes('role')).toBe('alert');
        expect(blockers.text()).toContain('Este convite não pode sair agora:');
        expect(blockers.text()).toContain('O paciente não autorizou lembretes de retorno por WhatsApp.');
        expect(blockers.text()).toContain('Registre o contato por telefone');
        expect(wrapper.find('[data-testid="invite-message"]').exists()).toBe(false);

        const submit = wrapper.find('[data-testid="recall-dialog-submit"]');
        expect(submit.attributes('disabled')).toBeDefined();

        await wrapper.find('#recall-dialog-form').trigger('submit');
        expect(forms.some((entry) => entry.lastRequest)).toBe(false);
    });

    it('falha ao conferir (servidor/rede): avisa e não deixa enviar', async () => {
        stubPreview({ ok: false, status: 500, json: () => Promise.resolve({}) });
        const wrapper = mountPage();

        await wrapper.find('[data-testid="recall-invite"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="invite-dialog"] [role="alert"]').text()).toContain(
            'Não foi possível conferir',
        );
        expect(wrapper.find('[data-testid="recall-dialog-submit"]').attributes('disabled')).toBeDefined();

        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new Error('rede'))),
        );
        await wrapper.find('[data-testid="recall-dialog-submit"]').trigger('click');
        expect(forms.some((entry) => entry.lastRequest)).toBe(false);
    });

    it('só a última prévia vale: fechar e abrir outra linha ignora a resposta atrasada da primeira', async () => {
        let resolveFirst;
        const first = new Promise((resolve) => {
            resolveFirst = resolve;
        });
        const fetchMock = vi
            .fn()
            .mockReturnValueOnce(first)
            .mockReturnValueOnce(Promise.resolve(ok(preview({ phone: '(11) 9****-0002' }))));
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountPage({
            data: [row({ id: 'a' }), row({ id: 'b', patient: { ...row().patient, id: 'p-2', name: 'BIA' } })],
        });
        const buttons = wrapper.findAll('[data-testid="recall-invite"]');

        await buttons[0].trigger('click');
        await buttons[1].trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="invite-phone"]').text()).toContain('(11) 9****-0002');

        resolveFirst(ok(preview({ phone: '(11) 9****-0001' })));
        await flushPromises();
        await nextTick();

        expect(wrapper.find('[data-testid="invite-phone"]').text()).toContain('(11) 9****-0002');
        expect(wrapper.find('[data-testid="invite-phone"]').text()).not.toContain('0001');
    });

    it('cancelar fecha sem enviar nada', async () => {
        stubPreview(ok(preview()));
        const wrapper = mountPage();

        await wrapper.find('[data-testid="recall-invite"]').trigger('click');
        await flushPromises();
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Cancelar')
            .trigger('click');

        expect(wrapper.find('#recall-dialog-form').exists()).toBe(false);
        expect(forms.some((entry) => entry.lastRequest)).toBe(false);
    });
});

describe('Retornos — o que a linha diz do convite', () => {
    const show = (inviteProps, extra = {}) =>
        mount(RecallInviteStatus, {
            props: { invite: inviteProps, channelOpen: true, whatsapp: true, t: recallListT, ...extra },
        });

    const last = (overrides = {}) => ({
        at: '2026-01-20T09:00:00-03:00',
        origin: 'manual',
        status: 'sent',
        response: null,
        responded_at: null,
        ...overrides,
    });

    it('entrega: fila, enviado, entregue, lido, não entregue, cancelado — em texto, com a origem', () => {
        const expected = {
            queued: 'Convite por WhatsApp na fila',
            sent: 'Convite por WhatsApp enviado em 20/01/2026',
            delivered: 'Convite entregue em 20/01/2026',
            read: 'Convite lido em 20/01/2026',
            failed: 'O convite por WhatsApp não foi entregue',
            cancelled: 'Convite cancelado antes de sair',
        };

        for (const [status, text] of Object.entries(expected)) {
            const wrapper = show(invite({ last: last({ status }) }));
            const delivery = wrapper.find('[data-testid="recall-invite-delivery"]').text();

            expect(delivery).toContain(text);
            expect(delivery).toContain('(enviado pela equipe)');
        }

        expect(show(invite({ last: last({ origin: 'auto' }) })).text()).toContain('(automático)');
    });

    it('"Quero agendar" no prazo vira selo em destaque; fora do prazo, só uma linha com a data', () => {
        const within = show(
            invite({
                requested: true,
                last: last({ response: 'wants_to_schedule', responded_at: '2026-01-21T10:00:00-03:00' }),
            }),
        );
        expect(within.find('[data-testid="recall-invite-requested"]').text()).toContain('Pediu para agendar');
        expect(within.find('[data-testid="recall-invite-requested"]').classes()).toContain('recall-tone--success');

        const old = show(
            invite({
                requested: false,
                last: last({ response: 'wants_to_schedule', responded_at: '2025-11-21T10:00:00-03:00' }),
            }),
        );
        expect(old.find('[data-testid="recall-invite-requested"]').exists()).toBe(false);
        expect(old.find('[data-testid="recall-invite-asked"]').text()).toContain('Pediu para agendar em 21/11/2025');
    });

    it('"Já agendei" pede para conferir a agenda', () => {
        const wrapper = show(
            invite({ last: last({ response: 'already_scheduled', responded_at: '2026-01-22T10:00:00-03:00' }) }),
        );

        expect(wrapper.find('[data-testid="recall-invite-asked-done"]').text()).toContain(
            'Disse que já agendou em 22/01/2026 — confira a agenda',
        );
    });

    it('sem autorização ou com SAIR: "só ligação" (com o canal aberto e celular WhatsApp)', () => {
        const noConsent = show(invite({ consent: false, can_try: false }));
        expect(noConsent.find('[data-testid="recall-invite-unreachable"]').text()).toContain(
            'Sem autorização de WhatsApp — só ligação',
        );

        const optedOut = show(invite({ opted_out: true, can_try: false }));
        expect(optedOut.find('[data-testid="recall-invite-unreachable"]').text()).toContain(
            'Respondeu SAIR — só ligação',
        );
    });

    it('não incomoda quem não usa o recurso: canal fechado ou paciente sem WhatsApp não mostram o aviso', () => {
        expect(
            show(invite({ consent: false }), { channelOpen: false })
                .find('[data-testid="recall-invite-unreachable"]')
                .exists(),
        ).toBe(false);
        expect(
            show(invite({ consent: false }), { whatsapp: false })
                .find('[data-testid="recall-invite-unreachable"]')
                .exists(),
        ).toBe(false);
        expect(show(null).find('[data-testid="recall-invite-status"]').exists()).toBe(false);
    });
});
