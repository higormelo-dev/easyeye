import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import DoctorPayoutsIndex from '@/Pages/Panel/Financial/DoctorPayouts/Index.vue';
import { t, doctors, itemRows, paginator, brl } from './fixtures.js';

/**
 * Apuração do repasse: sem médico → orientação; com médico → indicadores,
 * resumo por tipo com total, itens (regra, status com atalho ao fechamento,
 * alertas), exportação do período aplicado, filtros na URL e "Fechar
 * período" liberado só quando a prévia do servidor permite.
 */

const inertia = vi.hoisted(() => ({ pageProps: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.pageProps = reactive({ locale: 'pt_BR', flash: {}, errors: {}, t_ui: { close: 'Close' } });

    return {
        usePage: () => ({ props: inertia.pageProps }),
        router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn() },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: {
        props: ['title', 'breadcrumbs'],
        template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>',
    },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: { props: ['title'], template: '<div><h4>{{ title }}</h4><slot name="actions" /></div>' },
}));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({
    default: { props: ['data'], template: '<nav class="pagination-stub" />' },
}));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: {
        props: ['title'],
        template: '<div class="dd" :data-title="title"><slot name="trigger" /><ul><slot /></ul></div>',
    },
}));
vi.mock('@/Components/Panel/PeriodFilter.vue', () => ({
    default: {
        props: ['from', 'to', 'today', 'max', 'labels', 'disabled'],
        emits: ['change'],
        template: `<button type="button" class="period-stub" :data-from="from" :data-to="to" :data-max="max"
            @click="$emit('change', { from: '2026-08-01', to: '2026-08-31', preset: 'last_month' })" />`,
    },
}));
vi.mock('@/Pages/Panel/Financial/DoctorPayouts/AllocateReceiptModal.vue', () => ({
    default: {
        props: ['open', 'rows', 'receipts', 'loading', 'action'],
        emits: ['close', 'load'],
        template: `<div class="allocate-modal-stub" :data-open="String(open)" :data-rows="rows.map((row) => row.key).join(',')" :data-action="action">
            <button type="button" class="allocate-done" @click="$emit('close', true)"></button></div>`,
    },
}));
vi.mock('@/Components/Panel/ConfirmationWithReasonModal.vue', () => ({
    default: {
        props: ['open', 'title', 'message', 'confirmLabel', 'saving', 'minLength', 'maxLength', 'error'],
        emits: ['close', 'confirm'],
        template: `<div class="reason-modal-stub" :data-open="String(open)" :data-min="minLength">
            <button type="button" class="reason-confirm" @click="$emit('confirm', 'Recebimento lançado errado')"></button></div>`,
    },
}));
vi.mock('@/Pages/Panel/Financial/DoctorPayouts/ClosePeriodModal.vue', () => ({
    default: {
        props: ['open', 'preview', 'doctorId', 'doctor', 'action'],
        emits: ['close'],
        template:
            '<div class="close-modal-stub" :data-open="String(open)" :data-doctor="doctorId" :data-action="action" />',
    },
}));

const routes = {
    index: '/doctor-payouts',
    export: '/doctor-payouts/export',
    close: '/doctor-payouts/closings',
    rules: '/doctor-payouts/rules',
    closing_show: '/doctor-payouts/closings/__ID__',
    allocate: '/doctor-payouts/allocations',
    allocation: '/doctor-payouts/allocations/__ID__',
};

const tabs = { apuracao: '/doctor-payouts', closings: '/doctor-payouts/closings', rules: '/doctor-payouts/rules' };

const filters = { doctor: 'd1', from: '2026-09-01', to: '2026-09-27', status: '', service_type: '' };

const kpis = {
    production_count: 3,
    to_release: 180,
    release_base: 300,
    release_count: 1,
    paid: 80,
    to_pay: 0,
    awaiting_count: 2,
    awaiting_forecast: 120,
    no_rule: 1,
};

const summary = [
    { service_type: 'consultation', count: 2, charged: 300, payout: 260 },
    { service_type: 'exam', count: 0, charged: 0, payout: 0 },
    { service_type: 'procedure', count: 1, charged: 450.5, payout: 0 },
];

const openPreview = {
    period_start: '2026-09-01',
    period_end: '2026-09-27',
    count: 2,
    charged_cents: 75050,
    payout_cents: 18000,
    blocking: 0,
    warnings: {},
    can_close: true,
};

let wrapper;

