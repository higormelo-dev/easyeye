import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import DoctorPayoutShow from '@/Pages/Panel/Financial/DoctorPayouts/Show.vue';
import { t, statement, payoutSummary, brl } from './fixtures.js';

/**
 * Demonstrativo (clínica): o servidor decide o que aparece (`permissions`).
 * Fechado → ajustes + registrar pagamento; pago → dados do pagamento; só
 * admin estorna/reabre, com motivo (DELETE com o motivo no corpo); quem não é
 * admin vê o aviso. Cancelado → aviso com data, usuário e motivo.
 */

const inertia = vi.hoisted(() => ({ pageProps: null, forms: [] }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.pageProps = reactive({ locale: 'pt_BR', flash: {}, errors: {}, t_ui: { close: 'Close' } });

    return {
        usePage: () => ({ props: inertia.pageProps }),
        router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        useForm: (data) => {
            const initial = { ...data };
            const fields  = Object.keys(data);
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                data: () => Object.fromEntries(fields.map((field) => [field, form[field]])),
                reset: () => Object.assign(form, initial),
                clearErrors: () => { form.errors = {}; },
                post: vi.fn(),
            });
            inertia.forms.push(form);

            return form;
        },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({ default: { props: ['title'], template: '<div><h4>{{ title }}</h4><slot name="actions" /></div>' } }));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd"><slot name="trigger" /><ul><slot /></ul></div>' },
}));
vi.mock('@/Components/Panel/ConfirmationWithReasonModal.vue', () => ({
    default: {
        props: ['open', 'title', 'message', 'confirmLabel', 'confirmVariant', 'saving', 'minLength', 'maxLength', 'error'],
        emits: ['close', 'confirm'],
        template: `<div v-if="open" class="reason-modal-stub" :data-min="minLength" :data-max="maxLength">
            <h5>{{ title }}</h5><p data-test="reason-message">{{ message }}</p>
            <p v-if="error" data-test="reason-error">{{ error }}</p>
            <button type="button" data-test="reason-confirm" @click="$emit('confirm', 'Pagamento lançado em duplicidade')">{{ confirmLabel }}</button>
            <button type="button" data-test="reason-close" @click="$emit('close')"></button>
        </div>`,
    },
}));

const routes = {
    closings: '/doctor-payouts/closings',
    apuracao: '/doctor-payouts?doctor=d1&from=2026-08-01&to=2026-08-31',
    pdf: '/doctor-payouts/closings/po1/pdf',
    export: '/doctor-payouts/closings/po1/export',
    pay: '/doctor-payouts/closings/po1/payment',
    reverse: '/doctor-payouts/closings/po1/payment',
    reopen: '/doctor-payouts/closings/po1',
    adjustments_store: '/doctor-payouts/closings/po1/adjustments',
    adjustments_destroy: '/doctor-payouts/closings/po1/adjustments/__ID__',
    cash_flow: null,
};

const CLOSED_FINANCIAL = { can_adjust: true, can_pay: true, can_reverse: false, can_reopen: false, is_admin: false };
const CLOSED_ADMIN     = { can_adjust: true, can_pay: true, can_reverse: false, can_reopen: true, is_admin: true };
const PAID_ADMIN       = { can_adjust: false, can_pay: false, can_reverse: true, can_reopen: false, is_admin: true };
const PAID_FINANCIAL   = { can_adjust: false, can_pay: false, can_reverse: false, can_reopen: false, is_admin: false };

const paidStatement = {
    ...statement,
    payout: {
        ...payoutSummary, status: 'paid', paid_at: '2026-09-05', paid_amount: 150, payment_method: 'transfer',
        payment_notes: 'PIX 123', cash_entry_id: 'ce1',
    },
};

let wrapper;

function mountPage(props = {}) {
    wrapper = mount(DoctorPayoutShow, {
        props: {
            breadcrumbs: [],
            tabs: { apuracao: '/doctor-payouts', closings: '/doctor-payouts/closings', rules: '/doctor-payouts/rules' },
            statement,
            permissions: CLOSED_FINANCIAL,
            payment_methods: [{ value: 'transfer', label: 'Bank transfer' }, { value: 'cash', label: 'Cash' }],
            today: '2026-09-28',
            reason_limits: { min: 10, max: 1000 },
            routes,
            t,
            shared: {},
            ...props,
        },
    });

    return wrapper;
}

