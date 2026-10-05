import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import SubscriptionExtendModal from '@/Pages/Panel/Manager/Subscriptions/SubscriptionExtendModal.vue';
import SubscriptionCreateModal from '@/Pages/Panel/Manager/Subscriptions/SubscriptionCreateModal.vue';
import SubscriptionTermsModal from '@/Pages/Panel/Manager/Subscriptions/SubscriptionTermsModal.vue';

/**
 * Modais de assinatura do manager: prévia do novo término (mesma regra do
 * servidor), justificativa obrigatória e o que vai para a API.
 */
const t = {
    request_failed: 'Falhou',
    btn_cancel: 'Cancelar',
    extend_title: 'Adicionar período',
    extend_current_end: 'Término atual',
    extend_trial_end: 'Trial até',
    extend_from_today: 'Já venceu',
    extend_from_end: 'Soma ao término',
    extend_reactivate: 'Volta a valer',
    extend_custom: 'Outro período',
    extend_quantity: 'Quantidade',
    extend_unit: 'Unidade',
    extend_new_end: 'Novo término',
    extend_gateway_warning: 'Gateway :gateway segue cobrando',
    btn_extend: 'Adicionar',
    unit_days: 'dias',
    unit_months: 'meses',
    unit_years: 'anos',
    period_chip_days: '+:n dia|+:n dias',
    period_chip_months: '+:n mês|+:n meses',
    period_chip_years: '+:n ano|+:n anos',
    field_reason: 'Justificativa',
    field_reason_optional: 'Justificativa (opcional)',
    create_title: 'Nova assinatura',
    field_company: 'Empresa',
    field_plan: 'Plano',
    field_mode: 'Modalidade',
    field_cycle: 'Ciclo',
    field_starts_at: 'Início',
    field_ends_at: 'Término',
    field_trial_days: 'Dias',
    quick_period: 'Duração',
    btn_create: 'Criar',
    company_current: 'Atual: :plan · :modality · :status',
    company_current_no_end: 'sem término',
    company_replace_warning: 'Será substituída.',
    company_replace_gateway: 'Cobrança automática será cancelada.',
    company_none: 'Sem assinatura.',
    period_until: 'até :date',
    trial_days_hint: 'Padrão :days',
    modality: {
        trial: 'Trial',
        gateway: 'Automática',
        complimentary: 'Cortesia',
    },
    modality_hint: { complimentary: 'Sem cobrança até a data escolhida.' },
    terms_title: 'Alterar assinatura',
    terms_intro: 'Mude o plano ou o período.',
    terms_gateway_hint: 'Para cobrar de novo, crie uma nova assinatura.',
    terms_gateway_warning: 'A cobrança no gateway :gateway será cancelada.',
    terms_past_end_warning: 'Término no passado.',
    action_new_for_company: 'Nova assinatura para a empresa',
    btn_save_terms: 'Salvar alterações',
};

const REASON = 'Cortesia combinada na implantação (ticket #1).';

let wrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
});

beforeEach(() => {
    window.axios = vi.fn().mockResolvedValue({ data: { message: 'ok' } });
    window.axios.get = vi.fn();
});

