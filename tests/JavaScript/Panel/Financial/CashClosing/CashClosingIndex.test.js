import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import CashClosingIndex from '@/Pages/Panel/Financial/CashClosing/Index.vue';

/**
 * Fechamento de caixa (Fase 3): PeriodFilter sem datas futuras, prévia só
 * leitura com debounce (pendentes com atalho, totais por forma, sobreposição),
 * confirmação com resumo antes do POST e histórico com "Reabrir" só para
 * admin, com motivo (ConfirmationWithReasonModal).
 */

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
    router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: { props: ['title'], template: '<div><slot name="actions" /></div>' },
}));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({
    default: { props: ['data'], template: '<nav class="pagination-stub" />' },
}));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dropdown-stub" :data-title="title"><ul><slot /></ul></div>' },
}));
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open', 'size'],
        emits: ['close'],
        template: `<div v-if="open" class="modal-stub"><slot name="header" /><slot /><slot name="footer" />
            <button type="button" class="modal-stub-backdrop" @click="$emit('close')"></button></div>`,
    },
}));
vi.mock('@/Components/Panel/ConfirmationWithReasonModal.vue', () => ({
    default: {
        name: 'ReasonModalStub',
        props: ['open', 'title', 'message', 'confirmLabel', 'confirmVariant', 'saving', 'minLength', 'maxLength'],
        emits: ['close', 'confirm'],
        template: `<div v-if="open" class="reason-modal-stub">
            <h5>{{ title }}</h5>
            <p data-test="reason-message">{{ message }}</p>
            <button type="button" data-test="reason-confirm" @click="$emit('confirm', 'Recebimento lançado com valor errado')">{{ confirmLabel }}</button>
            <button type="button" data-test="reason-close" @click="$emit('close')"></button>
        </div>`,
    },
}));

const t = {
    page_title: 'Cash Closing',
    subtitle: 'Lock periods.',
    back_to_cash_flow: 'Cash flow',
    form_title: 'Close a period',
    last_close_hint: 'Last closing through :date.',
    notes: 'Notes',
    close_btn: 'Close period',
    preview: 'Period preview',
    preview_loading: 'Updating preview…',
    preview_hint: 'hint',
    income: 'Income',
    expense: 'Expenses',
    balance: 'Balance',
    entries_count: 'Entries',
    pending_title: 'Pending',
    pending_summary: ':count pending: :income receivable and :expense payable.',
    pending_none: 'No pending entries.',
    pending: 'Receivable (pending)',
    pending_expense: 'Payable (pending)',
    view_pending: 'View pending entries',
    by_payment_method: 'By payment method',
    by_payment_method_empty: 'No entries.',
    col_payment_method: 'Method',
    col_count: 'Qty.',
    payment_method_none: 'Not informed',
    overlap_warning: 'A closing already covers part of this period.',
    overlap_periods: 'Active closings: :periods.',
    confirm_title: 'Confirm cash closing',
    confirm_intro: 'No entry between :from and :to can be changed.',
    confirm_period: 'Period',
    confirm_pending_warning: 'Pending entries (:count): :income receivable and :expense payable.',
    confirm_btn: 'Confirm closing',
    cancel: 'Cancel',
    close_error: 'Could not close the period.',
    closed: 'Period closed successfully.',
    history: 'Closed periods',
    empty: 'No closed periods yet.',
    col_period: 'Period',
    col_income: 'Income',
    col_expense: 'Expenses',
    col_balance: 'Balance',
    col_closed_by: 'Closed by',
    col_closed_at: 'Closed at',
    col_notes: 'Notes',
    col_actions: 'Actions',
    view_entries: 'View entries for this period',
    actions_more: 'More actions',
    reopen: 'Reopen period',
    reopen_admin_only: 'Only administrators can reopen.',
    reopen_title: 'Reopen period?',
    reopen_message: 'Reopening :from to :to allows changes again.',
    reopen_confirm: 'Reopen period',
    reopened: 'Period reopened.',
    reopen_error: 'Could not reopen the period.',
    shared: { period: { after_max: 'Date cannot be after :date.' } },
};

const closes = {
    data: [
        {
            id: 'c1',
            period_start: '2026-08-01',
            period_end: '2026-08-31',
            total_income: 1000,
            total_expense: 400,
            balance: 600,
            closed_at: '2026-09-01T10:30:00-03:00',
            closed_by_name: 'Ana Recepção',
            notes: 'Conferido',
        },
    ],
    total: 1,
};

