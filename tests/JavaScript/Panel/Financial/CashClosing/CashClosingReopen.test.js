import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import CashClosingIndex from '@/Pages/Panel/Financial/CashClosing/Index.vue';

/**
 * Reabertura com o ConfirmationWithReasonModal REAL: o motivo precisa de 10+
 * caracteres (mesmo mínimo do ReopenCashCloseRequest), os textos do modal vêm
 * de `t_hardening` (sobrescritos pela página de fechamento) e o motivo vai
 * aparado no DELETE.
 */

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            locale: 'pt_BR',
            t_hardening: {
                modal_reason_label: 'Motivo da reabertura',
                modal_reason_hint: 'Mínimo de 10 caracteres.',
                modal_counter: ':current / :min mínimo',
                modal_cancel: 'Cancelar',
            },
        },
    }),
    router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({ default: { template: '<div><slot name="actions" /></div>' } }));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({ default: { template: '<nav />' } }));
vi.mock('@/Components/Panel/PeriodFilter.vue', () => ({ default: { template: '<div class="period-stub" />' } }));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({ default: { template: '<div class="dropdown-stub"><ul><slot /></ul></div>' } }));
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({ default: { props: ['open'], template: '<div v-if="open"><slot /><slot name="footer" /></div>' } }));

const t = {
    reopen: 'Reopen period', reopen_title: 'Reopen period?', reopen_confirm: 'Reopen period',
    reopen_message: 'Reopening :from to :to allows changes again.', reopened: 'Period reopened.', reopen_error: 'Could not reopen.',
};

const closes = {
    data: [{
        id: 'c1', period_start: '2026-08-01', period_end: '2026-08-31', total_income: 1000, total_expense: 400, balance: 600,
        closed_at: '2026-09-01T10:30:00-03:00', closed_by_name: 'Ana', notes: null,
    }],
    total: 1,
};

let wrapper;

function mountPage() {
    wrapper = mount(CashClosingIndex, {
        props: {
            closes,
            preview: { income: 0, expense: 0, balance: 0, pending: 0, entries_count: 0, overlaps: false, by_payment_method: [] },
            filters: { from: '2026-09-01', to: '2026-09-26' },
            today: '2026-09-26',
            can_reopen: true,
            t,
        },
        attachTo: document.body,
    });

    return wrapper;
}

const reasonField   = (w) => w.find('.modal textarea');
const confirmButton = (w) => w.findAll('.modal .modal-footer button').find((b) => b.text().includes('Reopen period'));

beforeEach(() => {
    vi.mocked(router.delete).mockReset();
    window.showSuccessToast = vi.fn();
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    delete window.showSuccessToast;
});

describe('Financial/CashClosing — reabertura com motivo (modal real)', () => {
    it('confirmar só libera com 10+ caracteres e envia o motivo aparado', async () => {
        vi.mocked(router.delete).mockImplementation((url, options) => { options.onSuccess?.({ props: { flash: {} } }); options.onFinish?.(); });
        const w = mountPage();

        await w.find('[data-test="reopen"]').trigger('click');

        expect(w.text()).toContain('Reopening 01/08/2026 to 31/08/2026 allows changes again.');
        expect(w.text()).toContain('Motivo da reabertura');
        expect(w.text()).toContain('0 / 10 mínimo');

        const textarea = reasonField(w);
        await textarea.setValue('  curto  ');
        expect(confirmButton(w).attributes('disabled')).toBeDefined();

        await textarea.setValue('  Valor lançado errado em 28/08  ');
        expect(confirmButton(w).attributes('disabled')).toBeUndefined();

        await confirmButton(w).trigger('click');
        await flushPromises();

        expect(router.delete).toHaveBeenCalledWith(
            '/_routes/panel.financial.cash-closing.destroy/c1',
            expect.objectContaining({ data: { reason: 'Valor lançado errado em 28/08' } }),
        );
        expect(window.showSuccessToast).toHaveBeenCalledWith('Period reopened.');
        expect(reasonField(w).exists()).toBe(false);
    });

    it('Esc fecha o modal de motivo (o componente compartilhado não trata Esc sozinho)', async () => {
        const w = mountPage();

        await w.find('[data-test="reopen"]').trigger('click');
        expect(reasonField(w).exists()).toBe(true);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();

        expect(reasonField(w).exists()).toBe(false);
        expect(router.delete).not.toHaveBeenCalled();
    });
});