describe('Adicionar período', () => {
    const subscription = {
        id: 'sub-1',
        entity_name: 'Clínica Visão',
        plan_name: 'Pro',
        modality: 'complimentary',
        status: 'active',
        access_ends_at: '2027-01-20T23:59:59',
        has_gateway_recurrence: false,
    };

    async function open(sub = subscription) {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(2027, 0, 10, 12, 0, 0));

        wrapper = mount(SubscriptionExtendModal, {
            props: { open: false, subscription: sub, t },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
    }

    const dialog = () => document.body.querySelector('.ee-modal__dialog');
    const button = (text) => [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === text);

    it('soma ao término atual e mostra o novo término antes de salvar', async () => {
        await open();

        button('+3 meses').click();
        await flushPromises();

        expect(dialog().querySelector('.extend-preview').textContent).toContain('20/04/2027');
        expect(dialog().textContent).toContain('Soma ao término');
    });

    it('só envia com justificativa e manda unidade, quantidade e motivo', async () => {
        await open();

        expect(button('Adicionar').disabled).toBe(true);

        const textarea = dialog().querySelector('textarea');
        textarea.value = REASON;
        textarea.dispatchEvent(new Event('input'));
        button('+15 dias').click();
        await flushPromises();
        expect(button('Adicionar').disabled).toBe(false);

        button('Adicionar').click();
        await flushPromises();

        expect(window.axios).toHaveBeenCalledWith(
            expect.objectContaining({
                method: 'post',
                url: '/_routes/manager.subscriptions.extend/sub-1',
                data: { unit: 'days', quantity: 15, reason: REASON },
            }),
        );
        expect(wrapper.emitted('saved')?.[0]).toEqual(['ok']);
    });

    it('avisa que a cobrança automática segue o calendário do gateway', async () => {
        await open({ ...subscription, modality: 'gateway', gateway: 'asaas', has_gateway_recurrence: true });

        expect(dialog().textContent).toContain('Gateway ASAAS segue cobrando');
    });

    it('mostra o erro de regra devolvido pelo servidor', async () => {
        window.axios = vi.fn().mockRejectedValue({
            response: { status: 422, data: { errors: { subscription: ['Esta assinatura não tem término.'] } } },
        });
        await open();

        const textarea = dialog().querySelector('textarea');
        textarea.value = REASON;
        textarea.dispatchEvent(new Event('input'));
        await flushPromises();
        button('Adicionar').click();
        await flushPromises();

        expect(dialog().querySelector('[role="alert"]').textContent).toContain('Esta assinatura não tem término.');
    });
});

describe('Nova assinatura', () => {
    const plans = [
        {
            id: 'plan-pro',
            name: 'Pro',
            active: true,
            default_cycle: 'monthly',
            prices: [
                { cycle: 'monthly', label: 'Mensal', months: 1, price: 300 },
                { cycle: 'yearly', label: 'Anual', months: 12, price: 3000 },
            ],
        },
    ];
    const billingCycles = [
        { value: 'monthly', label: 'Mensal', period_label: '/mês', months: 1 },
        { value: 'yearly', label: 'Anual', period_label: '/ano', months: 12 },
    ];
    const clinic = {
        id: 'clinic-1',
        name: 'Clínica Visão',
        active: true,
        current: {
            plan_name: 'Pro',
            modality: 'gateway',
            status_label: 'Ativo',
            has_access: true,
            open_ended: false,
            access_ends_at: '2027-02-01T00:00:00',
        },
    };

    async function open(preset = {}) {
        window.axios.get = vi.fn().mockResolvedValue({ data: { data: [clinic] } });
        wrapper = mount(SubscriptionCreateModal, {
            props: { open: false, plans, billingCycles, gateways: [], trialDays: 7, preset, t },
            // route() no template vem do ZiggyVue no app.
            global: { mocks: { route: globalThis.route } },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
    }

    const dialog = () => document.body.querySelector('.ee-modal__dialog');
    const createButton = () => [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Criar');

    function chooseMode(mode) {
        const radio = dialog().querySelector(`[data-mode="${mode}"] input`);
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
    }

    it('avisa que a assinatura atual (com cobrança automática) será substituída', async () => {
        await open({ entity_id: 'clinic-1', plan_id: 'plan-pro' });

        const status = dialog().querySelector('[data-test="company-status"]').textContent;
        expect(status).toContain('Atual: Pro · Automática · Ativo');
        expect(status).toContain('Será substituída.');
        expect(status).toContain('Cobrança automática será cancelada.');
    });

    it('cortesia exige justificativa e término; trial não pede justificativa', async () => {
        await open({ entity_id: 'clinic-1', plan_id: 'plan-pro', mode: 'complimentary' });

        expect(dialog().querySelector('#sub-ends')).not.toBeNull();
        expect(createButton().disabled).toBe(true);

        chooseMode('trial');
        await flushPromises();
        expect(dialog().querySelector('#sub-ends')).toBeNull();
        expect(createButton().disabled).toBe(false);
    });

    it('empresa com assinatura antiga sem término mostra "sem término"', async () => {
        clinic.current.open_ended = true;
        try {
            await open({ entity_id: 'clinic-1', plan_id: 'plan-pro' });

            expect(dialog().querySelector('[data-test="company-status"]').textContent).toContain('sem término');
        } finally {
            clinic.current.open_ended = false;
        }
    });

    it('cortesia: atalho de duração define o término e vai junto com a justificativa', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(2027, 0, 10, 12, 0, 0));
        await open({ entity_id: 'clinic-1', plan_id: 'plan-pro', mode: 'complimentary' });

        const chip = [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === '+3 meses');
        chip.click();
        await flushPromises();
        expect(dialog().querySelector('#sub-ends').value).toBe('2027-04-10');

        const textarea = dialog().querySelector('textarea');
        textarea.value = REASON;
        textarea.dispatchEvent(new Event('input'));
        await flushPromises();
        createButton().click();
        await flushPromises();

        expect(window.axios.mock.calls[0][0].data).toEqual({
            entity_id: 'clinic-1',
            plan_id: 'plan-pro',
            mode: 'complimentary',
            reason: REASON,
            starts_at: '2027-01-10',
            ends_at: '2027-04-10',
        });
    });

    it('não oferece "pago por fora" nem vitalícia', async () => {
        await open({ entity_id: 'clinic-1', plan_id: 'plan-pro' });

        const modes = [...dialog().querySelectorAll('[data-mode]')].map((el) => el.dataset.mode);
        expect(modes).toEqual(['trial', 'gateway', 'complimentary']);
    });

    describe('cobrança automática em quem já paga: troca de plano com prévia', () => {
        const changeT = {
            change_preview_loading: 'Calculando…',
            change_preview_title_upgrade: 'Upgrade imediato',
            change_preview_upgrade: 'Fatura de :amount (:days dias); depois :next_amount em :next.',
            change_preview_title_scheduled: 'Mudança no fim do período',
            change_preview_scheduled: 'Vale a partir de :date (:plan, :cycle) — :amount.',
            change_preview_gateway_kept: 'Mantém :gateway.',
            change_preview_unpaid_note: 'Não paga, nada muda.',
            change_preview_same: 'Já está neste plano.',
            btn_change_upgrade: 'Gerar fatura do upgrade',
            btn_change_schedule: 'Agendar mudança',
        };

        async function openWithPreview(change) {
            window.axios.get = vi.fn((url) =>
                Promise.resolve(
                    url.includes('change-preview')
                        ? { data: { data: { change, gateway: 'mercadopago' } } }
                        : { data: { data: [{ ...clinic, current: { ...clinic.current, id: 'sub-paid' } }] } },
                ),
            );
            wrapper = mount(SubscriptionCreateModal, {
                props: {
                    open: false,
                    plans,
                    billingCycles,
                    gateways: [],
                    trialDays: 7,
                    preset: { entity_id: 'clinic-1', plan_id: 'plan-pro', mode: 'gateway' },
                    t: { ...t, ...changeT },
                },
                global: { mocks: { route: globalThis.route } },
                attachTo: document.body,
            });
            await wrapper.setProps({ open: true });
            await flushPromises();
        }

        it('upgrade: mostra o valor proporcional e não fala em substituir; o botão gera a fatura', async () => {
            await openWithPreview({
                type: 'upgrade',
                amount_now: 154.84,
                remaining_days: 16,
                new_amount: 600,
                next_charge_at: '2026-11-01',
            });

            expect(window.axios.get).toHaveBeenCalledWith(
                expect.stringContaining(
                    'manager.subscriptions.change-preview?entity_id=clinic-1&plan_id=plan-pro&billing_cycle=monthly',
                ),
                expect.anything(),
            );
            const preview = dialog().querySelector('[data-test="plan-change-preview"]');
            expect(preview.dataset.type).toBe('upgrade');
            expect(preview.textContent.replace(/\u00a0/g, ' ')).toContain('Fatura de R$ 154,84 (16 dias)');
            expect(preview.textContent).toContain('Mantém mercadopago.');
            expect(dialog().querySelector('[data-test="company-status"]').textContent).not.toContain(
                'Será substituída.',
            );
            expect(document.body.textContent).toContain('Gerar fatura do upgrade');
        });

        it('downgrade: mostra a data; mesmo plano bloqueia o envio', async () => {
            await openWithPreview({
                type: 'scheduled',
                reason: 'downgrade',
                effective_at: '2026-11-01T23:59:59-03:00',
                new_amount: 150,
                plan: { name: 'Basic' },
                cycle: 'monthly',
            });

            expect(dialog().querySelector('[data-test="plan-change-preview"]').textContent).toContain(
                '01/11/2026 (Basic, Mensal)',
            );
            expect(document.body.textContent).toContain('Agendar mudança');

            wrapper.unmount();
            await openWithPreview({ type: 'current' });

            const submit = [...document.body.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Criar');
            expect(submit.disabled).toBe(true);
        });

        it('prévia falhou (rede/422): não envia — mostra o erro e "Tentar de novo"; com a prévia, libera a troca', async () => {
            let fail = true;
            window.axios.get = vi.fn((url) => {
                if (!url.includes('change-preview')) {
                    return Promise.resolve({
                        data: { data: [{ ...clinic, current: { ...clinic.current, id: 'sub-paid' } }] },
                    });
                }
                if (fail) return Promise.reject({ response: { status: 422, data: { message: 'Ciclo não vendido.' } } });

                return Promise.resolve({
                    data: {
                        data: {
                            change: {
                                type: 'upgrade',
                                amount_now: 10,
                                remaining_days: 3,
                                new_amount: 600,
                                next_charge_at: '2026-11-01',
                            },
                            gateway: 'mercadopago',
                        },
                    },
                });
            });
            wrapper = mount(SubscriptionCreateModal, {
                props: {
                    open: false,
                    plans,
                    billingCycles,
                    gateways: [{ value: 'asaas', label: 'Asaas' }],
                    trialDays: 7,
                    preset: { entity_id: 'clinic-1', plan_id: 'plan-pro', mode: 'gateway' },
                    t: { ...t, ...changeT, change_preview_retry: 'Tentar de novo' },
                },
                global: { mocks: { route: globalThis.route } },
                attachTo: document.body,
            });
            await wrapper.setProps({ open: true });
            await flushPromises();

            // Sem prévia o botão diria "Criar", mas o servidor faria a troca: bloqueado.
            const footerButton = () => [...dialog().parentElement.querySelectorAll('button.btn-primary')].at(-1);
            expect(dialog().querySelector('[data-test="plan-change-error"]').textContent).toContain(
                'Ciclo não vendido.',
            );
            expect(document.body.textContent).toContain('Criar');
            expect(footerButton().disabled).toBe(true);
            // Sem saber se é troca, não oferece escolher outro gateway.
            expect(dialog().querySelector('[data-test="gateway-field"]')).toBeNull();

            fail = false;
            dialog().querySelector('[data-test="plan-change-retry"]').click();
            await flushPromises();

            expect(dialog().querySelector('[data-test="plan-change-error"]')).toBeNull();
            expect(dialog().querySelector('[data-test="plan-change-preview"]').dataset.type).toBe('upgrade');
            expect(footerButton().textContent.trim()).toBe('Gerar fatura do upgrade');
            expect(footerButton().disabled).toBe(false);
            expect(dialog().querySelector('[data-test="gateway-field"]')).toBeNull();

            footerButton().click();
            await flushPromises();

            const sent = window.axios.mock.calls[0][0].data;
            expect(sent).toMatchObject({
                entity_id: 'clinic-1',
                plan_id: 'plan-pro',
                mode: 'gateway',
                billing_cycle: 'monthly',
            });
            expect(sent.gateway).toBeUndefined();
        });

        it('prévia carregando: envio bloqueado e sem campo de gateway', async () => {
            window.axios.get = vi.fn((url) =>
                url.includes('change-preview')
                    ? new Promise(() => {})
                    : Promise.resolve({
                          data: { data: [{ ...clinic, current: { ...clinic.current, id: 'sub-paid' } }] },
                      }),
            );
            wrapper = mount(SubscriptionCreateModal, {
                props: {
                    open: false,
                    plans,
                    billingCycles,
                    gateways: [{ value: 'asaas', label: 'Asaas' }],
                    trialDays: 7,
                    preset: { entity_id: 'clinic-1', plan_id: 'plan-pro', mode: 'gateway' },
                    t: { ...t, ...changeT },
                },
                global: { mocks: { route: globalThis.route } },
                attachTo: document.body,
            });
            await wrapper.setProps({ open: true });
            await flushPromises();

            expect(dialog().querySelector('[data-test="plan-change-loading"]')).not.toBeNull();
            expect(createButton().disabled).toBe(true);
            expect(dialog().querySelector('[data-test="gateway-field"]')).toBeNull();
        });
    });
});

describe('Alterar assinatura', () => {
    const plans = [{ id: 'plan-pro', name: 'Pro', active: true, prices: [] }];
    const subscription = {
        id: 'sub-9',
        entity_name: 'Clínica Visão',
        plan_id: 'plan-pro',
        modality: 'gateway',
        status: 'active',
        has_access: true,
        gateway: 'asaas',
        has_gateway_recurrence: true,
        starts_at: '2027-01-01T12:00:00',
        ends_at: '2027-03-01T23:59:59',
    };

    async function open(sub = subscription) {
        wrapper = mount(SubscriptionTermsModal, {
            props: { open: false, subscription: sub, plans, t },
            global: { mocks: { route: globalThis.route } },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
    }

    const dialog = () => document.body.querySelector('.ee-modal__dialog');
    const button = (label) => [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === label);

    it('sempre vira cortesia com término: sem escolha de modalidade', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(2027, 0, 10, 12, 0, 0));
        await open();

        expect(dialog().querySelectorAll('[data-mode]')).toHaveLength(0);
        expect(dialog().querySelector('[data-test="terms-modality"]').textContent).toContain('Cortesia');
        expect(dialog().textContent).toContain('A cobrança no gateway ASAAS será cancelada.');
        expect(dialog().querySelector('#terms-ends').value).toBe('2027-03-01');

        const textarea = dialog().querySelector('textarea');
        textarea.value = REASON;
        textarea.dispatchEvent(new Event('input'));
        await flushPromises();
        button('Salvar alterações').click();
        await flushPromises();

        expect(window.axios.mock.calls[0][0]).toMatchObject({
            method: 'put',
            data: {
                plan_id: 'plan-pro',
                mode: 'complimentary',
                starts_at: '2027-01-01',
                ends_at: '2027-03-01',
                reason: REASON,
            },
        });
    });

    it('assinatura antiga sem término mantém o início e sugere término em um mês', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(2027, 0, 31, 12, 0, 0));
        await open({ ...subscription, modality: 'complimentary', has_gateway_recurrence: false, ends_at: null });

        expect(dialog().querySelector('#terms-starts').value).toBe('2027-01-01');
        expect(dialog().querySelector('#terms-ends').value).toBe('2027-02-28');
    });

    it('leva para "nova assinatura" quando é para voltar a cobrar', async () => {
        await open();

        button('Nova assinatura para a empresa').click();
        await flushPromises();

        expect(wrapper.emitted('createNew')).toHaveLength(1);
    });
});