const basePreview = {
    income: 500,
    expense: 120,
    balance: 380,
    pending: 0,
    pending_income: 0,
    pending_expense: 0,
    pending_count: 0,
    entries_count: 7,
    overlaps: false,
    overlapping_periods: [],
    by_payment_method: [
        { method: 'cash', label: 'À Vista', income: 300, expense: 0, count: 4 },
        { method: null, label: null, income: 200, expense: 120, count: 3 },
    ],
};

const brl = (value, signed = false) =>
    new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        ...(signed ? { signDisplay: 'exceptZero' } : {}),
    }).format(value);

let wrapper;

function mountPage(extra = {}) {
    wrapper = mount(CashClosingIndex, {
        props: {
            closes,
            preview: basePreview,
            filters: { from: '2026-09-01', to: '2026-09-26' },
            last_close_end: '2026-08-31',
            today: '2026-09-26',
            can_reopen: true,
            t,
            ...extra,
        },
        attachTo: document.body,
    });

    return wrapper;
}

const closeBtn = (w) => w.find('[data-test="close-btn"]');

beforeEach(() => {
    vi.mocked(router.get).mockReset();
    vi.mocked(router.post).mockReset();
    vi.mocked(router.delete).mockReset();
    window.showSuccessToast = vi.fn();
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    vi.useRealTimers();
    delete window.showSuccessToast;
});

