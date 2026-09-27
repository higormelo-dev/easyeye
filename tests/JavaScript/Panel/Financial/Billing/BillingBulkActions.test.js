import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import BillingIndex from '@/Pages/Panel/Financial/Billing/Index.vue';
import ClaimsTable from '@/Pages/Panel/Financial/Billing/ClaimsTable.vue';
import BatchesTable from '@/Pages/Panel/Financial/Billing/BatchesTable.vue';
import ClaimsSelectionBar from '@/Pages/Panel/Financial/Billing/ClaimsSelectionBar.vue';
import BulkReceiptModal from '@/Pages/Panel/Financial/Billing/BulkReceiptModal.vue';
import BatchReceiptModal from '@/Pages/Panel/Financial/Billing/BatchReceiptModal.vue';
import AddClaimsModal from '@/Pages/Panel/Financial/Billing/AddClaimsModal.vue';
import AttachToBatchModal from '@/Pages/Panel/Financial/Billing/AttachToBatchModal.vue';
import { router, resetInertiaMock } from './support/inertiaMock.js';
import { t, claim, batch, schedule, kpis, lists, paginate, brl, norm } from './support/fixtures.js';

vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({ default: { props: ['title'], template: '<div><slot name="actions" /></div>' } }));
vi.mock('@/Components/Panel/SearchSelect.vue', () => ({ default: { props: ['modelValue'], template: '<div />' } }));
vi.mock('@/Components/Panel/Cid10Picker.vue', () => ({ default: { props: ['modelValue'], template: '<div />' } }));

const paymentMethods = [
    { value: 'transfer', label: 'Transferência bancária' },
    { value: 'cash', label: 'À vista (dinheiro)' },
];

const modalMount = { global: { stubs: { teleport: true } }, attachTo: document.body };

let wrapper;

function track(w) {
    wrapper = w;

    return w;
}

function axios422(errors) {
    return Object.assign(new Error('422'), { response: { status: 422, data: { message: 'invalid', errors } } });
}

beforeEach(() => resetInertiaMock());
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    delete window.axios;
});

