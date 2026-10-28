import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import BillingIndex from '@/Pages/Panel/Financial/Billing/Index.vue';
import { router, resetInertiaMock, forms } from './support/inertiaMock.js';
import { t, claim, batch, schedule, kpis, lists, paginate, brl, norm } from './support/fixtures.js';

vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: { props: ['title'], template: '<div><slot name="actions" /></div>' },
}));
vi.mock('@/Components/Panel/SearchSelect.vue', () => ({ default: { props: ['modelValue'], template: '<div />' } }));
vi.mock('@/Components/Panel/Cid10Picker.vue', () => ({
    default: { props: ['modelValue'], template: '<div class="cid-stub" />' },
}));

// Fase 4: as três abas chegam paginadas (paginator do Laravel) e com `lists`.
const baseProps = {
    breadcrumbs: [],
    eligibleSchedules: paginate(
        [schedule(), schedule({ id: 's2', covenant_id: 'cov-2', patient_name: 'Ana' })],
        {},
        'eligible_page',
    ),
    claims: paginate([claim()], {}, 'claims_page'),
    batches: paginate([batch()], {}, 'batches_page'),
    kpis,
    totals: { eligible: 2, claims: 1, batches: 1 },
    lists: lists(),
    covenants: [{ id: 'cov-1', name: 'Unimed', has_ans_registry: true, has_tiss_operator: true }],
    filters: {
        from: '2026-09-01',
        to: '2026-09-26',
        covenant_id: null,
        claim_status: null,
        batch_id: null,
        batch_code: null,
        tab: 'eligible',
    },
    claimStatuses: [
        { value: 'draft', label: 'Rascunho' },
        { value: 'submitted', label: 'Enviado' },
        { value: 'tiss_pending', label: 'Pendência TISS (fora de lote)' },
    ],
    paymentMethods: [{ value: 'transfer', label: 'Transferência bancária' }],
    today: '2026-09-26',
    storeIndividualUrl: '/billing/individual',
    storeBatchUrl: '/billing/batch',
    importReturnUrl: '/billing/import-return',
    glosasUrl: '/financial/tiss/glosas',
    procedurePricesUrl: '/financial/procedure-prices',
    cid10SearchUrl: '/cid10',
    glosaReasons: [],
    tussCodes: [],
    t,
};

let wrapper;

function mountPage(overrides = {}) {
    wrapper = mount(BillingIndex, {
        props: { ...baseProps, ...overrides },
        global: {
            stubs: {
                teleport: true,
                // Sobrescreve o stub global de <Link> (sem href) de tests/JavaScript/setup.js.
                Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
            },
        },
        attachTo: document.body,
    });

    return wrapper;
}

function withFilters(extra) {
    return { filters: { ...baseProps.filters, ...extra } };
}

function lastVisit() {
    const calls = router.get.mock.calls;

    return calls[calls.length - 1];
}

beforeEach(() => resetInertiaMock());
afterEach(() => {
    wrapper?.unmount();
    delete window.axios;
});

