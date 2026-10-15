import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () => (await import('./inertiaMock.js')).inertiaMock());

const { __submitted, __router } = await import('@inertiajs/vue3');
const AuthorizationTab = (await import('@/Components/Surgery/Tabs/AuthorizationTab.vue')).default;

const stub = {
    template: '<div v-if="open"><slot name="header" /><slot /><slot name="footer" /></div>',
    props: ['open', 'size'],
};

const t = {
    ui: {
        authorization: {
            state: 'Situação',
            number: 'Nº',
            valid_until: 'Validade',
            request: 'Enviar para autorização',
            appeal: 'Enviar recurso',
            respond: 'Registrar resposta',
            waive: 'Dispensar',
            withdraw: 'Retirar pedido',
            add_note: 'Anotação',
            problems_title: 'Antes de enviar, falta:',
            events: 'Histórico',
            events_empty: 'Nenhum evento registrado.',
            respond_title: 'Registrar',
            type: 'Tipo',
            occurred_at: 'Quando',
            password: 'Senha',
            partial_hint: 'Informe quanto liberou.',
            item_answered: 'já respondido',
            scope_hint: 'Vale só para o que falta: :items.',
            item: 'Item',
            requested: 'Pedido',
            authorized: 'Autorizado',
            denial_reason: 'Motivo',
            save: 'Registrar',
            cancel: 'Cancelar',
            channel: 'Canal',
            protocol: 'Protocolo',
            note: 'Anotação',
            waive_reason: 'Por quê?',
            withdraw_title: 'Retirar',
            withdraw_reason: 'Motivo',
        },
        authorization_types: {
            authorized: 'Autorizada',
            partially_authorized: 'Parcial',
            denied: 'Negada',
            waived: 'Dispensar',
            note: 'Anotação',
            submitted: 'Enviada',
        },
    },
};

const section = (over = {}) => ({
    state: { status: 'pending', label: 'Aguardando resposta', number: null, valid_until: null, expired: false },
    problems: [],
    events: [],
    actions: { request: false, appeal: false, withdraw: true, respond: true, waive: true, note: true },
    items: [
        { kind: 'procedure', id: 'p1', label: '30306019 — Facoemulsificação', requested: 1, authorized: null },
        { kind: 'material', id: 'm1', label: 'Anel de tensão', requested: 2, authorized: null },
    ],
    channels: ['phone', 'portal'],
    event_types: [],
    ...over,
});

const surgicalCase = { revision: 4 };
const urls = { authorization: { request: '/auth/request', withdraw: '/auth/withdraw', record: '/auth/events' } };

function mountTab(over = {}) {
    return mount(AuthorizationTab, {
        props: { section: section(over), surgicalCase, can: {}, urls, t },
        global: { stubs: { CenteredModal: stub } },
    });
}