/* ───────────────────────── Index: seleção entre páginas + barra ───────────────────────── */
describe('Billing/Index — seleção da aba Guias para o recebimento em lote', () => {
    const p1 = claim({ id: 'p1', code: 'GUI-P1', patient_name: 'Ana', allowed_actions: ['pay', 'deny'], receivable_amount: 200 });
    const p2 = claim({ id: 'p2', code: 'GUI-P2', patient_name: 'Bia', allowed_actions: ['pay'], receivable_amount: 150.5 });
    const x  = claim({ id: 'x', code: 'GUI-X', allowed_actions: [], status: 'paid' });
    const p3 = claim({ id: 'p3', code: 'GUI-P3', patient_name: 'Caio', allowed_actions: ['pay'], receivable_amount: 99.5 });

    const baseProps = {
        breadcrumbs: [],
        eligibleSchedules: paginate([schedule()], {}, 'eligible_page'),
        claims: paginate([p1, p2, x], { last_page: 2, total: 4 }, 'claims_page'),
        batches: paginate([batch()], {}, 'batches_page'),
        kpis,
        totals: { eligible: 1, claims: 4, batches: 1 },
        lists: lists(),
        covenants: [],
        filters: { from: '2026-09-01', to: '2026-09-26', covenant_id: null, claim_status: null, batch_id: null, batch_code: null, tab: 'claims' },
        claimStatuses: [],
        paymentMethods,
        today: '2026-09-26',
        bulkReceiptUrl: '/billing/claims/bulk-receipt',
        bulkMaxClaims: 200,
        storeIndividualUrl: '/billing/individual',
        storeBatchUrl: '/billing/batch',
        importReturnUrl: '/billing/import-return',
        t,
    };

    function mountPage(overrides = {}) {
        return track(mount(BillingIndex, {
            props: { ...baseProps, ...overrides },
            global: { stubs: { teleport: true, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } } },
            attachTo: document.body,
        }));
    }

    function bar(w) {
        return w.find('[data-test="claims-selection-bar"]');
    }

    it('checkbox só nas guias que podem receber; a seleção atravessa as páginas e a barra mostra quantidade e total', async () => {
        const w = mountPage();

        const rows = w.findAll('[data-test="claim-row"]');
        expect(rows[2].find('[data-test="claim-select"]').exists()).toBe(false);
        expect(rows[0].find('[data-test="claim-select"]').attributes('aria-label')).toBe('Selecionar a guia GUI-P1 — Ana');
        expect(bar(w).exists()).toBe(false);

        await rows[0].find('[data-test="claim-select"]').trigger('change');
        expect(norm(bar(w).find('[data-test="selection-summary"]').text())).toBe(norm(`1 selecionada(s) · ${brl(200)}`));
        expect(norm(w.find('[data-test="selection-live"]').text())).toBe(norm(`1 selecionada(s) · ${brl(200)}`));
        expect(bar(w).attributes('aria-label')).toBe(t.selection_bar_label);

        // "Selecionar a página": marca as pagáveis desta página.
        await w.find('[data-test="claims-select-page"]').trigger('change');
        expect(norm(bar(w).find('[data-test="selection-summary"]').text())).toBe(norm(`2 selecionada(s) · ${brl(350.5)}`));

        // Página 2 (mesmos filtros): as da página 1 continuam marcadas.
        await w.setProps({ claims: paginate([p3], { current_page: 2, last_page: 2, total: 4 }, 'claims_page') });
        expect(norm(bar(w).find('[data-test="selection-summary"]').text())).toBe(norm(`2 selecionada(s) · ${brl(350.5)}`));
        expect(bar(w).find('[data-test="selection-other-pages"]').text()).toBe(t.selection_other_pages);
        expect(w.find('[data-test="claims-select-page"]').element.checked).toBe(false);

        await w.find('[data-test="claim-select"]').trigger('change');
        expect(norm(bar(w).find('[data-test="selection-summary"]').text())).toBe(norm(`3 selecionada(s) · ${brl(450)}`));
    });

    it('guia que deixou de ser pagável sai da seleção; filtro novo descarta o que saiu da lista; limpar zera', async () => {
        const w = mountPage();

        await w.find('[data-test="claims-select-page"]').trigger('change');
        expect(bar(w).text()).toContain('2 selecionada(s)');

        // A página volta do servidor com p2 já paga por outro usuário.
        await w.setProps({ claims: paginate([p1, { ...p2, allowed_actions: [], status: 'paid' }, x], { last_page: 2, total: 4 }, 'claims_page') });
        expect(bar(w).text()).toContain('1 selecionada(s)');

        await w.find('[data-test="claims-select-page"]').trigger('change');
        // Convênio novo: só fica a marcada que ainda aparece.
        await w.setProps({
            filters: { ...baseProps.filters, covenant_id: 'cov-9' },
            claims: paginate([p3], {}, 'claims_page'),
        });
        expect(bar(w).exists()).toBe(false);

        await w.find('[data-test="claim-select"]').trigger('change');
        await bar(w).find('[data-test="selection-clear"]').trigger('click');
        expect(bar(w).exists()).toBe(false);
    });

    it('"Registrar recebimento" abre o modal com as marcadas de todas as páginas; gravou → limpa a seleção e recarrega a lista', async () => {
        window.axios = {
            post: vi.fn(() => Promise.resolve({ data: { message: 'Recebimento registrado em 3 guia(s).', paid: [], skipped: [], total_paid: 450 } })),
        };
        const w = mountPage();

        await w.find('[data-test="claims-select-page"]').trigger('change');
        await w.setProps({ claims: paginate([p3], { current_page: 2, last_page: 2, total: 4 }, 'claims_page') });
        await w.find('[data-test="claim-select"]').trigger('change');

        await bar(w).find('[data-test="selection-receive"]').trigger('click');

        const modal = w.find('[data-test="bulk-receipt-form"]');
        expect(modal.exists()).toBe(true);
        expect(modal.findAll('[data-test="bulk-row"]').map((row) => row.text())).toEqual([
            expect.stringContaining('GUI-P1'), expect.stringContaining('GUI-P2'), expect.stringContaining('GUI-P3'),
        ]);

        await w.find('[data-test="bulk-confirm"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/billing/claims/bulk-receipt', expect.objectContaining({
            items: [
                { claim_id: 'p1', paid_amount: 200 },
                { claim_id: 'p2', paid_amount: 150.5 },
                { claim_id: 'p3', paid_amount: 99.5 },
            ],
        }));
        expect(bar(w).exists()).toBe(false);
        expect(router.reload).toHaveBeenCalledWith({ only: ['claims', 'batches', 'kpis', 'totals'], preserveScroll: true });
        // O modal segue aberto com o resultado.
        expect(w.find('[data-test="bulk-result"]').text()).toContain('Recebimento registrado em 3 guia(s).');
    });

    it('"Incluir em lote" (guia) e as ações do lote abrem os modais certos', async () => {
        window.axios = { get: vi.fn(() => new Promise(() => {})) };
        const attachable = claim({ id: 'c7', code: 'GUI-7', allowed_actions: ['attach'], attach_targets_url: '/claims/c7/attach-targets' });
        const w = mountPage({ claims: paginate([attachable], {}, 'claims_page') });

        await w.find('[data-test="claim-menu"] button').trigger('click');
        await w.find('[data-test="attach-claim"]').trigger('click');
        expect(w.find('[data-test="attach-modal"]').exists()).toBe(true);
        expect(window.axios.get).toHaveBeenCalledWith('/claims/c7/attach-targets');
    });
});