describe('Billing/Index — cabeçalho, KPIs e fluxo', () => {
    it('header traz Novo lote, Importar retorno e o link da Conciliação de glosas', async () => {
        const w = mountPage();

        expect(w.find('[data-test="glosas-link"]').attributes('href')).toBe('/financial/tiss/glosas');
        expect(w.find('[data-test="open-import"]').text()).toContain(t.btn_import_return);

        await w.find('[data-test="header-new-batch"]').trigger('click');

        expect(w.text()).toContain(t.batch_title);
    });

    it('mostra os 5 KPIs com valores do servidor formatados e a definição de cada um', () => {
        const w = mountPage();

        expect(w.find('[data-test="billing-kpis"]').attributes('aria-label')).toBe(t.kpis_label);
        expect(w.find('[data-test="kpi-to-bill"]').text()).toBe('12 atendimento(s)');
        expect(norm(w.find('[data-test="billing-kpis"]').text())).toContain(
            norm('≈ R$ 1.500,00 (10 de 12 com preço na tabela)'),
        );
        expect(norm(w.find('[data-test="kpi-open"]').text())).toBe(norm(brl(450)));
        expect(norm(w.find('[data-test="kpi-received"]').text())).toBe(norm(brl(980.5)));
        expect(norm(w.find('[data-test="kpi-denied"]').text())).toBe(norm(brl(120)));
        expect(w.find('[data-test="kpi-tiss-pending"]').text()).toBe('4');
        // Definição do número: dica (title) + texto para leitor de tela.
        expect(w.find('[data-test="kpi-toggle-open"]').attributes('title')).toBe(t.kpi_open_hint);
        expect(w.find('[data-test="kpi-toggle-open"]').text()).toContain(t.kpi_open_hint);
    });

    it('"Glosado" é link para a Conciliação de glosas', () => {
        const w = mountPage();

        expect(w.find('[data-test="kpi-link-denied"]').attributes('href')).toBe('/financial/tiss/glosas');
    });

    it('"Em aberto" e "Pendências TISS" são botões de filtro (aria-pressed) que abrem a aba Guias', async () => {
        const w = mountPage();

        const open = w.find('[data-test="kpi-toggle-open"]');
        expect(open.element.tagName).toBe('BUTTON');
        expect(open.attributes('aria-pressed')).toBe('false');

        await w.find('[data-test="kpi-toggle-tiss-pending"]').trigger('click');

        const [url, params, options] = lastVisit();
        expect(url).toBe('/_routes/panel.financial.billing.index');
        expect(params).toEqual({ from: '2026-09-01', to: '2026-09-26', claim_status: 'tiss_pending', tab: 'claims' });
        expect(options).toMatchObject({ preserveState: true, preserveScroll: true, replace: true });
        expect(w.find('[data-test="tab-claims"]').attributes('aria-selected')).toBe('true');
    });

    it('KPI ativo fica pressionado e o segundo clique tira o filtro', async () => {
        const w = mountPage(withFilters({ claim_status: 'submitted', tab: 'claims' }));

        const open = w.find('[data-test="kpi-toggle-open"]');
        expect(open.attributes('aria-pressed')).toBe('true');

        await open.trigger('click');

        expect(lastVisit()[1]).toEqual({ from: '2026-09-01', to: '2026-09-26', tab: 'claims' });
    });

    it('explica o fluxo numa lista ordenada acessível', () => {
        const w = mountPage();

        const list = w.find('[data-test="billing-flow"] ol');
        expect(list.attributes('aria-label')).toBe(t.flow_label);
        const steps = list.findAll('li');
        expect(steps).toHaveLength(5);
        expect(steps[0].text()).toContain('Atendimento');
        expect(steps[4].text()).toContain('Recebimento/Glosa');
    });
});

describe('Billing/Index — abas na URL', () => {
    it('abre na aba que veio em filters.tab, com tabs acessíveis', () => {
        const w = mountPage(withFilters({ tab: 'claims' }));

        const tab = w.find('[data-test="tab-claims"]');
        expect(tab.attributes('role')).toBe('tab');
        expect(tab.attributes('aria-selected')).toBe('true');
        expect(tab.attributes('aria-controls')).toBe('billing-panel-claims');
        expect(w.find('[data-test="tab-eligible"]').attributes('tabindex')).toBe('-1');
        expect(w.find('#billing-panel-claims').attributes('role')).toBe('tabpanel');
        expect(w.find('#billing-panel-claims').isVisible()).toBe(true);
        expect(w.find('#billing-panel-eligible').isVisible()).toBe(false);
        expect(tab.text()).toContain('1');
    });

    it('trocar de aba grava ?tab= na URL sem ir ao servidor e mantém o flash', async () => {
        const w = mountPage();

        await w.find('[data-test="tab-batches"]').trigger('click');

        expect(router.replace).toHaveBeenCalledTimes(1);
        const visit = router.replace.mock.calls[0][0];
        expect(visit.url).toContain('tab=batches');
        expect(visit.preserveState).toBe(true);
        expect(visit.flash({ success: 'ok' })).toEqual({ success: 'ok' });
        expect(visit).not.toHaveProperty('props');
        expect(router.get).not.toHaveBeenCalled();
        expect(w.find('[data-test="tab-batches"]').attributes('aria-selected')).toBe('true');
    });

    it('setas do teclado navegam entre as abas', async () => {
        const w = mountPage();

        await w.find('[data-test="tab-eligible"]').trigger('keydown', { key: 'ArrowRight' });
        expect(w.find('[data-test="tab-claims"]').attributes('aria-selected')).toBe('true');

        await w.find('[data-test="tab-claims"]').trigger('keydown', { key: 'ArrowLeft' });
        expect(w.find('[data-test="tab-eligible"]').attributes('aria-selected')).toBe('true');
    });
});

