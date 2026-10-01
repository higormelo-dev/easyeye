import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ClaimsTable from '@/Pages/Panel/Financial/Billing/ClaimsTable.vue';
import BatchesTable from '@/Pages/Panel/Financial/Billing/BatchesTable.vue';
import EligibleTable from '@/Pages/Panel/Financial/Billing/EligibleTable.vue';
import { resetInertiaMock } from './support/inertiaMock.js';
import { t, claim, batch, schedule, paginate, brl, norm } from './support/fixtures.js';

vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());

beforeEach(() => resetInertiaMock());

describe('ClaimsTable', () => {
    it('mostra só as ações que o servidor permite (allowed_actions)', () => {
        const w = mount(ClaimsTable, {
            props: {
                t,
                claims: [
                    claim({ id: 'a', allowed_actions: ['pay', 'deny'] }),
                    claim({ id: 'b', allowed_actions: ['pay'], status: 'denied' }),
                    claim({ id: 'c', allowed_actions: [], status: 'paid' }),
                ],
            },
        });

        const rows = w.findAll('[data-test="claim-row"]');
        expect(rows[0].find('[data-test="receive"]').exists()).toBe(true);
        expect(rows[0].find('[data-test="deny"]').exists()).toBe(true);
        expect(rows[1].find('[data-test="receive"]').exists()).toBe(true);
        expect(rows[1].find('[data-test="deny"]').exists()).toBe(false);
        expect(rows[2].find('[data-test="receive"]').exists()).toBe(false);
        expect(rows[2].find('[data-test="deny"]').exists()).toBe(false);
    });

    it('código GUI e nº da guia TISS em colunas próprias; valor, glosa e pago formatados pelo idioma', () => {
        const w = mount(ClaimsTable, {
            props: {
                t,
                claims: [
                    claim({ guide_number: 'GUI-202609-000123', amount: 1234.5, glosa_amount: 50, paid_amount: 0 }),
                ],
            },
        });

        const row = w.find('[data-test="claim-row"]');
        expect(row.find('[data-test="claim-code"]').text()).toBe('GUI-0001');
        expect(row.find('[data-test="claim-tiss-number"]').text()).toBe('GUI-202609-000123');
        expect(norm(row.text())).toContain(norm(brl(1234.5)));
        expect(norm(row.text())).toContain(norm(brl(50)));
        expect(row.text()).toContain('20/09/2026');
        expect(row.find('[data-test="receive"]').attributes('aria-label')).toBe(t.action_receive);
        expect(row.find('[data-test="deny"]').attributes('aria-label')).toBe(t.action_deny);
        expect(row.find('[data-test="claim-status"]').classes()).toContain('badge-soft-info');
    });

    it('colunas secundárias somem abaixo do desktop (d-none d-lg-table-cell)', () => {
        const w = mount(ClaimsTable, { props: { t, claims: [claim()] } });

        const headers = w.findAll('thead th');
        const secondary = headers.filter((th) => th.classes().includes('d-none'));
        expect(secondary.map((th) => th.text())).toEqual(
            expect.arrayContaining([t.col_tiss_number, t.col_covenant, t.col_batch, t.col_glosa, t.col_received]),
        );
        secondary.forEach((th) => expect(th.classes().some((c) => /^d-(lg|xl)-table-cell$/.test(c))).toBe(true));
    });

    it('o código do lote é um botão que emite filter-batch com a guia', async () => {
        const c = claim();
        const w = mount(ClaimsTable, {
            props: { t, claims: [c, claim({ id: 'x', batch_id: null, batch_code: null })] },
        });

        const [withBatch, withoutBatch] = w.findAll('[data-test="claim-row"]');
        await withBatch.find('[data-test="claim-batch"]').trigger('click');

        expect(w.emitted('filter-batch')[0][0]).toEqual(c);
        expect(withoutBatch.find('[data-test="claim-batch"]').exists()).toBe(false);
    });

    it('guia TISS fora de lote: badge "Fora de lote" (individual) ou "Pendência" (pré-validação) e o aviso', () => {
        const w = mount(ClaimsTable, {
            props: {
                t,
                claims: [
                    claim({ id: 'out', out_of_batch: true, batch_id: null, batch_code: null }),
                    claim({ id: 'pend', has_pending_guide: true }),
                    claim({ id: 'ok' }),
                ],
            },
        });

        const [out, pending, ok] = w.findAll('[data-test="claim-row"]');
        expect(out.find('[data-test="claim-out-of-batch"]').text()).toContain(t.out_of_batch_badge);
        expect(out.find('[data-test="claim-out-of-batch"]').attributes('title')).toBe(t.out_of_batch_hint);
        expect(out.find('[data-test="claim-out-of-batch"] .visually-hidden').text()).toContain(t.out_of_batch_hint);
        expect(pending.find('[data-test="claim-pending"]').text()).toContain(t.pending_badge);
        expect(pending.find('[data-test="claim-out-of-batch"]').exists()).toBe(false);
        expect(ok.find('[data-test="claim-pending"]').exists()).toBe(false);
        expect(ok.find('[data-test="claim-out-of-batch"]').exists()).toBe(false);
        expect(w.find('[data-test="out-of-batch-notice"]').text()).toContain(t.out_of_batch_notice);
    });

    it('sem guia fora de lote, não mostra o aviso', () => {
        const w = mount(ClaimsTable, { props: { t, claims: [claim()] } });

        expect(w.find('[data-test="out-of-batch-notice"]').exists()).toBe(false);
    });

    it('emite receive/deny/check-pending com a guia e mostra spinner durante a verificação', async () => {
        const c = claim();
        const w = mount(ClaimsTable, { props: { t, claims: [c], checkingClaimId: 'c1' } });

        await w.find('[data-test="receive"]').trigger('click');
        await w.find('[data-test="deny"]').trigger('click');

        expect(w.emitted('receive')[0][0]).toEqual(c);
        expect(w.emitted('deny')[0][0]).toEqual(c);
        expect(w.find('[data-test="check-pending"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="check-pending"] i').classes()).toContain('spinner-border');
    });

    // Fase 4: o teto ("Mostrando X de N") deu lugar à paginação do servidor.
    it('pagina pelo servidor: TablePagination com os links da aba e rótulos traduzidos', () => {
        const w = mount(ClaimsTable, {
            props: { t, claims: paginate([claim()], { total: 120, last_page: 3 }, 'claims_page') },
            // Sobrescreve o stub global de <Link> (sem href) de tests/JavaScript/setup.js.
            global: { stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } } },
        });

        const pagination = w.find('[data-test="claims-pagination"]');
        expect(pagination.exists()).toBe(true);
        expect(pagination.text()).toContain('Exibindo 1–1 de 120 guias');
        expect(pagination.find('nav').attributes('aria-label')).toBe('Paginação da aba Guias');
        expect(pagination.findAll('a.page-link').map((a) => a.attributes('href'))).toContain(
            '/panel/financial/billing?claims_page=2',
        );
        expect(pagination.find('a[aria-label="Próxima página"]').exists()).toBe(true);
    });

    it('uma página só: sem paginação; lista vazia com busca mostra o termo', () => {
        const single = mount(ClaimsTable, { props: { t, claims: paginate([claim()]) } });
        expect(single.find('[data-test="claims-pagination"]').exists()).toBe(false);

        const empty = mount(ClaimsTable, { props: { t, claims: paginate([]), search: '50%' } });
        expect(empty.find('[data-test="claims-no-results"]').text()).toBe('Nenhum resultado para “50%”.');
    });

    it('cabeçalhos ordenáveis (whitelist do servidor) emitem sort; a busca emite search', async () => {
        const w = mount(ClaimsTable, { props: { t, claims: paginate([claim()]), sort: 'created', direction: 'desc' } });

        const sortable = w.findAll('thead th[aria-sort]');
        expect(sortable.map((th) => th.text())).toEqual([t.col_guide, t.col_attendance, t.col_patient, t.col_amount]);
        expect(sortable[0].attributes('aria-sort')).toBe('descending');
        expect(sortable[2].find('button').attributes('title')).toBe('Ordenar por Paciente');

        await sortable[2].find('button').trigger('click');
        expect(w.emitted('sort')[0][0]).toEqual({ sort: 'patient', direction: 'asc' });

        await w.find('[data-test="claims-search"] input').setValue('maria');
        expect(w.emitted('search')[0][0]).toBe('maria');
        expect(w.find('[data-test="claims-search"] input').attributes('aria-label')).toBe(t.search_claims);
    });

    it('menu "Mais ações" só com as ações permitidas: corrigir pendência e cancelar (perigosa)', async () => {
        const w = mount(ClaimsTable, {
            props: {
                t,
                claims: paginate([
                    claim({
                        id: 'both',
                        code: 'GUI-1',
                        allowed_actions: ['pay', 'deny', 'cancel', 'fix_pending'],
                        fix_pending: { url: '/fix' },
                    }),
                    claim({ id: 'cancel-only', code: 'GUI-2', allowed_actions: ['cancel'] }),
                    claim({ id: 'none', code: 'GUI-3', allowed_actions: ['pay'] }),
                ]),
            },
            global: { stubs: { teleport: true } },
            attachTo: document.body,
        });

        const [both, cancelOnly, none] = w.findAll('[data-test="claim-row"]');
        expect(none.find('[data-test="claim-menu"]').exists()).toBe(false);
        expect(both.find('[data-test="claim-menu"]').attributes('data-row-actions')).toBe('claim-both');

        const trigger = both.find('[data-test="claim-menu"] button');
        expect(trigger.attributes('aria-label')).toBe('Mais ações da guia GUI-1');
        await trigger.trigger('click');

        expect(both.find('[data-test="fix-pending"]').text()).toContain(t.action_fix_pending);
        expect(both.find('[data-test="cancel-claim"]').classes()).toContain('text-danger');

        await both.find('[data-test="cancel-claim"]').trigger('click');
        expect(w.emitted('cancel')[0][0].id).toBe('both');

        await cancelOnly.find('[data-test="claim-menu"] button').trigger('click');
        expect(cancelOnly.find('[data-test="fix-pending"]').exists()).toBe(false);
        await cancelOnly.find('[data-test="cancel-claim"]').trigger('click');
        expect(w.emitted('cancel')[1][0].id).toBe('cancel-only');

        await both.find('[data-test="claim-menu"] button').trigger('click');
        await both.find('[data-test="fix-pending"]').trigger('click');
        expect(w.emitted('fix-pending')[0][0].id).toBe('both');

        w.unmount();
    });

    it('guia cancelada mostra quando, quem e o motivo', () => {
        const w = mount(ClaimsTable, {
            props: {
                t,
                claims: paginate([
                    claim({
                        status: 'cancelled',
                        status_label: 'Cancelado',
                        allowed_actions: [],
                        cancelled_at: '2026-09-25T10:00:00-03:00',
                        cancelled_by_name: 'Ana Financeiro',
                        cancel_reason: 'Convênio errado no atendimento',
                    }),
                ]),
            },
        });

        expect(w.find('[data-test="claim-cancelled-info"]').text()).toBe('Cancelada em 25/09/2026 por Ana Financeiro');
        expect(w.find('[data-test="claim-cancel-reason"]').text()).toBe('Motivo: Convênio errado no atendimento');
        expect(w.find('[data-test="claim-cancel-reason"]').attributes('title')).toBe('Convênio errado no atendimento');
    });
});