/* ───────────────────────── Tabelas ───────────────────────── */
describe('ClaimsTable / BatchesTable — ações em lote vindas de allowed_actions', () => {
    it('cabeçalho "selecionar a página" fica parcial com seleção parcial e desabilita sem guia pagável; "Incluir em lote" emite attach', async () => {
        const a = claim({ id: 'a', allowed_actions: ['pay'] });
        const b = claim({ id: 'b', allowed_actions: ['pay'] });
        const c = claim({ id: 'c', code: 'GUI-C', allowed_actions: ['attach', 'cancel'] });
        const w = track(mount(ClaimsTable, { props: { t, claims: paginate([a, b, c]), selectedIds: ['a'] }, ...modalMount }));
        await nextTick();

        const header = w.find('[data-test="claims-select-page"]');
        expect(header.element.indeterminate).toBe(true);
        expect(header.attributes('aria-label')).toBe(t.claims_select_page);
        expect(w.findAll('[data-test="claim-row"]')[0].classes()).toContain('table-active');

        await header.trigger('change');
        expect(w.emitted('toggle-select-page')).toHaveLength(1);

        await w.findAll('[data-test="claim-select"]')[1].trigger('change');
        expect(w.emitted('toggle-select')[0][0].id).toBe('b');

        await w.find('[data-test="claim-menu"] button').trigger('click');
        await w.find('[data-test="attach-claim"]').trigger('click');
        expect(w.emitted('attach')[0][0].id).toBe('c');

        const none = mount(ClaimsTable, { props: { t, claims: paginate([c]) } });
        expect(none.find('[data-test="claims-select-page"]').attributes('disabled')).toBeDefined();
        none.unmount();
    });

    it('lote enviado com guia a receber: "Registrar recebimento" rotulado; rascunho: "Adicionar guias"/"Reprocessar pendentes" no menu', async () => {
        const sent  = batch({ id: 's', code: 'LOT-S', status: 'submitted', allowed_actions: ['download_xml', 'receive'] });
        const draft = batch({ id: 'd', code: 'LOT-D', allowed_actions: ['submit', 'cancel', 'add_claims', 'reprocess_pending'] });
        const w = track(mount(BatchesTable, { props: { t, batches: paginate([sent, draft]) }, ...modalMount }));

        const [sentRow, draftRow] = w.findAll('[data-test="batch-row"]');
        const receive = sentRow.find('[data-test="receive-batch"]');
        expect(receive.attributes('aria-label')).toBe('Registrar recebimento do lote LOT-S');
        expect(draftRow.find('[data-test="receive-batch"]').exists()).toBe(false);
        expect(sentRow.find('[data-test="batch-menu"]').exists()).toBe(false);

        await receive.trigger('click');
        expect(w.emitted('receive')[0][0].id).toBe('s');

        await draftRow.find('[data-test="batch-menu"] button').trigger('click');
        await draftRow.find('[data-test="add-claims"]').trigger('click');
        await draftRow.find('[data-test="batch-menu"] button').trigger('click');
        await draftRow.find('[data-test="reprocess-pending"]').trigger('click');

        expect(w.emitted('add-claims')[0][0].id).toBe('d');
        expect(w.emitted('reprocess')[0][0].id).toBe('d');

        // O menu fecha a cada escolha: reabre para conferir a ação perigosa (separada).
        await draftRow.find('[data-test="batch-menu"] button').trigger('click');
        expect(draftRow.find('[data-test="cancel-batch"]').classes()).toContain('text-danger');
        expect(draftRow.find('.dropdown-divider').exists()).toBe(true);
    });

    it('barra da seleção: some sem seleção; resumo formatado pelo idioma e aviso de outras páginas', async () => {
        const w = track(mount(ClaimsSelectionBar, { props: { t, count: 0, total: 0 } }));
        expect(w.find('[data-test="claims-selection-bar"]').exists()).toBe(false);
        expect(w.find('[data-test="selection-live"]').attributes('role')).toBe('status');

        await w.setProps({ count: 2, total: 1234.5, otherPages: true });
        expect(norm(w.find('[data-test="selection-summary"]').text())).toBe(norm(`2 selecionada(s) · ${brl(1234.5)}`));
        expect(w.find('[data-test="selection-other-pages"]').exists()).toBe(true);

        await w.find('[data-test="selection-receive"]').trigger('click');
        await w.find('[data-test="selection-clear"]').trigger('click');
        expect(w.emitted('receive')).toHaveLength(1);
        expect(w.emitted('clear')).toHaveLength(1);
    });
});