const has = (w, test) => w.find(`[data-test="${test}"]`).exists();

beforeEach(() => {
    inertia.pageProps.flash = {};
    vi.mocked(router.delete).mockReset();
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    inertia.forms.length = 0;
    vi.unstubAllGlobals();
});

describe('Financial/DoctorPayouts/Show — demonstrativo da clínica', () => {
    it('cabeçalho, grupos com subtotal, ajustes e totais do fechamento', () => {
        const w = mountPage();

        expect(w.find('[data-test="statement-code"]').text()).toBe('RM-000001');
        expect(w.find('[data-test="statement-header"] [data-status="closed"]').text()).toBe('Closed');
        expect(w.find('[data-test="statement-doctor"]').text()).toBe('Dra. Ana Lima');
        expect(w.find('[data-test="statement-period"]').text()).toBe('01/08/2026 – 31/08/2026');
        expect(w.text()).toContain('Carla Financeiro');
        expect(w.text()).toContain('Conferido');

        const groups = w.findAll('[data-test="statement-group"]');
        expect(groups.map((g) => g.attributes('data-type'))).toEqual(['consultation', 'procedure']);
        expect(groups[0].find('h3').text()).toContain('Consultations');
        expect(groups[0].find('[data-test="group-subtotal"]').text()).toContain(brl(180));
        expect(groups[0].findAll('[data-test="item-row"]')).toHaveLength(1);

        expect(w.find('[data-test="adjustment-amount"]').text()).toBe(brl(-30, true));
        expect(w.find('[data-test="total-gross"]').text()).toBe(brl(750.5));
        expect(w.find('[data-test="total-items"]').text()).toBe(brl(180));
        expect(w.find('[data-test="total-adjustments"]').text()).toBe(brl(-30, true));
        expect(w.find('[data-test="total-net"]').text()).toBe(brl(150));
    });

    it('PDF, exportação e voltar (fechamentos / apuração do período)', () => {
        const w = mountPage();

        expect(w.find('[data-test="statement-pdf"]').attributes('href')).toBe('/doctor-payouts/closings/po1/pdf');
        expect(w.findAll('.dd a').map((a) => a.attributes('href'))).toEqual([
            '/doctor-payouts/closings/po1/export?format=csv',
            '/doctor-payouts/closings/po1/export?format=xlsx',
        ]);
        expect(w.find('[data-test="back-closings"]').attributes('href')).toBe('/doctor-payouts/closings');
        expect(w.find('[data-test="back-apuracao"]').attributes('href')).toBe(routes.apuracao);
        expect(w.find('[data-test="tab-closings"]').attributes('aria-current')).toBe('page');
    });

    it('fechado, financeiro sem admin: ajustes e pagamento; sem estornar/reabrir, com o aviso', () => {
        const w = mountPage({ permissions: CLOSED_FINANCIAL });

        expect(has(w, 'adjustment-form')).toBe(true);
        expect(has(w, 'adjustment-remove')).toBe(true);
        expect(has(w, 'payment-form')).toBe(true);
        expect(has(w, 'reverse-open')).toBe(false);
        expect(has(w, 'reopen-open')).toBe(false);
        expect(w.find('[data-test="admin-only"]').text()).toBe('Only administrators can perform this operation.');
    });

    it('fechado + admin: "Reabrir" pede motivo e envia DELETE com o motivo', async () => {
        const w = mountPage({ permissions: CLOSED_ADMIN });

        expect(has(w, 'reverse-open')).toBe(false);
        expect(has(w, 'admin-only')).toBe(false);

        await w.find('[data-test="reopen-open"]').trigger('click');

        const modal = w.find('.reason-modal-stub');
        expect(modal.find('h5').text()).toBe('Reopen (cancel) this closing?');
        expect(modal.find('[data-test="reason-message"]').text()).toBe('The attendances become pending again.');
        expect(modal.attributes('data-min')).toBe('10');
        expect(modal.attributes('data-max')).toBe('1000');

        await modal.find('[data-test="reason-confirm"]').trigger('click');

        expect(router.delete).toHaveBeenCalledWith('/doctor-payouts/closings/po1', expect.objectContaining({
            data: { reason: 'Pagamento lançado em duplicidade' },
            preserveScroll: true,
        }));
    });

    it('sucesso fecha o modal; erro de validação (motivo/status) fica dentro dele', async () => {
        vi.mocked(router.delete)
            .mockImplementationOnce((url, options) => { options.onError?.({ status: 'This operation is not allowed.' }); options.onFinish?.(); })
            .mockImplementationOnce((url, options) => { options.onSuccess?.({ props: { flash: {} } }); options.onFinish?.(); });
        const w = mountPage({ permissions: CLOSED_ADMIN });

        await w.find('[data-test="reopen-open"]').trigger('click');
        await w.find('[data-test="reason-confirm"]').trigger('click');
        expect(w.find('[data-test="reason-error"]').text()).toBe('This operation is not allowed.');

        await w.find('[data-test="reason-confirm"]').trigger('click');
        expect(w.find('.reason-modal-stub').exists()).toBe(false);
    });

    it('recusa por permissão (redirect com flash de erro) aparece no modal', async () => {
        vi.mocked(router.delete).mockImplementation((url, options) => {
            options.onSuccess?.({ props: { flash: { error: 'Only administrators can perform this operation.' } } });
            options.onFinish?.();
        });
        const w = mountPage({ permissions: CLOSED_ADMIN });

        await w.find('[data-test="reopen-open"]').trigger('click');
        await w.find('[data-test="reason-confirm"]').trigger('click');

        expect(w.find('[data-test="reason-error"]').text()).toBe('Only administrators can perform this operation.');
    });

    it('pago + admin: dados do pagamento e "Estornar pagamento"; sem formulário de pagamento nem ajustes', async () => {
        const w = mountPage({ statement: paidStatement, permissions: PAID_ADMIN, routes: { ...routes, cash_flow: '/cash-flow?from=2026-09-05&to=2026-09-05' } });

        expect(w.find('[data-test="payment-paid"]').text()).toContain('Paid on 05/09/2026');
        expect(w.find('[data-test="payment-amount"]').text()).toBe(brl(150));
        expect(w.find('[data-test="payment-method"]').text()).toBe('Bank transfer');
        expect(w.find('[data-test="payment-cash-flow"]').attributes('href')).toBe('/cash-flow?from=2026-09-05&to=2026-09-05');
        expect(w.text()).toContain('PIX 123');
        expect(has(w, 'payment-form')).toBe(false);
        expect(has(w, 'adjustment-form')).toBe(false);
        expect(has(w, 'adjustment-remove')).toBe(false);
        expect(has(w, 'reopen-open')).toBe(false);

        await w.find('[data-test="reverse-open"]').trigger('click');
        expect(w.find('.reason-modal-stub h5').text()).toBe('Reverse the payment of this payout?');

        await w.find('[data-test="reason-confirm"]').trigger('click');
        expect(router.delete).toHaveBeenCalledWith('/doctor-payouts/closings/po1/payment', expect.objectContaining({
            data: { reason: 'Pagamento lançado em duplicidade' },
        }));
    });

    it('pago sem admin: nada de estorno, só o aviso', () => {
        const w = mountPage({ statement: paidStatement, permissions: PAID_FINANCIAL });

        expect(has(w, 'reverse-open')).toBe(false);
        expect(has(w, 'reopen-open')).toBe(false);
        expect(has(w, 'admin-only')).toBe(true);
    });

    it('cancelado: aviso com data, usuário e motivo; sem pagamento nem ações', () => {
        const w = mountPage({
            statement: {
                ...statement,
                payout: {
                    ...payoutSummary, status: 'cancelled', cancelled_at: '2026-09-10T09:00:00-03:00',
                    cancelled_by_name: 'Admin Clínica', cancel_reason: 'Agenda do médico errada',
                },
            },
            permissions: { can_adjust: false, can_pay: false, can_reverse: false, can_reopen: false, is_admin: true },
        });

        const notice = w.find('[data-test="statement-cancelled"]').text();
        expect(notice).toContain('Closing cancelled on');
        expect(notice).toContain('by Admin Clínica');
        expect(notice).toContain('Reason: Agenda do médico errada');
        expect(has(w, 'payment-panel')).toBe(false);
        expect(has(w, 'admin-actions')).toBe(false);
    });

    it('pagamento estornado antes: aviso com usuário e motivo', () => {
        const w = mountPage({
            statement: {
                ...statement,
                payout: {
                    ...payoutSummary, payment_reversed_at: '2026-09-12T11:00:00-03:00',
                    payment_reversed_by_name: 'Admin Clínica', payment_reversal_reason: 'Conta errada',
                },
            },
        });

        expect(w.find('[data-test="statement-reversed"]').text()).toContain('by Admin Clínica. Reason: Conta errada');
    });

    it('remover ajuste pede confirmação e apaga pela rota do ajuste', async () => {
        const confirm = vi.fn(() => true);
        vi.stubGlobal('confirm', confirm);
        const w = mountPage();

        await w.find('[data-test="adjustment-remove"]').trigger('click');

        expect(confirm).toHaveBeenCalledWith('Remove this adjustment?');
        expect(router.delete).toHaveBeenCalledWith('/doctor-payouts/closings/po1/adjustments/a1', expect.objectContaining({ preserveScroll: true }));
    });

    it('remover ajuste recusado pelo servidor mostra o erro na página', async () => {
        vi.stubGlobal('confirm', vi.fn(() => true));
        vi.mocked(router.delete).mockImplementation((url, options) => { options.onError?.({ amount: 'The payout total cannot be negative.' }); options.onFinish?.(); });
        const w = mountPage();

        await w.find('[data-test="adjustment-remove"]').trigger('click');

        expect(w.find('[data-test="action-error"]').text()).toBe('The payout total cannot be negative.');
    });

    it('pagamento: hoje por padrão (sem datas futuras), forma de pagamento e dica do caixa', async () => {
        const w = mountPage();

        const date = w.find('[data-test="payment-date"]');
        expect(date.element.value).toBe('2026-09-28');
        expect(date.attributes('max')).toBe('2026-09-28');
        expect(w.findAll('[data-test="payment-method-select"] option').map((o) => o.text())).toEqual(['Bank transfer', 'Cash']);
        expect(w.find('[data-test="payment-hint"]').text()).toBe('Creates a paid expense in the Cash Flow.');

        await w.find('[data-test="payment-method-select"]').setValue('cash');
        await w.find('[data-test="payment-notes"]').setValue('Em espécie');
        await w.find('[data-test="payment-form"]').trigger('submit');

        const form = inertia.forms.find((f) => 'paid_at' in f);
        expect(form.post).toHaveBeenCalledWith('/doctor-payouts/closings/po1/payment', expect.objectContaining({ preserveScroll: true }));
        expect(form.data()).toEqual({ paid_at: '2026-09-28', payment_method: 'cash', payment_notes: 'Em espécie' });
    });

    it('total zerado: dica de que não há lançamento no caixa; erros do servidor no formulário', async () => {
        const w = mountPage({ statement: { ...statement, payout: { ...payoutSummary, total_amount: 0 } } });

        expect(w.find('[data-test="payment-hint"]').text()).toBe('Zero total: no cash entry.');

        const form = inertia.forms.find((f) => 'paid_at' in f);
        form.errors = { paid_at: 'The cash register of 05/09/2026 is closed.', status: 'Not allowed.' };
        await nextTick();

        expect(w.find('[data-test="payment-date"]').attributes('aria-invalid')).toBe('true');
        expect(w.text()).toContain('The cash register of 05/09/2026 is closed.');
        expect(w.find('[data-test="payment-error"]').text()).toBe('Not allowed.');
    });

    it('novo ajuste: descrição, tipo e valor vão para a rota de ajustes', async () => {
        const w = mountPage();

        await w.find('[data-test="adjustment-description"]').setValue('Imposto retido');
        await w.find('[data-test="adjustment-kind"]').setValue('debit');
        const form = inertia.forms.find((f) => 'kind' in f);
        form.amount = 45.9;
        await w.find('[data-test="adjustment-form"]').trigger('submit');

        expect(form.post).toHaveBeenCalledWith('/doctor-payouts/closings/po1/adjustments', expect.objectContaining({ preserveScroll: true }));
        expect(form.data()).toEqual({ description: 'Imposto retido', kind: 'debit', amount: 45.9 });
    });
});