describe('BatchesTable', () => {
    it('lote TISS: "Enviar à operadora" + XML; lote particular: "Marcar como cobrado" sem XML', () => {
        const w = mount(BatchesTable, {
            props: {
                t,
                batches: [
                    batch({ id: 'tiss' }),
                    batch({ id: 'part', is_particular: true, is_tiss: false, allowed_actions: ['submit'] }),
                ],
            },
        });

        const [tissRow, particularRow] = w.findAll('[data-test="batch-row"]');
        expect(tissRow.find('[data-test="submit-batch"]').text()).toBe(t.action_submit_tiss);
        expect(tissRow.find('[data-test="download-xml"]').attributes('aria-label')).toBe(t.action_download_xml);
        expect(tissRow.text()).toContain('2 no lote');
        expect(tissRow.text()).toContain('1 com pendência');
        expect(norm(tissRow.text())).toContain(norm(brl(450)));

        expect(particularRow.find('[data-test="submit-batch"]').text()).toBe(t.action_mark_charged);
        expect(particularRow.find('[data-test="download-xml"]').exists()).toBe(false);
        expect(particularRow.text()).toContain(t.particular_badge);
    });

    it('mostra a data de envio (ou "Não enviado") e o período', () => {
        const w = mount(BatchesTable, {
            props: {
                t,
                batches: [batch(), batch({ id: 'b2', submitted_at: '2026-09-21T14:30:00', status: 'submitted' })],
            },
        });

        const [draft, sent] = w.findAll('[data-test="batch-row"]');
        expect(draft.find('[data-test="batch-submitted-at"]').text()).toBe(t.not_submitted);
        expect(sent.find('[data-test="batch-submitted-at"]').text()).toContain('21/09/2026');
        expect(draft.text()).toContain('01/09/2026 a 20/09/2026');
    });

    it('não mostra "enviar" para lote já enviado e desabilita a linha em envio', async () => {
        const w = mount(BatchesTable, {
            props: {
                t,
                submittingBatchId: 'b1',
                batches: [batch(), batch({ id: 'b2', status: 'submitted', allowed_actions: ['download_xml'] })],
            },
        });

        const [sending, sent] = w.findAll('[data-test="batch-row"]');
        expect(sending.find('[data-test="submit-batch"]').attributes('disabled')).toBeDefined();
        expect(sent.find('[data-test="submit-batch"]').exists()).toBe(false);
    });

    it('emite submit e view-claims com o lote (botão só-ícone rotulado)', async () => {
        const b = batch();
        const w = mount(BatchesTable, { props: { t, batches: [b] } });

        await w.find('[data-test="submit-batch"]').trigger('click');
        const view = w.find('[data-test="view-claims"]');
        expect(view.attributes('aria-label')).toBe('Ver as guias do lote LOT-0007');
        await view.trigger('click');

        expect(w.emitted('submit')[0][0]).toEqual(b);
        expect(w.emitted('view-claims')[0][0]).toEqual(b);
    });

    it('"Cancelar lote" só no menu de lote em rascunho (allowed_actions); lote cancelado mostra quem/quando/motivo', async () => {
        const w = mount(BatchesTable, {
            props: {
                t,
                batches: paginate([
                    batch({ id: 'draft', allowed_actions: ['submit', 'download_xml', 'cancel'] }),
                    batch({
                        id: 'gone',
                        code: 'LOT-0009',
                        status: 'cancelled',
                        status_label: 'Cancelado',
                        allowed_actions: [],
                        included_count: 0,
                        pending_count: 0,
                        cancelled_at: '2026-09-25T10:00:00-03:00',
                        cancelled_by_name: null,
                        cancel_reason: 'Lote gerado em duplicidade',
                    }),
                ]),
            },
            global: { stubs: { teleport: true } },
            attachTo: document.body,
        });

        const [draft, gone] = w.findAll('[data-test="batch-row"]');
        expect(gone.find('[data-test="batch-menu"]').exists()).toBe(false);

        const trigger = draft.find('[data-test="batch-menu"] button');
        expect(trigger.attributes('aria-label')).toBe('Mais ações do lote LOT-0007');
        await trigger.trigger('click');
        await draft.find('[data-test="cancel-batch"]').trigger('click');
        expect(w.emitted('cancel')[0][0].id).toBe('draft');

        expect(w.find('[data-test="batch-cancelled-info"]').text()).toContain(
            'Lote cancelado em 25/09/2026 — Motivo: Lote gerado em duplicidade',
        );

        w.unmount();
    });

    it('ordena por Lote/Período/Total, busca e pagina (batches_page)', async () => {
        const w = mount(BatchesTable, {
            props: {
                t,
                batches: paginate([batch()], { total: 60, last_page: 3 }, 'batches_page'),
                sort: 'total',
                direction: 'asc',
            },
        });

        const sortable = w.findAll('thead th[aria-sort]');
        expect(sortable.map((th) => th.text())).toEqual([t.col_batch, t.col_period, t.col_total]);
        expect(sortable[2].attributes('aria-sort')).toBe('ascending');

        await sortable[2].find('button').trigger('click');
        expect(w.emitted('sort')[0][0]).toEqual({ sort: 'total', direction: 'desc' });

        await w.find('[data-test="batches-search"] input').setValue('LOT-00');
        expect(w.emitted('search')[0][0]).toBe('LOT-00');

        expect(w.find('[data-test="batches-pagination"]').text()).toContain('de 60 lotes');
    });
});