/* ───────────────────────── Recebimento das guias selecionadas ───────────────────────── */
describe('BulkReceiptModal — valor por guia', () => {
    const rows = [
        claim({ id: 'c1', code: 'GUI-1', patient_name: 'Ana', amount: 200, receivable_amount: 200 }),
        claim({ id: 'c2', code: 'GUI-2', patient_name: 'Bia', amount: 300, glosa_amount: 100, receivable_amount: 200 }),
    ];

    function mountModal(props = {}) {
        return track(mount(BulkReceiptModal, {
            props: { open: true, claims: rows, paymentMethods, today: '2026-09-26', url: '/bulk', max: 200, t, ...props },
            ...modalMount,
        }));
    }

    function amountInput(w, id) {
        return w.find(`#billing-bulk-amount-${id}`);
    }

    it('abre com a receber de cada guia (MoneyInput rotulado), data de hoje e o total; editar recalcula', async () => {
        const w = mountModal();

        expect(w.text()).toContain('Registrar recebimento de 2 guia(s)');
        expect(amountInput(w, 'c1').element.value).toBe('200,00');
        expect(amountInput(w, 'c2').element.value).toBe('200,00');
        expect(w.find('label[for="billing-bulk-amount-c2"]').text()).toBe('Valor recebido da guia GUI-2');
        expect(w.find('[data-test="receipt-date"]').element.value).toBe('2026-09-26');
        expect(w.find('[data-test="receipt-date"]').attributes('max')).toBe('2026-09-26');
        expect(norm(w.find('[data-test="bulk-total"]').text())).toBe(norm(brl(400)));

        await amountInput(w, 'c2').setValue('250,00');
        expect(norm(w.find('[data-test="bulk-total"]').text())).toBe(norm(brl(450)));
        expect(w.find('[data-test="bulk-confirm"]').text()).toBe('Registrar 2 recebimento(s)');
    });

    it('valor acima do valor da guia: erro na linha (ligado ao campo), foco nela e nada enviado', async () => {
        window.axios = { post: vi.fn() };
        const w = mountModal();

        await amountInput(w, 'c1').setValue('250,00');
        await w.find('[data-test="bulk-confirm"]').trigger('click');
        await nextTick();

        const input = amountInput(w, 'c1');
        expect(window.axios.post).not.toHaveBeenCalled();
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toBe('billing-bulk-amount-c1-error');
        expect(norm(w.find('#billing-bulk-amount-c1-error').text())).toBe(norm(`Informe um valor maior que zero e até ${brl(200)}.`));
        expect(document.activeElement).toBe(input.element);
    });

    it('envia data, forma, observação e o valor de cada guia; resultado ao vivo com as ignoradas e foco nele', async () => {
        const data = {
            message: 'Recebimento registrado em 1 guia(s); 1 já estava(m) paga(s) e foi(ram) ignorada(s).',
            paid: [{ claim_id: 'c1', code: 'GUI-1', paid_amount: 180 }],
            skipped: [{ claim_id: 'c2', code: 'GUI-2', message: 'A guia GUI-2 já estava paga — ignorada.' }],
            total_paid: 180,
        };
        window.axios = { post: vi.fn(() => Promise.resolve({ data })) };
        const w = mountModal();

        await amountInput(w, 'c1').setValue('180,00');
        await w.find('[data-test="receipt-method"]').setValue('cash');
        await w.find('[data-test="receipt-notes"]').setValue('Depósito 123');
        await w.find('[data-test="bulk-confirm"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/bulk', {
            paid_at: '2026-09-26',
            payment_method: 'cash',
            notes: 'Depósito 123',
            items: [{ claim_id: 'c1', paid_amount: 180 }, { claim_id: 'c2', paid_amount: 200 }],
        });

        const result = w.find('[role="status"] [data-test="bulk-result"]');
        expect(result.find('[data-test="bulk-message"]').text()).toBe(data.message);
        expect(norm(result.find('[data-test="bulk-paid-total"]').text())).toBe(norm(`Total registrado: ${brl(180)}`));
        expect(result.find('[data-test="bulk-skipped"]').text()).toContain('A guia GUI-2 já estava paga — ignorada.');
        expect(document.activeElement).toBe(result.element);
        expect(w.emitted('saved')[0][0]).toEqual(data);
        expect(w.find('[data-test="bulk-confirm"]').exists()).toBe(false);
        expect(amountInput(w, 'c1').attributes('disabled')).toBeDefined();
    });

    it('recusa do servidor (tudo ou nada): motivo em cada guia, no campo da data e na lista geral; nada marcado como gravado', async () => {
        window.axios = {
            post: vi.fn(() => Promise.reject(axios422({
                'claims.c2': ['A guia GUI-2 está cancelada.'],
                'items.0.paid_amount': ['O valor recebido da guia GUI-1 não pode ser maior que o valor da guia.'],
                paid_at: ['O caixa do dia 25/09/2026 está fechado.'],
            }))),
        };
        const w = mountModal();

        await w.find('[data-test="bulk-confirm"]').trigger('click');
        await flushPromises();

        const rowErrors = w.findAll('[data-test="bulk-row-error"]').map((el) => el.text());
        expect(rowErrors).toEqual([
            'O valor recebido da guia GUI-1 não pode ser maior que o valor da guia.',
            'A guia GUI-2 está cancelada.',
        ]);
        expect(w.find('#billing-bulk-date-error').text()).toBe('O caixa do dia 25/09/2026 está fechado.');
        expect(w.find('[data-test="receipt-date"]').attributes('aria-invalid')).toBe('true');

        const general = w.find('[data-test="bulk-errors"]');
        expect(general.attributes('role')).toBe('alert');
        expect(general.text()).toContain(t.bulk_receipt_errors_title);
        expect(general.text()).toContain('A guia GUI-2 está cancelada.');
        expect(w.find('[data-test="bulk-result"]').exists()).toBe(false);
        expect(w.emitted('saved')).toBeUndefined();
    });

    it('acima do teto: aviso e confirmar desabilitado; Esc fecha (não durante o envio)', async () => {
        const w = mountModal({ max: 1 });

        expect(w.find('[data-test="bulk-over-limit"]').text()).toBe('Selecione no máximo 1 guias por vez.');
        expect(w.find('[data-test="bulk-confirm"]').attributes('disabled')).toBeDefined();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toHaveLength(1);
    });
});