describe('Financial/CashClosing/Index — fechamento', () => {
    it('"Fechar período" abre a confirmação com período, contagem e totais, sem POST antes de confirmar', async () => {
        const w = mountPage();

        await w.find('form').trigger('submit');

        expect(router.post).not.toHaveBeenCalled();
        expect(w.find('[data-test="confirm-summary"]').text()).toContain(
            'No entry between 01/09/2026 and 26/09/2026 can be changed.',
        );
        expect(w.find('[data-test="confirm-period"]').text()).toBe('01/09/2026 – 26/09/2026');
        expect(w.find('[data-test="confirm-count"]').text()).toBe('7');
        expect(w.find('[data-test="confirm-income"]').text()).toBe(brl(500));
        expect(w.find('[data-test="confirm-expense"]').text()).toBe(brl(120));
        expect(w.find('[data-test="confirm-balance"]').text()).toBe(brl(380, true));
        expect(w.find('[data-test="confirm-pending"]').exists()).toBe(false);
    });

    it('confirmar envia o POST com período e observações; sucesso fecha a confirmação e avisa', async () => {
        vi.mocked(router.post).mockImplementation((url, data, options) => {
            options.onSuccess?.();
            options.onFinish?.();
        });
        const w = mountPage();

        await w.find('textarea').setValue('Conferido com a gaveta');
        await w.find('form').trigger('submit');
        await w.find('[data-test="confirm-close"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/_routes/panel.financial.cash-closing.store',
            { period_start: '2026-09-01', period_end: '2026-09-26', notes: 'Conferido com a gaveta' },
            expect.objectContaining({ preserveScroll: true }),
        );
        await nextTick();
        expect(w.find('[data-test="confirm-summary"]').exists()).toBe(false);
        expect(window.showSuccessToast).toHaveBeenCalledWith('Period closed successfully.');
    });

    it('erro do servidor aparece dentro da confirmação e junto do período', async () => {
        vi.mocked(router.post).mockImplementation((url, data, options) => {
            options.onError?.({ period_start: 'Já existe um fechamento que cobre parte deste período.' });
            options.onFinish?.();
        });
        const w = mountPage();

        await w.find('form').trigger('submit');
        await w.find('[data-test="confirm-close"]').trigger('click');
        await nextTick();

        expect(w.find('[data-test="close-error"]').text()).toContain(
            'Já existe um fechamento que cobre parte deste período.',
        );
        expect(w.find('[data-test="period-server-error"]').text()).toContain('Já existe um fechamento');
        expect(w.find('[data-test="confirm-close"]').attributes('disabled')).toBeUndefined();
    });

    it('pendentes: prévia resume a receber/a pagar com atalho para o Fluxo filtrado; confirmação avisa', async () => {
        const w = mountPage({
            preview: { ...basePreview, pending: 150, pending_income: 150, pending_expense: 80, pending_count: 3 },
        });

        expect(w.find('[data-test="preview-pending-summary"]').text()).toBe(
            `3 pending: ${brl(150)} receivable and ${brl(80)} payable.`,
        );
        expect(w.find('[data-test="view-pending"]').attributes('href')).toBe(
            '/_routes/panel.financial.cash-flow.index?from=2026-09-01&to=2026-09-26&status=pending',
        );

        await w.find('form').trigger('submit');
        expect(w.find('[data-test="confirm-pending"]').text()).toContain(
            `Pending entries (3): ${brl(150)} receivable and ${brl(80)} payable.`,
        );
    });

    it('prévia mostra totais por forma de pagamento ("não informada" traduzida) e a contagem', () => {
        const w = mountPage();

        expect(w.find('[data-test="preview-count"]').text()).toBe('7');
        expect(w.find('[data-test="method-cash"]').text()).toContain('À Vista');
        expect(w.find('[data-test="method-cash"]').text()).toContain(brl(300));
        expect(w.find('[data-test="method-none"]').text()).toContain('Not informed');
        expect(w.find('[data-test="method-none"]').text()).toContain(brl(120));
        expect(w.find('[data-test="preview-pending"]').text()).toContain('No pending entries.');
        expect(w.find('[data-test="preview"]').attributes('aria-live')).toBe('polite');
    });

    it('trocar o período recarrega só a prévia, com debounce, e bloqueia o fechamento até atualizar', async () => {
        vi.useFakeTimers();
        const w = mountPage();

        await w.find('[data-test="period-preset"]').setValue('last7');
        await nextTick();

        expect(closeBtn(w).attributes('disabled')).toBeDefined();
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);

        expect(router.get).toHaveBeenCalledTimes(1);
        expect(router.get).toHaveBeenCalledWith(
            '/_routes/panel.financial.cash-closing.index',
            { from: '2026-09-20', to: '2026-09-26' },
            expect.objectContaining({ only: ['preview', 'filters'], preserveState: true, replace: true }),
        );

        // Servidor devolve a prévia do novo período: o botão volta a ficar disponível.
        await w.setProps({ filters: { from: '2026-09-20', to: '2026-09-26' } });
        expect(closeBtn(w).attributes('disabled')).toBeUndefined();
    });

    it('enquanto a prévia carrega: aria-busy, aviso visível e fechamento bloqueado', async () => {
        vi.useFakeTimers();
        vi.mocked(router.get).mockImplementation((url, data, options) => {
            options.onStart?.();
        });
        const w = mountPage();

        await w.find('[data-test="period-preset"]').setValue('today');
        vi.advanceTimersByTime(400);
        await nextTick();

        expect(w.find('[data-test="preview"]').attributes('aria-busy')).toBe('true');
        expect(w.find('[data-test="preview-loading"]').text()).toContain('Updating preview…');
        expect(closeBtn(w).attributes('disabled')).toBeDefined();
    });

    it('data futura digitada: erro do PeriodFilter, nenhuma requisição e botão desabilitado', async () => {
        vi.useFakeTimers();
        const w = mountPage();

        await w.find('[data-test="period-to"]').setValue('2026-09-30');
        await flushPromises();
        vi.advanceTimersByTime(1000);

        expect(w.find('[data-test="period-error"]').attributes('role')).toBe('alert');
        expect(w.find('[data-test="period-error"]').text()).toContain('Date cannot be after 26/09/2026.');
        expect(w.find('[data-test="period-to"]').attributes('max')).toBe('2026-09-26');
        expect(router.get).not.toHaveBeenCalled();
        expect(closeBtn(w).attributes('disabled')).toBeDefined();

        await w.find('form').trigger('submit');
        expect(w.find('[data-test="confirm-summary"]').exists()).toBe(false);

        // Corrigiu a data: volta a valer.
        await w.find('[data-test="period-to"]').setValue('2026-09-26');
        await flushPromises();
        expect(closeBtn(w).attributes('disabled')).toBeUndefined();
    });

    it('sobreposição com fechamento existente: aviso com os períodos e botão desabilitado', () => {
        const w = mountPage({
            preview: {
                ...basePreview,
                overlaps: true,
                overlapping_periods: [{ period_start: '2026-09-01', period_end: '2026-09-15' }],
            },
        });

        expect(w.find('[data-test="overlap-warning"]').text()).toContain(
            'A closing already covers part of this period.',
        );
        expect(w.find('[data-test="overlap-periods"]').text()).toBe('Active closings: 01/09/2026 – 15/09/2026.');
        expect(closeBtn(w).attributes('disabled')).toBeDefined();
    });

    it('mostra até onde foi o último fechamento', () => {
        expect(mountPage().find('[data-test="last-close-hint"]').text()).toContain('Last closing through 31/08/2026.');
        wrapper.unmount();
        expect(mountPage({ last_close_end: null }).find('[data-test="last-close-hint"]').exists()).toBe(false);
    });
});