describe('EligibleTable', () => {
    it('checkbox do cabeçalho fica parcial com seleção parcial e rotula cada linha', async () => {
        const w = mount(EligibleTable, {
            props: { t, schedules: [schedule(), schedule({ id: 's2', patient_name: 'Ana' })], selectedIds: ['s1'] },
            attachTo: document.body,
        });

        await w.vm.$nextTick();

        const selectAll = w.find('[data-test="select-all"]');
        expect(selectAll.element.indeterminate).toBe(true);
        expect(selectAll.attributes('aria-label')).toBe(t.eligible_select_all);
        expect(w.findAll('[data-test="eligible-row"]')[1].find('input').attributes('aria-label')).toContain(
            'Selecionar Ana',
        );
        expect(w.find('[data-test="new-batch"]').text()).toBe('Gerar lote com 1 selecionado(s)');

        w.unmount();
    });

    it('mostra o valor da tabela de preços (ou —) por atendimento', () => {
        const w = mount(EligibleTable, {
            props: { t, schedules: [schedule({ suggested_price: 150 }), schedule({ id: 's2' })], selectedIds: [] },
        });

        const [priced, unpriced] = w.findAll('[data-test="eligible-price"]');
        expect(norm(priced.text())).toBe(norm(brl(150)));
        expect(unpriced.text()).toBe('—');
    });

    it('emite bill/toggle/new-batch', async () => {
        const s = schedule();
        const w = mount(EligibleTable, { props: { t, schedules: [s], selectedIds: [] } });

        await w.find('[data-test="eligible-row"] button').trigger('click');
        await w.find('[data-test="eligible-row"] input').trigger('change');
        await w.find('[data-test="new-batch"]').trigger('click');

        expect(w.emitted('bill')[0][0]).toEqual(s);
        expect(w.emitted('toggle')[0][0]).toBe('s1');
        expect(w.emitted('new-batch')).toHaveLength(1);
        expect(w.find('[data-test="new-batch"]').text()).toBe(t.btn_new_batch);
    });

    it('paginado: o cabeçalho marca a página; o contador soma os marcados de outras páginas', async () => {
        const w = mount(EligibleTable, {
            props: {
                t,
                schedules: paginate([schedule(), schedule({ id: 's2' })], { total: 80, last_page: 2 }, 'eligible_page'),
                // s9 foi marcado em outra página.
                selectedIds: ['s9'],
            },
            attachTo: document.body,
        });

        await w.vm.$nextTick();

        const selectAll = w.find('[data-test="select-all"]');
        expect(selectAll.element.checked).toBe(false);
        expect(selectAll.element.indeterminate).toBe(false);
        expect(w.find('[data-test="new-batch"]').text()).toBe('Gerar lote com 1 selecionado(s)');
        expect(w.find('[data-test="eligible-pagination"]').text()).toContain('de 80 atendimentos');

        const sortable = w.findAll('thead th[aria-sort]');
        expect(sortable.map((th) => th.text())).toEqual([t.col_date, t.col_patient, t.col_covenant]);
        await sortable[2].find('button').trigger('click');
        expect(w.emitted('sort')[0][0]).toEqual({ sort: 'covenant', direction: 'asc' });

        await w.find('[data-test="eligible-search"] input').setValue('joão');
        expect(w.emitted('search')[0][0]).toBe('joão');

        w.unmount();
    });
});