describe('AuthorizationTab', () => {
    it('só mostra as ações que o servidor liberou para o estado atual', () => {
        const wrapper = mountTab({
            actions: { request: true, appeal: false, withdraw: false, respond: false, waive: true, note: false },
        });

        expect(wrapper.find('[data-testid="auth-request"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="auth-respond"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="auth-withdraw"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="auth-waive"]').exists()).toBe(true);
    });

    it('lista o que falta e bloqueia o envio enquanto houver pendência', () => {
        const wrapper = mountTab({
            actions: { request: true, appeal: false, withdraw: false, respond: false, waive: false, note: false },
            problems: ['Informe ao menos um procedimento.'],
        });

        expect(wrapper.find('[data-testid="auth-problems"]').text()).toContain('Informe ao menos um procedimento.');
        expect(wrapper.find('[data-testid="auth-request"]').attributes('disabled')).toBeDefined();
    });

    it('envia o pedido com a revisão vista pela tela', async () => {
        __router.post.mockClear();
        const wrapper = mountTab({
            actions: { request: true, appeal: false, withdraw: false, respond: false, waive: false, note: false },
        });

        await wrapper.find('[data-testid="auth-request"]').trigger('click');

        expect(__router.post).toHaveBeenCalledWith('/auth/request', { expected_revision: 4 }, expect.any(Object));
    });

    it('registra autorização com número e validade; omite campos que não valem para o tipo', async () => {
        __submitted.length = 0;
        const wrapper = mountTab();

        await wrapper.find('[data-testid="auth-respond"]').trigger('click');
        await wrapper.find('[data-testid="auth-number"]').setValue('AUT-9');
        await wrapper.find('#auth-valid').setValue('2026-12-01');
        await wrapper.find('[data-testid="auth-save"]').trigger('click');

        const sent = __submitted.at(-1);
        expect(sent.url).toBe('/auth/events');
        expect(sent.payload).toMatchObject({
            type: 'authorized',
            authorization_number: 'AUT-9',
            valid_until: '2026-12-01',
            expected_revision: 4,
        });
        expect(sent.payload.denial_reason).toBeUndefined();
        expect(sent.payload.authorized_items).toBeUndefined();
    });

    it('autorização parcial envia a quantidade liberada por item', async () => {
        __submitted.length = 0;
        const wrapper = mountTab();

        await wrapper.find('[data-testid="auth-respond"]').trigger('click');
        await wrapper.find('[data-testid="auth-type"]').setValue('partially_authorized');
        await wrapper.find('[data-testid="auth-number"]').setValue('P-1');

        const inputs = wrapper.findAll('table input[type="number"]');
        await inputs[1].setValue(1);
        await wrapper.find('[data-testid="auth-save"]').trigger('click');

        const items = __submitted.at(-1).payload.authorized_items;
        expect(items).toEqual([
            { kind: 'procedure', id: 'p1', quantity: 1 },
            { kind: 'material', id: 'm1', quantity: 1 },
        ]);
    });

    it('item que a operadora já respondeu (outra guia do pedido) aparece travado e não é refeito pelo registro manual', async () => {
        __submitted.length = 0;
        const wrapper = mountTab({
            items: [
                {
                    kind: 'procedure',
                    id: 'p1',
                    label: '30306019 — Facoemulsificação',
                    requested: 2,
                    authorized: 1,
                    locked: true,
                },
                { kind: 'material', id: 'm1', label: 'Anel de tensão', requested: 2, authorized: null, locked: false },
            ],
        });

        await wrapper.find('[data-testid="auth-respond"]').trigger('click');

        // O registro "autorizado" (sem lista de itens) também avisa que vale só para o que falta.
        expect(wrapper.find('[data-testid="auth-scope-hint"]').text()).toBe(
            'Vale só para o que falta: Anel de tensão.',
        );

        await wrapper.find('[data-testid="auth-type"]').setValue('partially_authorized');
        await wrapper.find('[data-testid="auth-number"]').setValue('P-2');

        const inputs = wrapper.findAll('table input[type="number"]');

        // O respondido mostra o que a operadora liberou (1 de 2), desabilitado e marcado; o aberto vem com o pedido.
        expect(inputs[0].element.value).toBe('1');
        expect(inputs[0].attributes('disabled')).toBeDefined();
        expect(inputs[1].attributes('disabled')).toBeUndefined();
        expect(wrapper.find('table').text()).toContain('já respondido');

        await inputs[1].setValue(1);
        await wrapper.find('[data-testid="auth-save"]').trigger('click');

        expect(__submitted.at(-1).payload.authorized_items).toEqual([
            { kind: 'procedure', id: 'p1', quantity: 1 },
            { kind: 'material', id: 'm1', quantity: 1 },
        ]);
    });

    it('negativa pede o motivo e não leva número de autorização', async () => {
        __submitted.length = 0;
        const wrapper = mountTab();

        await wrapper.find('[data-testid="auth-respond"]').trigger('click');
        await wrapper.find('[data-testid="auth-type"]').setValue('denied');
        await wrapper.find('[data-testid="auth-denial"]').setValue('Fora da cobertura');
        await wrapper.find('[data-testid="auth-save"]').trigger('click');

        const payload = __submitted.at(-1).payload;
        expect(payload.denial_reason).toBe('Fora da cobertura');
        expect(payload.authorization_number).toBeUndefined();
    });

    it('mostra o log de eventos com o item autorizado pelo nome', () => {
        const wrapper = mountTab({
            events: [
                {
                    id: 'e1',
                    type: 'authorized',
                    label: 'Autorizada',
                    occurred_at: '2026-10-15T10:00:00-03:00',
                    number: 'AUT-1',
                    valid_until: '2026-11-30',
                    authorized_items: [{ kind: 'procedure', id: 'p1', quantity: 1 }],
                    denial_reason: null,
                    protocol_number: null,
                    channel: 'portal',
                    note: null,
                    recorded_by: 'Maria',
                },
            ],
        });

        const text = wrapper.find('[data-testid="auth-event"]').text();
        expect(text).toContain('Autorizada');
        expect(text).toContain('Maria');
        expect(text).toContain('Facoemulsificação');
    });
});