describe('Financial/CashClosing/Index — histórico e reabertura', () => {
    it('histórico: período, receitas, despesas, saldo com sinal, quem fechou, data/hora local, observação e "Ver lançamentos"', () => {
        const w = mountPage();
        const row = w.find('[data-test="close-c1"]');

        expect(row.text()).toContain('01/08/2026 – 31/08/2026');
        expect(row.text()).toContain(brl(1000));
        expect(row.text()).toContain(brl(400));
        expect(row.text()).toContain(brl(600, true));
        expect(row.text()).toContain('Ana Recepção');
        expect(row.text()).toContain(
            new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(
                new Date('2026-09-01T10:30:00-03:00'),
            ),
        );
        expect(row.find('[data-test="close-notes"]').text()).toContain('Conferido');
        expect(row.find('[data-test="view-entries"]').attributes('href')).toBe(
            '/_routes/panel.financial.cash-flow.index?from=2026-08-01&to=2026-08-31',
        );
        expect(row.find('[data-test="view-entries"]').attributes('aria-label')).toBe(
            'View entries for this period: 01/08/2026 – 31/08/2026',
        );
    });

    it('não-admin não vê "Reabrir" e é avisado de que só admin reabre', () => {
        const w = mountPage({ can_reopen: false });

        expect(w.find('[data-test="reopen"]').exists()).toBe(false);
        expect(w.find('[data-test="reopen-admin-only"]').text()).toContain('Only administrators can reopen.');
    });

    it('admin: "Reabrir" (ação perigosa no menu) pede motivo; confirmar envia o DELETE com o motivo', async () => {
        vi.mocked(router.delete).mockImplementation((url, options) => {
            options.onSuccess?.({ props: { flash: {} } });
            options.onFinish?.();
        });
        const w = mountPage();

        expect(w.find('.dropdown-stub').attributes('data-title')).toBe('More actions: 01/08/2026 – 31/08/2026');
        expect(w.find('[data-test="reopen"]').classes()).toContain('text-danger');

        await w.find('[data-test="reopen"]').trigger('click');
        expect(router.delete).not.toHaveBeenCalled();

        const modal = w.findComponent({ name: 'ReasonModalStub' });
        expect(modal.props('title')).toBe('Reopen period?');
        expect(modal.props('minLength')).toBe(10);
        expect(modal.props('maxLength')).toBe(1000);
        expect(w.find('[data-test="reason-message"]').text()).toBe(
            'Reopening 01/08/2026 to 31/08/2026 allows changes again.',
        );

        await w.find('[data-test="reason-confirm"]').trigger('click');

        expect(router.delete).toHaveBeenCalledWith(
            '/_routes/panel.financial.cash-closing.destroy/c1',
            expect.objectContaining({ data: { reason: 'Recebimento lançado com valor errado' }, preserveScroll: true }),
        );
        await nextTick();
        expect(w.find('.reason-modal-stub').exists()).toBe(false);
        expect(window.showSuccessToast).toHaveBeenCalledWith('Period reopened.');
    });

    it('erro de validação/permissão na reabertura aparece no histórico (role=alert)', async () => {
        vi.mocked(router.delete).mockImplementation((url, options) => {
            options.onError?.({ reason: 'O motivo precisa ter pelo menos 10 caracteres.' });
            options.onFinish?.();
        });
        const w = mountPage();

        await w.find('[data-test="reopen"]').trigger('click');
        await w.find('[data-test="reason-confirm"]').trigger('click');
        await nextTick();

        expect(w.find('[data-test="reopen-error"]').attributes('role')).toBe('alert');
        expect(w.find('[data-test="reopen-error"]').text()).toContain('O motivo precisa ter pelo menos 10 caracteres.');
        expect(window.showSuccessToast).not.toHaveBeenCalled();
    });

    it('recusa do middleware (redirect com flash de erro) não vira "sucesso"', async () => {
        vi.mocked(router.delete).mockImplementation((url, options) => {
            options.onSuccess?.({ props: { flash: { error: 'Esta ação não é permitida.' } } });
            options.onFinish?.();
        });
        const w = mountPage();

        await w.find('[data-test="reopen"]').trigger('click');
        await w.find('[data-test="reason-confirm"]').trigger('click');
        await nextTick();

        expect(w.find('[data-test="reopen-error"]').text()).toContain('Esta ação não é permitida.');
        expect(window.showSuccessToast).not.toHaveBeenCalled();
    });

    it('Esc cancela a reabertura sem enviar nada', async () => {
        const w = mountPage();

        await w.find('[data-test="reopen"]').trigger('click');
        expect(w.find('.reason-modal-stub').exists()).toBe(true);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();

        expect(w.find('.reason-modal-stub').exists()).toBe(false);
        expect(router.delete).not.toHaveBeenCalled();
    });
});