/* ───────────────────────── Recebimento do lote ───────────────────────── */
describe('BatchReceiptModal — prévia antes de confirmar', () => {
    const sent = batch({ id: 'b9', code: 'LOT-9', status: 'submitted', receipt_preview_url: '/batches/b9/receipt-preview', receipt_url: '/batches/b9/receipt' });

    function mountModal() {
        return track(mount(BatchReceiptModal, { props: { open: true, batch: sent, paymentMethods, today: '2026-09-26', t }, ...modalMount }));
    }

    it('mostra quantidade e total da prévia e posta data/forma/observação na rota do lote', async () => {
        window.axios = {
            get: vi.fn(() => Promise.resolve({ data: { count: 2, total: 400, blocked: 1, max: 200, over_limit: false } })),
            post: vi.fn(() => Promise.resolve({ data: { message: 'Recebimento registrado em 2 guia(s).', paid: [], skipped: [], total_paid: 400 } })),
        };
        const w = mountModal();
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledWith('/batches/b9/receipt-preview');
        expect(norm(w.find('[data-test="batch-receipt-summary"]').text())).toBe(norm(`2 guia(s) a receber · ${brl(400)}`));
        expect(w.find('[data-test="batch-receipt-blocked"]').text()).toContain('1 guia(s)');

        await w.find('[data-test="batch-receipt-confirm"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/batches/b9/receipt', { paid_at: '2026-09-26', payment_method: 'transfer', notes: '' });
        expect(w.find('[data-test="batch-receipt-message"]').text()).toBe('Recebimento registrado em 2 guia(s).');
        expect(w.emitted('saved')).toHaveLength(1);
    });

    it('acima do teto ou sem guia a receber: confirmar desabilitado; erro da prévia aparece traduzido', async () => {
        window.axios = { get: vi.fn(() => Promise.resolve({ data: { count: 250, total: 50000, blocked: 0, max: 200, over_limit: true } })) };
        const over = mountModal();
        await flushPromises();

        expect(over.find('[data-test="batch-receipt-over-limit"]').text()).toContain('250');
        expect(over.find('[data-test="batch-receipt-confirm"]').attributes('disabled')).toBeDefined();
        over.unmount();

        window.axios = { get: vi.fn(() => Promise.reject(axios422({ batch: ['O lote LOT-9 não tem guia a receber.'] }))) };
        const empty = mountModal();
        await flushPromises();

        expect(empty.find('[data-test="batch-receipt-error"]').text()).toBe('O lote LOT-9 não tem guia a receber.');
        expect(empty.find('[data-test="batch-receipt-confirm"]').attributes('disabled')).toBeDefined();
    });
});