function mountPage(props = {}) {
    wrapper = mount(DoctorPayoutsIndex, {
        props: {
            breadcrumbs: [],
            tabs,
            filters,
            period_capped: null,
            today: '2026-09-28',
            options: { doctors },
            selected_doctor: doctors[0],
            kpis,
            summary,
            items: paginator(itemRows),
            close_preview: openPreview,
            routes,
            t,
            shared: { period: {} },
            ...props,
        },
    });

    return wrapper;
}

const closeButton = (w) => w.find('[data-test="close-open"]');

beforeEach(() => {
    inertia.pageProps.flash = {};
    vi.mocked(router.get).mockReset();
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('Financial/DoctorPayouts/Index — apuração', () => {
    it('sem médico: orienta a escolher, sem indicadores, exportação ou "Fechar período"', () => {
        const w = mountPage({
            filters: { ...filters, doctor: '' },
            selected_doctor: null,
            kpis: null,
            summary: null,
            items: null,
            close_preview: null,
        });

        expect(w.find('[data-test="select-doctor"]').text()).toContain('Select a doctor');
        expect(w.find('[data-test="select-doctor"]').text()).toContain('Choose the doctor and the period.');
        expect(w.find('[data-test="kpis"]').exists()).toBe(false);
        expect(w.find('[data-test="items"]').exists()).toBe(false);
        expect(closeButton(w).exists()).toBe(false);
        expect(w.find('.dd').exists()).toBe(false);
        expect(w.find('.close-modal-stub').exists()).toBe(false);

        // Médico inativo aparece com o aviso; status/tipo só com médico escolhido.
        const options = w.findAll('[data-test="filter-doctor"] option').map((o) => o.text());
        expect(options).toEqual(['Select the doctor', 'Dra. Ana Lima', 'Dr. Beto Reis (inactive)']);
        expect(w.find('[data-test="filter-status"]').exists()).toBe(false);
        expect(w.find('[data-test="filter-type"]').exists()).toBe(false);
    });

    it('com médico: indicadores formatados e "sem regra" em destaque com atalho para as regras', () => {
        const w = mountPage();

        expect(w.find('[data-test="kpi-production"]').text()).toBe('3');
        expect(w.find('[data-kpi="production"]').text()).toContain('3 acts in the period');
        expect(w.find('[data-test="kpi-to_release"]').text()).toBe(brl(180));
        expect(w.find('[data-kpi="to_release"]').text()).toContain('1 installment(s) received');
        expect(w.find('[data-test="kpi-release_base"]').text()).toBe(brl(300));
        expect(w.find('[data-test="kpi-paid"]').text()).toBe(brl(80));
        expect(w.find('[data-test="kpi-to_pay"]').text()).toBe(brl(0));
        // Previsão (aguardando recebimento) separada do que pode ser liberado.
        expect(w.find('[data-test="kpi-awaiting"]').text()).toBe(brl(120));
        expect(w.find('[data-kpi="awaiting"]').text()).toContain('2 act(s) without receipt');
        expect(w.find('[data-test="kpi-no_rule"]').text()).toBe('1');

        const noRule = w.find('[data-kpi="no_rule"] a');
        // Abre a lista dos itens que bloqueiam (sem regra); o alerta mantém o atalho para as regras.
        expect(noRule.attributes('href')).toBe(
            '/doctor-payouts?doctor=d1&from=2026-09-01&to=2026-09-27&status=pending&warning=no_rule',
        );
        expect(noRule.classes()).toContain('border-danger');
    });

    it('resumo por tipo com linha de total (somas em centavos)', () => {
        const w = mountPage();

        const rows = w.findAll('[data-test="summary-row"]');
        expect(rows.map((r) => r.find('th').text())).toEqual(['Consultations', 'Exams', 'Procedures and surgeries']);
        expect(rows[2].text()).toContain(brl(450.5));

        const total = w.find('[data-test="summary-total"]');
        expect(total.text()).toContain('Total');
        expect(total.text()).toContain('3');
        expect(total.text()).toContain(brl(750.5));
        expect(total.text()).toContain(brl(260));
    });

    it('itens: regra aplicada, pagador, origem do valor, alertas e status com atalho ao fechamento', () => {
        const w = mountPage();

        const rows = w.findAll('[data-test="item-row"]');
        expect(rows).toHaveLength(3);

        expect(rows[0].text()).toContain('Maria Souza');
        expect(rows[0].text()).toContain('P0001');
        expect(rows[0].text()).toContain('Private pay');
        expect(rows[0].find('[data-test="item-rule"]').text()).toBe('60% of net received');
        expect(rows[0].find('[data-test="item-payout"]').text()).toBe(brl(180));
        expect(rows[0].find('[data-status="pending"]').text()).toBe('To release');

        expect(rows[1].text()).toContain('Unimed');
        expect(rows[1].find('[data-test="item-rule"]').text()).toBe('No rule');
        expect(rows[1].find('[data-base-source="table"]').attributes('title')).toBe('From the price table.');
        const warning = rows[1].find('[data-warning="no_rule"]');
        expect(warning.text()).toContain('No rule');
        expect(warning.attributes('title')).toBe('Create a rule to close.');

        expect(rows[2].find('[data-test="item-rule"]').text()).toBe(`${brl(80)} fixed`);
        const closing = rows[2].find('[data-test="item-closing-link"]');
        expect(closing.attributes('href')).toBe('/doctor-payouts/closings/po1');
        expect(closing.text()).toContain('Paid');
        expect(closing.text()).toContain('Closing RM-000001');
    });

    it('aguardando recebimento mostra a previsão no lugar do repasse; parcela complementar mostra o recebido acumulado', () => {
        const awaiting = {
            ...itemRows[0],
            key: 'schedule:s9',
            row_id: 'schedule:s9#0',
            status: 'awaiting',
            payout: 0,
            forecast: 120,
            base_source: 'charged',
            tranche: 0,
        };
        const complement = {
            ...itemRows[0],
            key: 'schedule:s1',
            row_id: 'schedule:s1#2',
            status: 'pending',
            base_source: 'received',
            tranche: 2,
            charged: 200,
            received: 230,
            payout: 120,
            released_before: 18,
        };
        const w = mountPage({ items: paginator([awaiting, complement]) });

        const rows = w.findAll('[data-test="item-row"]');
        expect(rows[0].find('[data-status="awaiting"]').text()).toBe('Awaiting receipt');
        expect(rows[0].find('[data-test="item-forecast"]').text()).toBe(`Forecast ${brl(120)}`);
        expect(rows[0].find('[data-test="item-payout"]').text()).not.toContain(brl(0));

        expect(rows[1].find('[data-test="item-tranche"]').text()).toBe(`Installment 2 · Received to date ${brl(230)}`);
        expect(rows[1].find('[data-test="item-payout"]').text()).toContain(brl(120));
        expect(rows[1].find('[data-test="item-released-before"]').text()).toBe(`Already released ${brl(18)}`);
        expect(rows[1].find('[data-base-source="received"]').text()).toContain('Received');
    });

    it('fechamento: avisa período antes do último fechado e total negativo', () => {
        const before = mountPage({
            close_preview: { ...openPreview, can_close: false, last_closed_until: '2026-09-30' },
        });
        expect(before.find('[data-test="close-hint"]').text()).toBe('There is already a closing up to 30/09/2026.');
        before.unmount();

        const negative = mountPage({ close_preview: { ...openPreview, can_close: false, payout_cents: -500 } });
        expect(negative.find('[data-test="close-hint"]').text()).toBe('The total to release is negative.');
    });

    it('selecionar itens habilita "Alocar recebimento" e abre o modal com os itens escolhidos', async () => {
        const w = mountPage();
        const open = w.find('[data-test="allocate-open"]');

        expect(open.attributes('disabled')).toBeDefined();

        await w.findAll('[data-test="select-row"]')[0].setValue(true);
        expect(open.attributes('disabled')).toBeUndefined();

        await open.trigger('click');

        const modal = w.find('.allocate-modal-stub');
        expect(modal.attributes('data-open')).toBe('true');
        expect(modal.attributes('data-rows')).toBe('schedule:s1');
        expect(modal.attributes('data-action')).toBe('/doctor-payouts/allocations');

        // Alocação concluída: fecha e limpa a seleção.
        await w.find('.allocate-done').trigger('click');
        expect(w.find('.allocate-modal-stub').attributes('data-open')).toBe('false');
        expect(open.attributes('disabled')).toBeDefined();
    });

    it('trocar de página limpa a seleção (o botão volta a ficar desabilitado)', async () => {
        const w = mountPage();

        await w.findAll('[data-test="select-row"]')[0].setValue(true);
        expect(w.find('[data-test="allocate-open"]').attributes('disabled')).toBeUndefined();

        await w.setProps({ items: paginator(itemRows, { current_page: 2 }) });

        expect(w.find('[data-test="allocate-open"]').attributes('disabled')).toBeDefined();
        expect(w.findAll('[data-test="select-row"]').some((box) => box.element.checked)).toBe(false);
    });

    it('selecionar todos marca as linhas da página', async () => {
        const w = mountPage();

        await w.find('[data-test="select-all"]').setValue(true);

        expect(w.findAll('[data-test="select-row"]').every((box) => box.element.checked)).toBe(true);
    });

    it('recebimento manual do item aparece na coluna e o estorno pede motivo e envia DELETE', async () => {
        const withManual = [
            {
                ...itemRows[0],
                manual_allocations: [{ id: 'al1', amount: 50, date: '2026-09-05', description: 'Depósito convênio' }],
            },
        ];
        const w = mountPage({ items: paginator(withManual) });

        const manual = w.find('[data-test="item-manual"]');
        expect(manual.text()).toContain(brl(50));

        await manual.find('[data-test="manual-reverse"]').trigger('click');
        const reason = w.find('.reason-modal-stub');
        expect(reason.attributes('data-open')).toBe('true');
        expect(reason.attributes('data-min')).toBe('10');

        await reason.find('.reason-confirm').trigger('click');

        expect(router.delete).toHaveBeenCalledWith(
            '/doctor-payouts/allocations/al1',
            expect.objectContaining({ data: { reason: 'Recebimento lançado errado' } }),
        );
    });

    it('sem itens no período: mensagem de vazio na tabela', () => {
        const w = mountPage({ items: paginator([]) });

        expect(w.find('[data-test="items-empty"]').text()).toBe('No attendance in the period.');
    });

    it('prévia liberada: "Fechar período" abre a confirmação com o médico e a rota de fechamento', async () => {
        const w = mountPage();

        expect(closeButton(w).attributes('disabled')).toBeUndefined();
        expect(w.find('.close-modal-stub').attributes('data-open')).toBe('false');

        await closeButton(w).trigger('click');

        const modal = w.find('.close-modal-stub');
        expect(modal.attributes('data-open')).toBe('true');
        expect(modal.attributes('data-doctor')).toBe('d1');
        expect(modal.attributes('data-action')).toBe('/doctor-payouts/closings');
    });

    it('itens sem regra: botão desabilitado, aviso com a contagem e atalho para as regras', async () => {
        const w = mountPage({ close_preview: { ...openPreview, blocking: 3, can_close: false } });

        expect(closeButton(w).attributes('disabled')).toBeDefined();

        const blocked = w.find('[data-test="close-blocked"]');
        expect(blocked.text()).toContain('3 item(s) without a payout rule.');
        expect(blocked.find('[data-test="go-to-rules"]').attributes('href')).toBe('/doctor-payouts/rules');
        expect(closeButton(w).attributes('aria-describedby')).toBe(blocked.attributes('id'));

        await closeButton(w).trigger('click');
        expect(w.find('.close-modal-stub').attributes('data-open')).toBe('false');
    });

    it('nada pendente: botão desabilitado com a explicação', () => {
        const w = mountPage({ close_preview: { ...openPreview, count: 0, payout_cents: 0, can_close: false } });

        expect(closeButton(w).attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="close-blocked"]').exists()).toBe(false);
        expect(w.find('[data-test="close-hint"]').text()).toBe('There are no pending items in this period.');
    });

    it('trocar médico, período, status ou tipo visita a URL com o conjunto completo de filtros', async () => {
        const w = mountPage();

        await w.find('[data-test="filter-doctor"]').setValue('d2');
        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            {
                doctor: 'd2',
                from: '2026-09-01',
                to: '2026-09-27',
                status: '',
                service_type: '',
                receipt: '',
                warning: '',
            },
            expect.objectContaining({ preserveState: true, preserveScroll: true }),
        );

        await w.find('.period-stub').trigger('click');
        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            {
                doctor: 'd1',
                from: '2026-08-01',
                to: '2026-08-31',
                status: '',
                service_type: '',
                receipt: '',
                warning: '',
            },
            expect.objectContaining({ preserveState: true, preserveScroll: true }),
        );

        await w.find('[data-test="filter-status"]').setValue('paid');
        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            expect.objectContaining({ doctor: 'd1', status: 'paid' }),
            expect.any(Object),
        );

        await w.find('[data-test="filter-type"]').setValue('exam');
        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            expect.objectContaining({ service_type: 'exam' }),
            expect.any(Object),
        );
    });

    it('o período não aceita datas futuras (máximo = hoje do servidor)', () => {
        const w = mountPage();

        expect(w.find('.period-stub').attributes('data-max')).toBe('2026-09-28');
    });

    it('limpar filtros da lista mantém médico e período', async () => {
        const w = mountPage({ filters: { ...filters, status: 'pending', service_type: 'exam', receipt: 'awaiting' } });

        await w.find('[data-test="filters-clear"]').trigger('click');

        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            {
                doctor: 'd1',
                from: '2026-09-01',
                to: '2026-09-27',
                status: '',
                service_type: '',
                receipt: '',
                warning: '',
            },
            expect.any(Object),
        );
    });

    it('filtro de recebimento vai na URL; o período é pela data do recebimento', async () => {
        const w = mountPage();

        expect(w.find('[data-test="period-hint"]').text()).toBe(
            'By the receipt date. The rule applies by the attendance date.',
        );

        const options = w.findAll('[data-test="filter-receipt"] option').map((option) => option.text());
        expect(options[0]).toBe('Any situation');
        expect(options).toContain('Awaiting receipt');
        expect(options).toContain('No own charge');

        await w.find('[data-test="filter-receipt"]').setValue('awaiting');
        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            expect.objectContaining({ doctor: 'd1', receipt: 'awaiting' }),
            expect.any(Object),
        );
    });

    it('recebimento da clínica: indicadores separados da previsão, avisos e coluna por item', () => {
        const receipt = {
            billed: 500,
            glosa: 200,
            received: 300,
            open: 150,
            difference: 20,
            open_count: 1,
            not_linked_count: 1,
            unconfirmed_count: 2,
        };
        const w = mountPage({ kpis: { ...kpis, receipt } });

        const section = w.find('[data-test="receipt-kpis"]');
        expect(section.find('h2').text()).toBe('Clinic receipts');
        expect(section.attributes('aria-labelledby')).toBe(section.find('h2').attributes('id'));
        expect(w.find('[data-test="kpi-receipt_billed"]').text()).toBe(brl(500));
        expect(w.find('[data-test="kpi-receipt_glosa"]').text()).toBe(brl(200));
        expect(w.find('[data-test="kpi-receipt_received"]').text()).toBe(brl(300));
        expect(w.find('[data-test="kpi-receipt_open"]').text()).toBe(brl(150));
        expect(w.find('[data-kpi="receipt_open"]').text()).toContain('1 attendance(s) awaiting');

        expect(w.find('[data-test="receipt-note-unconfirmed"]').text()).toContain(
            '2 attendance(s) paid without entry.',
        );
        expect(w.find('[data-test="receipt-note-difference"]').text()).toContain(brl(20));
        expect(w.find('[data-test="receipt-note-not_linked"]').text()).toContain('1 item(s) without an own charge.');

        const cells = w.findAll('[data-test="item-receipt"]');
        expect(cells).toHaveLength(3);
        expect(cells[0].attributes('data-receipt')).toBe('received');
        expect(cells[0].text()).toContain(brl(300));
        expect(cells[0].text()).toContain('Received');
        expect(cells[1].attributes('data-receipt')).toBe('not_linked');
        expect(cells[1].text()).toContain('No own charge');
        expect(cells[1].text()).not.toContain('R$');
        expect(cells[2].text()).toContain(`Glosa ${brl(200)}`);
        expect(cells[2].text()).toContain('Denied (glosa)');
        expect(cells[2].find('[data-test="item-receipt-shared"]').text()).toBe('Whole attendance (2 items)');
        expect(cells[0].find('[data-test="item-receipt-shared"]').exists()).toBe(false);
    });

    it('sem dados de recebimento (kpis.receipt ausente) o bloco não aparece', () => {
        const w = mountPage();

        expect(w.find('[data-test="receipt-kpis"]').exists()).toBe(false);
    });

    it('exportação: CSV e Excel com médico e período aplicados', () => {
        const w = mountPage();

        const links = w.findAll('.dd a').map((a) => a.attributes('href'));
        expect(links).toEqual([
            '/doctor-payouts/export?doctor=d1&from=2026-09-01&to=2026-09-27&format=csv',
            '/doctor-payouts/export?doctor=d1&from=2026-09-01&to=2026-09-27&format=xlsx',
        ]);
        expect(w.find('.dd').text()).toContain('CSV spreadsheet');
        expect(w.find('.dd').text()).toContain('Excel spreadsheet');
    });

    it('período acima do limite: aviso com as datas pedidas e as mostradas', () => {
        const w = mountPage({
            period_capped: { requested_from: '2024-01-01', requested_to: '2026-09-27', max_days: 366 },
        });

        expect(w.find('[data-test="period-capped"]').text()).toBe(
            'Requested 01/01/2024 to 27/09/2026 exceeds 366 days. Showing 01/09/2026 to 27/09/2026.',
        );
    });

    it('abas: a apuração é a página atual', () => {
        const w = mountPage();

        expect(w.find('[data-test="tab-apuracao"]').attributes('aria-current')).toBe('page');
        expect(w.find('[data-test="tab-closings"]').attributes('aria-current')).toBeUndefined();
        expect(w.find('[data-test="tab-rules"]').attributes('href')).toBe('/doctor-payouts/rules');
    });

    it('total a repassar e ajustes: o total do resumo bate com já pago + a pagar + a liberar', () => {
        const w = mountPage({
            kpis: { ...kpis, to_release: 60, paid: 40, to_pay: 60, adjustments: -20, to_transfer: 120 },
            summary: [
                { service_type: 'consultation', count: 2, charged: 300, payout: 180 },
                { service_type: 'exam', count: 0, charged: 0, payout: 0 },
                { service_type: 'procedure', count: 0, charged: 0, payout: 0 },
            ],
        });

        expect(w.find('[data-kpi="to_transfer"]').text()).toContain('Total to transfer');
        expect(w.find('[data-kpi="to_transfer"]').text()).toContain(brl(120));
        expect(w.find('[data-test="summary-adjustments"]').text()).toContain(brl(-20));
        expect(w.find('[data-test="summary-total"]').text()).toContain(brl(160)); // 180 − 20 = 40 + 60 + 60
    });

    it('clicar no tipo do resumo abre a lista com as MESMAS linhas (com recebimento, sem a previsão)', async () => {
        const w = mountPage();

        await w.find('[data-type="exam"] [data-test="summary-open-type"]').trigger('click');

        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            {
                doctor: 'd1',
                from: '2026-09-01',
                to: '2026-09-27',
                status: 'in_payout',
                service_type: 'exam',
                receipt: '',
                warning: '',
            },
            expect.any(Object),
        );
    });

    it('bloqueio por falta de regra: "Ver itens" filtra os que bloqueiam; o KPI leva à mesma lista', async () => {
        const w = mountPage({ close_preview: { ...openPreview, blocking: 2, can_close: false } });

        await w.find('[data-test="see-no-rule-items"]').trigger('click');

        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            expect.objectContaining({ status: 'pending', warning: 'no_rule', service_type: '', receipt: '' }),
            expect.any(Object),
        );
        expect(w.find('[data-test="go-to-rules"]').attributes('href')).toBe('/doctor-payouts/rules');
    });

    it('filtro por alerta e status "com recebimento" vão na URL', async () => {
        const w = mountPage();

        expect(w.find('[data-test="filter-status"]').text()).toContain('With receipt (no forecast)');

        await w.find('[data-test="filter-warning"]').setValue('no_rule');
        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts',
            expect.objectContaining({ warning: 'no_rule' }),
            expect.any(Object),
        );
    });

    it('período anterior ao último fechamento: aviso de consulta histórica', () => {
        const w = mountPage({
            close_preview: { ...openPreview, can_close: false, historical: true, last_closed_until: '2026-09-30' },
        });

        expect(w.find('[data-test="historical-period"]').text()).toBe(
            'This period ends before the last closing (30/09/2026).',
        );
    });

    it('exames do equipamento sem médico: aviso com singular/plural, mesmo sem médico escolhido', () => {
        expect(mountPage({ unassigned_exams: 1 }).find('[data-test="unassigned-exams"]').text()).toBe(
            '1 equipment exam in the period has no doctor.',
        );
        wrapper.unmount();

        const w = mountPage({
            unassigned_exams: 3,
            filters: { ...filters, doctor: '' },
            selected_doctor: null,
            kpis: null,
            summary: null,
            items: null,
            close_preview: null,
        });
        expect(w.find('[data-test="unassigned-exams"]').text()).toBe('3 equipment exams in the period have no doctor.');
    });

    it('mostra o retorno do servidor (flash.message) e o fecha com estado local', async () => {
        inertia.pageProps.flash = { message: 'Payout closed: RM-000002.' };
        const w = mountPage();

        const alert = w.find('[data-test="flash-message"]');
        expect(alert.text()).toContain('Payout closed: RM-000002.');

        await alert.find('button[aria-label="Close"]').trigger('click');
        expect(w.find('[data-test="flash-message"]').exists()).toBe(false);
    });
});