describe('Billing/Index — filtros (aplicação automática)', () => {
    it('convênio e status aplicam na hora, mantendo período e aba na URL', async () => {
        const w = mountPage(withFilters({ tab: 'claims' }));

        await w.find('#billing-filter-covenant').setValue('cov-1');

        expect(router.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()[1]).toEqual({ from: '2026-09-01', to: '2026-09-26', covenant_id: 'cov-1', tab: 'claims' });

        await w.find('#billing-filter-status').setValue('submitted');

        expect(router.get).toHaveBeenCalledTimes(2);
        expect(lastVisit()[1]).toEqual({
            from: '2026-09-01',
            to: '2026-09-26',
            claim_status: 'submitted',
            tab: 'claims',
        });
        expect(lastVisit()[2]).toMatchObject({ preserveState: true, preserveScroll: true, replace: true });
    });

    it('período (PeriodFilter) aplica pelo atalho e o status só aparece na aba Guias', async () => {
        const w = mountPage();

        expect(w.find('#billing-filter-status').exists()).toBe(false);

        await w.find('[data-test="period-preset"]').setValue('last_month');

        expect(lastVisit()[1]).toEqual({ from: '2026-08-01', to: '2026-08-31', tab: 'eligible' });
    });

    it('limpar filtros mantém só a aba', async () => {
        const w = mountPage(withFilters({ covenant_id: 'cov-1' }));

        await w.find('[data-test="clear-filters"]').trigger('click');

        expect(lastVisit()[1]).toEqual({ tab: 'eligible' });
    });

    it('mostra "atualizando" (aria-live) e marca os painéis como ocupados durante a visita', async () => {
        const w = mountPage();

        await w.find('#billing-filter-covenant').setValue('cov-1');
        lastVisit()[2].onStart();
        await nextTick();

        expect(w.find('[data-test="filtering"]').attributes('role')).toBe('status');
        expect(w.find('[data-test="filtering"]').text()).toContain(t.filtering);
        expect(w.find('#billing-panel-eligible').attributes('aria-busy')).toBe('true');

        lastVisit()[2].onFinish();
        await nextTick();
        expect(w.find('[data-test="filtering"]').text()).toBe('');
    });

    it('reflete os filtros normalizados que o servidor devolve', async () => {
        const w = mountPage(withFilters({ tab: 'claims' }));

        await w.setProps({
            filters: {
                from: '2026-08-01',
                to: '2026-08-31',
                covenant_id: 'cov-1',
                claim_status: 'draft',
                batch_id: null,
                batch_code: null,
                tab: 'claims',
            },
        });

        expect(w.find('[data-test="period-from"]').element.value).toBe('2026-08-01');
        expect(w.find('[data-test="period-to"]').element.value).toBe('2026-08-31');
        expect(w.find('#billing-filter-covenant').element.value).toBe('cov-1');
        expect(w.find('#billing-filter-status').element.value).toBe('draft');
    });

    // Fase 4 (paginação): a seleção atravessa páginas/busca/ordem; período ou
    // convênio novos continuam descartando o que saiu da lista.
    it('seleção atravessa as páginas e vai para o lote; período/convênio novo descarta o que saiu da lista', async () => {
        const w = mountPage();

        await w.findAll('[data-test="eligible-row"] input')[0].trigger('change');
        await w.findAll('[data-test="eligible-row"] input')[1].trigger('change');
        expect(w.find('[data-test="new-batch"]').text()).toContain('2 selecionado(s)');

        // Página 2 (mesmos filtros): s1 e s2 continuam marcados.
        await w.setProps({
            eligibleSchedules: paginate(
                [schedule({ id: 's3', patient_name: 'Bia' })],
                { current_page: 2, last_page: 2 },
                'eligible_page',
            ),
        });
        expect(w.find('[data-test="new-batch"]').text()).toContain('2 selecionado(s)');

        // O modal de lote recebe os marcados de outras páginas junto com a página atual.
        await w.find('[data-test="header-new-batch"]').trigger('click');
        expect(w.text()).toContain('1 atendimento(s) marcado(s) deste convênio entrarão no lote.');
        expect(w.text()).toContain('1 marcado(s) de outro convênio ficarão de fora.');

        // Convênio novo: só fica o marcado que ainda está na lista.
        await w.setProps({
            filters: { ...baseProps.filters, covenant_id: 'cov-2' },
            eligibleSchedules: paginate([schedule({ id: 's2', covenant_id: 'cov-2' })], {}, 'eligible_page'),
        });

        expect(w.find('[data-test="new-batch"]').text()).toContain('1 selecionado(s)');
    });
});