/* ───────────────────────── Adicionar guias / reprocessar / incluir em lote ───────────────────────── */
describe('AddClaimsModal / AttachToBatchModal — resultado por guia', () => {
    const draft = batch({
        id: 'b1', code: 'LOT-1', pending_count: 2,
        attachable_claims_url: '/batches/b1/attachable-claims', attach_claims_url: '/batches/b1/attach-claims', reprocess_url: '/batches/b1/reprocess-pending',
    });

    const result = {
        message: '1 guia(s) incluída(s) no lote LOT-1; 1 com pendência ficaram de fora (veja os erros de cada uma).',
        attached_count: 1,
        pending_count: 1,
        remaining: 0,
        results: [
            { claim_id: 'a', code: 'GUI-A', patient_name: 'Ana', attached: true, validation: { passes: true, errors: [], warnings: [], summary: 'ok' } },
            { claim_id: 'b', code: 'GUI-B', patient_name: 'Bia', attached: false, validation: { passes: false, errors: [{ message: 'CID não informado.' }], warnings: [], summary: 'Guia possui pendências.' } },
        ],
    };

    it('"Adicionar guias": lista as elegíveis (origem, pendência, rótulo por linha), seleção múltipla e resultado por guia', async () => {
        window.axios = {
            get: vi.fn(() => Promise.resolve({
                data: {
                    data: [
                        { id: 'a', code: 'GUI-A', patient_name: 'Ana', attendance_date: '2026-09-20', amount: 150, origin: 'individual', origin_batch_code: null, has_errors: false },
                        { id: 'b', code: 'GUI-B', patient_name: 'Bia', attendance_date: '2026-09-21', amount: 150, origin: 'other', origin_batch_code: 'LOT-9', has_errors: true },
                    ],
                    total: 3,
                    max: 200,
                },
            })),
            post: vi.fn(() => Promise.resolve({ data: result })),
        };
        const w = track(mount(AddClaimsModal, { props: { open: true, batch: draft, mode: 'add', t }, ...modalMount }));
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledWith('/batches/b1/attachable-claims');
        expect(w.text()).toContain('Adicionar guias ao lote LOT-1');
        const items = w.findAll('[data-test="add-claims-item"]');
        expect(items[0].find('input').attributes('aria-label')).toBe('Selecionar a guia GUI-A — Ana');
        expect(items[0].text()).toContain('Individual');
        expect(items[1].text()).toContain('Pendente no lote LOT-9');
        expect(items[1].text()).toContain(t.pending_badge);
        expect(w.find('[data-test="add-claims-truncated"]').text()).toBe('Mostrando as primeiras 2 de 3 guias elegíveis.');
        expect(w.find('[data-test="add-claims-confirm"]').attributes('disabled')).toBeDefined();

        await w.find('[data-test="add-claims-all"]').trigger('change');
        expect(w.find('[data-test="add-claims-count"]').text()).toBe('2 selecionada(s)');
        expect(w.find('[data-test="add-claims-confirm"]').text()).toBe('Adicionar 2 guia(s)');

        await w.find('[data-test="add-claims-confirm"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/batches/b1/attach-claims', { claim_ids: ['a', 'b'] });

        const section = w.find('[role="status"] [data-test="add-claims-result"]');
        expect(section.find('[data-test="attach-message"]').classes()).toContain('alert-warning');
        const [ok, pending] = section.findAll('[data-test="attach-result-item"]');
        expect(ok.find('[data-test="attach-result-status"]').text()).toBe(t.attach_result_attached);
        expect(ok.text()).toContain(t.attach_result_clean);
        expect(pending.find('[data-test="attach-result-status"]').text()).toBe(t.attach_result_pending);
        expect(pending.find('[data-test="prevalidation-errors"]').text()).toContain('CID não informado.');
        expect(document.activeElement).toBe(section.element);
        expect(w.emitted('saved')[0][0]).toEqual(result);
    });

    it('"Reprocessar pendentes": sem lista, posta na rota do lote e mostra o resultado; recusa do servidor vira aviso', async () => {
        window.axios = { get: vi.fn(), post: vi.fn(() => Promise.resolve({ data: result })) };
        const w = track(mount(AddClaimsModal, { props: { open: true, batch: draft, mode: 'reprocess', t }, ...modalMount }));

        expect(window.axios.get).not.toHaveBeenCalled();
        expect(w.find('[data-test="reprocess-intro"]').text()).toContain('2 guia(s)');

        await w.find('[data-test="add-claims-confirm"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/batches/b1/reprocess-pending');
        expect(w.findAll('[data-test="attach-result-item"]')).toHaveLength(2);
        w.unmount();

        window.axios = { get: vi.fn(), post: vi.fn(() => Promise.reject(axios422({ batch: ['O lote LOT-1 está sendo enviado ou alterado agora.'] }))) };
        const busy = track(mount(AddClaimsModal, { props: { open: true, batch: draft, mode: 'reprocess', t }, ...modalMount }));

        await busy.find('[data-test="add-claims-confirm"]').trigger('click');
        await flushPromises();

        expect(busy.find('[data-test="add-claims-error"]').attributes('role')).toBe('alert');
        expect(busy.find('[data-test="add-claims-error"]').text()).toContain('está sendo enviado');
        expect(busy.emitted('saved')).toBeUndefined();
    });

    it('"Incluir em lote": lotes do convênio em rádio (o atual marcado), envia a guia ao lote escolhido e mostra o resultado', async () => {
        window.axios = {
            get: vi.fn(() => Promise.resolve({
                data: {
                    data: [
                        { id: 'b1', code: 'LOT-1', period_start: '2026-09-01', period_end: '2026-09-20', claims_count: 3, total_amount: 450, is_current: false, attach_url: '/batches/b1/attach-claims' },
                        { id: 'b2', code: 'LOT-2', period_start: '2026-09-10', period_end: '2026-09-20', claims_count: 1, total_amount: 150, is_current: true, attach_url: '/batches/b2/attach-claims' },
                    ],
                },
            })),
            post: vi.fn(() => Promise.resolve({ data: { ...result, attached_count: 1, pending_count: 0, results: [result.results[0]], message: '1 guia(s) incluída(s) no lote LOT-1.' } })),
        };
        const single = claim({ id: 'a', code: 'GUI-A', attach_targets_url: '/claims/a/attach-targets' });
        const w = track(mount(AttachToBatchModal, { props: { open: true, claim: single, t }, ...modalMount }));
        await flushPromises();

        const radios = w.findAll('[data-test="attach-target"]');
        expect(w.find('legend').text()).toBe(t.attach_targets_legend);
        expect(radios[1].element.checked).toBe(true);
        expect(w.find('label[for="billing-attach-target-b1"]').text()).toBe('LOT-1 — 3 guia(s) · 01/09/2026 a 20/09/2026');
        expect(w.find('label[for="billing-attach-target-b2"]').text()).toContain(t.attach_target_current);

        await radios[0].setValue(true);
        await w.find('[data-test="attach-confirm"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/batches/b1/attach-claims', { claim_ids: ['a'] });
        expect(w.find('[data-test="attach-message"]').classes()).toContain('alert-success');
        expect(w.emitted('saved')).toHaveLength(1);
    });

    it('sem lote de destino: aviso e confirmar desabilitado', async () => {
        window.axios = { get: vi.fn(() => Promise.resolve({ data: { data: [] } })) };
        const w = track(mount(AttachToBatchModal, { props: { open: true, claim: claim({ attach_targets_url: '/x' }), t }, ...modalMount }));
        await flushPromises();

        expect(w.find('[data-test="attach-no-targets"]').text()).toBe(t.attach_no_targets);
        expect(w.find('[data-test="attach-confirm"]').attributes('disabled')).toBeDefined();
    });
});