describe('Billing/Index — busca, ordenação e paginação por aba', () => {
    afterEach(() => vi.useRealTimers());

    it('busca com debounce: leva a busca da aba, zera a página dela e mantém a das outras', async () => {
        vi.useFakeTimers();
        const w = mountPage({
            ...withFilters({ tab: 'claims' }),
            batches: paginate([batch()], { current_page: 3, last_page: 4 }, 'batches_page'),
            claims: paginate([claim()], { current_page: 2, last_page: 2 }, 'claims_page'),
        });

        await w.find('[data-test="claims-search"] input').setValue('GUI-0');
        await w.find('[data-test="claims-search"] input').setValue('GUI-00');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);

        expect(router.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()[1]).toEqual({
            from: '2026-09-01',
            to: '2026-09-26',
            tab: 'claims',
            claims_search: 'GUI-00',
            batches_page: 3,
        });
    });

    it('ordenar manda a ordem da aba (só quando difere do padrão) e zera a página dela', async () => {
        const w = mountPage(withFilters({ tab: 'claims' }));

        const patient = w.findAll('#billing-panel-claims thead th[aria-sort] button')[2];
        await patient.trigger('click');

        expect(lastVisit()[1]).toEqual({
            from: '2026-09-01',
            to: '2026-09-26',
            tab: 'claims',
            claims_sort: 'patient',
            claims_direction: 'asc',
        });

        // Voltar ao padrão (Guia = criação, desc) tira a ordem da URL.
        await w.setProps({ lists: lists({ claims: { sort: 'created', direction: 'asc' } }) });
        await w.findAll('#billing-panel-claims thead th[aria-sort] button')[0].trigger('click');

        expect(lastVisit()[1]).toEqual({ from: '2026-09-01', to: '2026-09-26', tab: 'claims' });
    });

    it('filtro global mantém busca/ordem das abas e volta todas para a 1ª página', async () => {
        const w = mountPage({
            ...withFilters({ tab: 'batches' }),
            lists: lists({ batches: { search: 'LOT-7', sort: 'total', direction: 'asc' } }),
            batches: paginate([batch()], { current_page: 2, last_page: 2 }, 'batches_page'),
        });

        await w.find('#billing-filter-covenant').setValue('cov-1');

        expect(lastVisit()[1]).toEqual({
            from: '2026-09-01',
            to: '2026-09-26',
            covenant_id: 'cov-1',
            tab: 'batches',
            batches_search: 'LOT-7',
            batches_sort: 'total',
            batches_direction: 'asc',
        });
    });

    it('limpar filtros apaga também as buscas digitadas', async () => {
        const w = mountPage({ ...withFilters({ tab: 'claims' }), lists: lists({ claims: { search: 'maria' } }) });

        expect(w.find('[data-test="claims-search"] input').element.value).toBe('maria');

        await w.find('[data-test="clear-filters"]').trigger('click');

        expect(lastVisit()[1]).toEqual({ tab: 'claims' });
        expect(w.find('[data-test="claims-search"] input').element.value).toBe('');
    });

    it('contagem das abas vem do total do paginator', () => {
        const w = mountPage({ totals: {}, claims: paginate([claim()], { total: 321, last_page: 7 }, 'claims_page') });

        expect(w.find('[data-test="tab-claims"]').text()).toContain('321');
    });
});

describe('Billing/Index — lote clicável', () => {
    it('o código do lote na aba Guias abre a aba Lotes filtrada por ele', async () => {
        const w = mountPage(withFilters({ tab: 'claims' }));

        const link = w.find('[data-test="claim-batch"]');
        expect(link.attributes('aria-label')).toBe('Ver o lote LOT-0001 na aba Lotes');

        await link.trigger('click');

        expect(lastVisit()[1]).toEqual({ from: '2026-09-01', to: '2026-09-26', batch_id: 'b1', tab: 'batches' });
        expect(w.find('[data-test="tab-batches"]').attributes('aria-selected')).toBe('true');
    });

    it('"Ver guias" no lote filtra a aba Guias; o chip do lote remove o filtro', async () => {
        const w = mountPage(withFilters({ tab: 'batches', batch_id: 'b1', batch_code: 'LOT-0007' }));

        const chip = w.find('[data-test="batch-chip"]');
        expect(chip.text()).toContain('Lote LOT-0007');

        await w.find('[data-test="view-claims"]').trigger('click');
        expect(lastVisit()[1]).toEqual({ from: '2026-09-01', to: '2026-09-26', batch_id: 'b1', tab: 'claims' });

        const clear = w.find('[data-test="batch-chip-clear"]');
        expect(clear.attributes('aria-label')).toBe('Remover o filtro do lote LOT-0007');
        await clear.trigger('click');
        expect(lastVisit()[1]).toEqual({ from: '2026-09-01', to: '2026-09-26', tab: 'claims' });
    });
});

describe('Billing/Index — enviar lote com confirmação', () => {
    it('não envia direto: abre o resumo e só posta ao confirmar', async () => {
        const w = mountPage(withFilters({ tab: 'batches' }));

        await w.find('[data-test="submit-batch"]').trigger('click');

        expect(router.post).not.toHaveBeenCalled();
        expect(w.find('[data-test="summary-code"]').text()).toBe('LOT-0007');

        await w.find('[data-test="confirm-submit"]').trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, data, options] = router.post.mock.calls[0];
        expect(url).toBe('/batches/b1/submit');
        expect(data).toEqual({});
        expect(options).toMatchObject({ preserveState: true, preserveScroll: true });
    });

    it('trava o botão durante o envio, mostra o erro do servidor e mantém o modal aberto', async () => {
        const w = mountPage(withFilters({ tab: 'batches' }));

        await w.find('[data-test="submit-batch"]').trigger('click');
        await w.find('[data-test="confirm-submit"]').trigger('click');

        const options = router.post.mock.calls[0][2];
        options.onStart();
        await nextTick();

        expect(w.find('[data-test="confirm-submit"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="submit-batch"]').attributes('disabled')).toBeDefined();

        options.onError({ batch: 'Falha no envio TISS: operadora indisponível' });
        options.onFinish();
        await nextTick();

        expect(w.find('[data-test="submit-error"]').text()).toBe('Falha no envio TISS: operadora indisponível');
        expect(w.find('[data-test="confirm-submit"]').attributes('disabled')).toBeUndefined();

        options.onSuccess();
        await nextTick();
        expect(w.find('[data-test="submit-summary"]').exists()).toBe(false);
    });
});

describe('Billing/Index — cancelar guia/lote (motivo obrigatório)', () => {
    const cancellable = claim({
        id: 'c9',
        code: 'GUI-0009',
        allowed_actions: ['cancel'],
        cancel_url: '/claims/c9/cancel',
    });

    async function openClaimCancel(w) {
        await w.find('[data-test="claim-menu"] button').trigger('click');
        await w.find('[data-test="cancel-claim"]').trigger('click');
    }

    it('abre a confirmação com motivo (mín. 10) e só posta o motivo na rota de cancelar', async () => {
        const w = mountPage({ ...withFilters({ tab: 'claims' }), claims: paginate([cancellable], {}, 'claims_page') });

        await openClaimCancel(w);

        const dialog = w.find('[role="dialog"]');
        expect(dialog.text()).toContain('Cancelar a guia GUI-0009');
        expect(dialog.text()).toContain(t.cancel_claim_message);

        const confirm = dialog.findAll('.modal-footer button')[1];
        await dialog.find('textarea').setValue('curto');
        expect(confirm.attributes('disabled')).toBeDefined();

        await dialog.find('textarea').setValue('  Convênio errado no atendimento  ');
        expect(confirm.text()).toContain(t.cancel_claim_confirm);
        await confirm.trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, data, options] = router.post.mock.calls[0];
        expect(url).toBe('/claims/c9/cancel');
        expect(data).toEqual({ reason: 'Convênio errado no atendimento' });
        expect(options).toMatchObject({ preserveScroll: true, preserveState: true });
    });

    it('recusa do servidor (ex.: já cancelada) aparece no modal; sucesso fecha', async () => {
        const w = mountPage({ ...withFilters({ tab: 'claims' }), claims: paginate([cancellable], {}, 'claims_page') });

        await openClaimCancel(w);
        await w.find('[role="dialog"] textarea').setValue('Motivo com mais de dez');
        await w.findAll('[role="dialog"] .modal-footer button')[1].trigger('click');

        const options = router.post.mock.calls[0][2];
        options.onStart();
        options.onError({ status: 'A guia GUI-0009 já está cancelada.' });
        options.onFinish();
        await nextTick();

        expect(w.find('[role="dialog"] [role="alert"]').text()).toBe('A guia GUI-0009 já está cancelada.');

        options.onSuccess();
        await nextTick();
        expect(w.find('[role="dialog"]').exists()).toBe(false);
    });

    it('cancelar lote usa os textos do lote e a URL do lote', async () => {
        const w = mountPage({
            ...withFilters({ tab: 'batches' }),
            batches: paginate([batch({ allowed_actions: ['submit', 'cancel'] })], {}, 'batches_page'),
        });

        await w.find('[data-test="batch-menu"] button').trigger('click');
        await w.find('[data-test="cancel-batch"]').trigger('click');

        expect(w.find('[role="dialog"]').text()).toContain('Cancelar o lote LOT-0007');

        await w.find('[role="dialog"] textarea').setValue('Lote gerado em duplicidade');
        await w.findAll('[role="dialog"] .modal-footer button')[1].trigger('click');

        expect(router.post.mock.calls[0][0]).toBe('/batches/b1/cancel');
        expect(router.post.mock.calls[0][1]).toEqual({ reason: 'Lote gerado em duplicidade' });
    });
});

describe('Billing/Index — corrigir pendência', () => {
    const pending = claim({
        id: 'c5',
        code: 'GUI-0005',
        guide_number: 'GUI-202609-000005',
        has_pending_guide: true,
        allowed_actions: ['fix_pending', 'cancel'],
        fix_pending: {
            url: '/claims/c5/fix-pending',
            clinical_indication: '',
            beneficiary_card_number: '123',
            authorization_number: null,
        },
    });

    it('abre com os dados atuais da guia, posta a correção e mostra o resultado; a lista recarrega', async () => {
        const post = vi.fn(() =>
            Promise.resolve({
                data: {
                    message: 'Guia GUI-0005 corrigida e incluída no lote LOT-0001.',
                    attached: true,
                    validation: {
                        passes: true,
                        errors: [],
                        warnings: [{ message: 'Lateralidade não informada.' }],
                        summary: 'Guia possui avisos.',
                    },
                },
            }),
        );
        window.axios = { post };

        const w = mountPage({ ...withFilters({ tab: 'claims' }), claims: paginate([pending], {}, 'claims_page') });

        await w.find('[data-test="claim-menu"] button').trigger('click');
        await w.find('[data-test="fix-pending"]').trigger('click');

        expect(w.find('[data-test="fix-summary"]').text()).toContain('GUI-202609-000005');
        expect(w.find('[data-test="fix-card"]').element.value).toBe('123');

        await w.find('[data-test="fix-auth"]').setValue('AUT-77');
        await w.find('[data-test="fix-save"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/claims/c5/fix-pending', {
            clinical_indication: '',
            beneficiary_card_number: '123',
            authorization_number: 'AUT-77',
        });
        expect(w.find('[data-test="fix-message"]').text()).toBe('Guia GUI-0005 corrigida e incluída no lote LOT-0001.');
        expect(w.find('[data-test="prevalidation-warnings"]').text()).toContain('Lateralidade não informada.');
        expect(router.reload).toHaveBeenCalledWith({
            only: ['claims', 'batches', 'kpis', 'totals'],
            preserveScroll: true,
        });
    });
});

describe('Billing/Index — avisos', () => {
    it('mostra erro visível quando a verificação de pendências falha', async () => {
        window.axios = { get: vi.fn(() => Promise.reject(new Error('500'))) };
        const w = mountPage(withFilters({ tab: 'claims' }));

        await w.find('[data-test="check-pending"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-test="action-error"]').text()).toContain(t.pending_check_failed);
    });

    it('depois de glosar, o modal mostra o próximo passo com "Abrir conciliação" já filtrada pela guia', async () => {
        const w = mountPage(withFilters({ tab: 'claims' }));

        await w.find('[data-test="deny"]').trigger('click');
        await w.find('[data-test="confirm-deny"]').trigger('click');

        const denyForm = forms.find((f) => f.lastPost?.url === '/claims/c1/denied');
        expect(denyForm.lastPost.data.glosa_amount).toBe(200);

        denyForm.lastPost.options.onSuccess();
        await nextTick();

        expect(w.find('[data-test="deny-done"]').text()).toContain('Glosa registrada na guia GUI-0001');
        expect(w.find('[data-test="open-conciliation"]').attributes('href')).toBe(
            '/financial/tiss/glosas?search=GUI-0001',
        );

        await w.find('[data-test="deny-close"]').trigger('click');
        expect(w.find('[data-test="deny-done"]').exists()).toBe(false);
    });
});
