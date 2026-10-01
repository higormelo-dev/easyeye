import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import GlosasIndex from '@/Pages/Panel/Financial/Tiss/GlosasIndex.vue';

/**
 * Conciliação de Glosas como fila de trabalho: abas (Pendentes de qualquer
 * data / Resolvidas / Todas), KPIs que filtram (aria-pressed), busca com
 * debounce, detalhe por recarga parcial (only: ['glosaDetail']), próxima ação
 * como botão + demais no menu, e os modais de recurso (validação e 409 no
 * servidor).
 */
const inertia = vi.hoisted(() => ({ pageProps: null, forms: [] }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.pageProps = reactive({ locale: 'pt_BR', flash: {}, errors: {} });

    return {
        usePage: () => ({ props: inertia.pageProps }),
        router: { get: vi.fn(), post: vi.fn(), reload: vi.fn(), replace: vi.fn() },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        useForm: (initial) => {
            const form = reactive({
                ...initial,
                errors: {},
                processing: false,
                transformer: null,
                reset() {
                    Object.assign(form, initial);
                },
                clearErrors() {
                    form.errors = {};
                },
                setError(key, message) {
                    form.errors = { ...form.errors, [key]: message };
                },
                transform(callback) {
                    form.transformer = callback;
                    return form;
                },
                post: vi.fn(),
            });
            inertia.forms.push(form);
            return form;
        },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'subtitle', 'total', 'totalLabel'],
        template:
            '<div class="page-header">{{ title }} <span class="total">{{ totalLabel }} {{ total }}</span><slot name="actions" /></div>',
    },
}));
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open', 'size'],
        emits: ['close'],
        template: `<div v-if="open" class="modal-stub">
            <div class="m-header"><slot name="header" /></div>
            <div class="m-body"><slot /></div>
            <div class="m-footer"><slot name="footer" /></div>
            <button type="button" class="stub-close" @click="$emit('close')"></button>
        </div>`,
    },
}));

const t = {
    title: 'Conciliação de Glosas',
    subtitle: 'Prazos',
    total_label: 'Pendentes:',
    btn_import_return: 'Importar retorno TISS',
    filters_label: 'Filtros das glosas',
    search_placeholder: 'Buscar nº da guia, código ou motivo',
    search_clear: 'Limpar busca',
    filter_status: 'Status',
    filter_status_all: 'Todos os status',
    filter_operator: 'Convênio/operadora',
    filter_operator_all: 'Todos os convênios',
    filter_clear: 'Limpar filtros',
    period_any: 'Pendentes de qualquer data',
    filtering: 'Filtrando...',
    period_hint: 'Período pela data de identificação.',
    tabs_label: 'Situação das glosas',
    tab_pending: 'Pendentes',
    tab_resolved: 'Resolvidas',
    tab_all: 'Todas',
    kpis_label: 'Indicadores das glosas',
    total_glosa: 'Total glosado',
    glosa_count: ':count glosa(s)',
    open_amount: 'Em aberto',
    open_hint: 'Glosas abertas, de qualquer data.',
    appealed: 'Recorridas',
    appealed_hint: 'Com recurso.',
    overdue_title: 'Vencidas',
    overdue_hint: 'Prazo vencido.',
    recovered: 'Recuperado',
    recovered_hint: 'Soma dos valores aceitos.',
    recovered_of_total: 'de :total glosados no período',
    due_soon_title: 'Vencendo em :days dias',
    due_soon_hint: 'Vence em até :days dias.',
    by_covenant: 'Resumo',
    col_covenant: 'Convênio',
    col_count: 'Glosas',
    col_total: 'Total',
    col_open: 'Aberto',
    list_title_pending: 'Fila de glosas pendentes',
    list_title_resolved: 'Glosas resolvidas no período',
    list_title_all: 'Glosas do período',
    empty: 'Nenhuma glosa no período.',
    empty_pending: 'Nenhuma glosa pendente.',
    empty_filtered: 'Nenhuma glosa com esses filtros.',
    empty_hint: 'Importe',
    showing_of: 'Mostrando :shown de :total. Refine os filtros para ver as demais.',
    col_date: 'Identificada em',
    col_guide: 'Guia',
    col_reason: 'Motivo',
    col_deadline: 'Prazo',
    col_status: 'Status',
    col_appeal: 'Recurso',
    col_value: 'Valor',
    col_actions: 'Ações',
    no_covenant: 'Sem convênio',
    claim_code_label: 'Faturamento :code',
    no_guide: 'Sem guia',
    deadline_overdue_days: 'Vencida há :days dias',
    deadline_overdue_one: 'Venceu ontem',
    deadline_overdue: 'Vencida',
    deadline_today: 'Vence hoje',
    deadline_tomorrow: 'Vence amanhã',
    deadline_in_days: 'Vence em :days dias',
    deadline_none: 'Sem prazo',
    appeal_response_until: 'Resposta até :date',
    appeals_previous: '+:count recurso(s) anterior(es)',
    no_appeal: 'Sem recurso',
    appeal_btn: 'Recorrer',
    submit_appeal_btn: 'Marcar como enviado',
    resolve_appeal_btn: 'Registrar decisão',
    details_btn: 'Detalhes',
    details_label: 'Ver detalhes e histórico da glosa :code',
    more_actions: 'Mais ações da glosa :code',
    processing: 'Processando...',
    close: 'Fechar',
    cancel_btn: 'Cancelar',
    action_error: 'Não foi possível concluir a ação.',
    detail_title: 'Glosa :code',
    detail_loading: 'Carregando os detalhes da glosa...',
    detail_missing: 'Glosa não encontrada ou sem acesso a ela.',
    detail_summary: 'Resumo',
    detail_status: 'Status',
    detail_identified: 'Identificada em',
    detail_deadline: 'Prazo para recorrer',
    detail_amount: 'Valor glosado',
    detail_recovered: 'Recuperado',
    detail_resolved_at: 'Resolvida em',
    detail_resolution_notes: 'Observações da decisão',
    detail_guide: 'Guia',
    guide_provider_number: 'Nº da guia (prestador)',
    guide_operator_number: 'Nº na operadora',
    guide_claim_code: 'Guia de faturamento',
    guide_attendance: 'Atendimento',
    guide_patient: 'Paciente',
    guide_total: 'Valor da guia',
    detail_reason: 'Motivo da glosa',
    detail_appeals: 'Recursos',
    no_appeals: 'Nenhum recurso aberto para esta glosa.',
    appeal_opened_at: 'Aberto em',
    appeal_submitted_at: 'Enviado em',
    appeal_response_deadline: 'Resposta até',
    appeal_requested: 'Solicitado',
    appeal_accepted: 'Aceito',
    appeal_reason: 'Justificativa',
    appeal_result_notes: 'Decisão da operadora',
    detail_timeline: 'Linha do tempo',
    timeline_identified: 'Glosa identificada',
    timeline_glosa: 'Glosa: :status',
    timeline_glosa_change: 'Glosa: :from → :to',
    timeline_appeal: 'Recurso :number: :status',
    timeline_appeal_change: 'Recurso :number: :from → :to',
    modal_glosa_label: 'Motivo',
    modal_value_label: 'Valor glosado',
    modal_guide_label: 'Guia',
    modal_covenant_label: 'Convênio',
    modal_deadline_label: 'Prazo',
    modal_appeal_label: 'Recurso',
    modal_requested: 'Valor solicitado',
    appeal_title: 'Recurso de Glosa',
    justification_label: 'Justificativa',
    justification_placeholder: 'Descreva',
    min_chars_audit_hint: 'Mínimo de :min caracteres.',
    char_counter: ':count/:max',
    appeal_number_hint: 'REC',
    submit_appeal: 'Abrir recurso',
    submit_confirm_title: 'Marcar recurso como enviado',
    submit_confirm_intro: 'Confirme o envio.',
    submit_confirm_consequence:
        'O sistema não envia o recurso eletronicamente. O prazo de resposta da operadora (:days dias) passa a contar.',
    submit_confirm_btn: 'Confirmar envio',
    resolve_title: 'Decisão',
    decision_label: 'Decisão',
    decision_accepted: 'Aceito',
    decision_rejected: 'Rejeitado',
    accepted_amount_label: 'Valor aceito',
    accepted_amount_help: 'Até :max',
    result_notes_label: 'Observações',
    resolve_submit_btn: 'Confirmar decisão',
    resolve_preview: 'A glosa ficará: :status',
    reason_required: 'Informe a justificativa.',
    reason_min: 'A justificativa precisa ter ao menos :min caracteres.',
    decision_required: 'Selecione a decisão.',
    accepted_amount_required: 'Informe o valor aceito.',
    accepted_amount_min: 'O valor aceito deve ser maior que zero.',
    accepted_amount_max: 'O valor aceito não pode ser maior que o valor glosado (:max).',
    accepted_amount_decimals: 'O valor aceito deve ter no máximo 2 casas decimais (centavos).',
    glosa_status: {
        open: 'Aberta',
        appealed: 'Recorrida',
        partial_reversed: 'Revertida parcialmente',
        reversed: 'Revertida',
        maintained: 'Mantida',
        cancelled: 'Cancelada',
    },
    import: {
        import_return_title: 'Importar retorno TISS',
        import_return_hint: 'Envie o XML.',
        import_return_covenant: 'Convênio',
        import_return_no_covenants: 'Nenhum convênio.',
        import_return_file: 'Arquivo XML',
        import_return_btn: 'Importar',
        select: 'Selecione...',
        btn_cancel: 'Cancelar',
        processing: 'Processando...',
    },
};

const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v);
const norm = (s) => s.replace(/\s+/g, ' ').trim();

function appeal(overrides = {}) {
    return {
        id: 'a1',
        appeal_number: 'REC-202609-00007',
        status: 'submitted',
        status_label: 'Enviado',
        status_color: 'info',
        can_be_submitted: false,
        can_be_resolved: true,
        requested_amount: 150,
        accepted_amount: 0,
        submitted_at: '2026-09-20T10:00:00-03:00',
        deadline: '2026-11-19',
        submit_url: '/appeals/a1/submit',
        resolve_url: '/appeals/a1/resolve',
        ...overrides,
    };
}

function glosa(overrides = {}) {
    return {
        id: 'g1',
        identified_at: '2026-09-10',
        deadline: '2026-09-29',
        resolved_at: null,
        operator_name: 'Unimed',
        guide_number: 'GUI-1',
        claim_code: 'GUI-0000000001',
        reason_code: '3099',
        reason_text: 'Procedimento não autorizado pela operadora conforme contrato',
        amount: 150,
        recovered_amount: 0,
        status: 'open',
        status_label: 'Aberta',
        status_color: 'danger',
        is_actionable: true,
        appeals_count: 0,
        appeal_url: '/glosas/g1/appeal',
        appeals: [],
        ...overrides,
    };
}

function detailFixture(overrides = {}) {
    return {
        ...glosa({
            status: 'appealed',
            status_label: 'Recorrida',
            status_color: 'warning',
            is_actionable: false,
            appeals: [
                appeal({
                    status: 'opened',
                    status_label: 'Aberto',
                    status_color: 'secondary',
                    can_be_submitted: true,
                    can_be_resolved: false,
                    submitted_at: null,
                    deadline: null,
                    created_at: '2026-09-12T10:00:00-03:00',
                    reason: 'Cobertura contratual válida.',
                    result_notes: null,
                }),
            ],
        }),
        resolution_notes: null,
        guide: {
            provider_number: 'GUI-202609-000001',
            operator_number: null,
            claim_code: 'GUI-0000000001',
            attendance_date: '2026-09-05',
            patient_name: 'Maria Souza',
            total_amount: 300,
        },
        timeline: [
            {
                id: 'h1',
                changed_at: '2026-09-12T10:00:00-03:00',
                context: 'glosa',
                appeal_number: null,
                previous_label: 'Aberta',
                current_status: 'appealed',
                current_label: 'Recorrida',
                reason: 'Recurso REC-202609-00007 aberto.',
            },
        ],
        ...overrides,
    };
}

const baseSummary = {
    open: 150,
    open_count: 1,
    appealed: 300,
    appealed_count: 2,
    overdue: 80,
    overdue_count: 1,
    due_soon: 150,
    due_soon_count: 1,
    due_soon_days: 5,
    total: 1800,
    count: 3,
    recovered: 500,
    appeal_response_days: 60,
};

const statusOptions = {
    pending: [
        { value: 'open', label: 'Aberta' },
        { value: 'appealed', label: 'Recorrida' },
    ],
    resolved: [
        { value: 'reversed', label: 'Revertida' },
        { value: 'recovered', label: 'Recuperadas (total ou parcial)' },
    ],
    all: [
        { value: 'open', label: 'Aberta' },
        { value: 'recovered', label: 'Recuperadas (total ou parcial)' },
    ],
};

const baseFilters = {
    tab: 'pending',
    from: '2026-09-01',
    to: '2026-09-30',
    status: null,
    operator_id: null,
    due: null,
    search: '',
};

/** Paginator do Laravel com as linhas da página (fase 4: a lista é paginada). */
function paginator(data, meta = {}) {
    const total = meta.total ?? data.length;
    const lastPage = meta.last_page ?? 1;
    const current = meta.current_page ?? 1;
    const url = (page) => `/panel/financial/tiss/glosas?page=${page}`;

    return {
        data,
        current_page: current,
        last_page: lastPage,
        per_page: 30,
        total,
        from: data.length ? (current - 1) * 30 + 1 : null,
        to: data.length ? (current - 1) * 30 + data.length : null,
        prev_page_url: current > 1 ? url(current - 1) : null,
        next_page_url: current < lastPage ? url(current + 1) : null,
        links: [
            { url: current > 1 ? url(current - 1) : null, label: '&laquo; Anterior', active: false },
            ...Array.from({ length: lastPage }, (_, i) => ({
                url: url(i + 1),
                label: String(i + 1),
                active: i + 1 === current,
            })),
            { url: current < lastPage ? url(current + 1) : null, label: 'Próxima &raquo;', active: false },
        ],
    };
}

let wrapper;

function mountPage(glosas = [glosa()], extra = {}) {
    inertia.forms.length = 0;
    wrapper = mount(GlosasIndex, {
        attachTo: document.body,
        global: { stubs: { teleport: true } },
        props: {
            breadcrumbs: [],
            filters: baseFilters,
            today: '2026-09-26',
            summary: baseSummary,
            tabCounts: { pending: 3, resolved: 4, all: 9 },
            glosas: paginator(glosas),
            byOperator: [{ name: 'Unimed', total: 1800, open: 150, count: 3 }],
            operators: [
                { id: 'op-1', name: 'Unimed' },
                { id: 'op-2', name: 'Bradesco' },
            ],
            statusOptions,
            glosaDetail: null,
            covenants: [{ id: 'cov-1', name: 'Unimed', has_tiss_operator: true }],
            importReturnUrl: '/billing/import-return',
            t,
            ...extra,
        },
    });
    return wrapper;
}

const appealForm = () => inertia.forms.find((f) => 'reason' in f);
const resolveForm = () => inertia.forms.find((f) => 'decision' in f);

function lastVisit() {
    const calls = vi.mocked(router.get).mock.calls;

    return calls[calls.length - 1];
}

beforeEach(() => {
    vi.mocked(router.get).mockClear();
    vi.mocked(router.post).mockClear();
    vi.mocked(router.reload).mockClear();
});
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
});

describe('GlosasIndex — KPIs como filtros', () => {
    it('mostra os 5 KPIs formatados pelo locale, com a definição de cada um', () => {
        const w = mountPage();

        const kpis = w.find('[data-test="glosa-kpis"]');
        expect(kpis.attributes('aria-label')).toBe(t.kpis_label);
        expect(norm(w.find('[data-test="kpi-open"]').text())).toBe(norm(brl(150)));
        expect(norm(w.find('[data-test="kpi-overdue"]').text())).toBe(norm(brl(80)));
        expect(norm(w.find('[data-test="kpi-due-soon"]').text())).toBe(norm(brl(150)));
        expect(norm(w.find('[data-test="kpi-recovered"]').text())).toBe(norm(brl(500)));
        expect(norm(kpis.text())).toContain(norm(`de ${brl(1800)} glosados no período`));
        expect(kpis.text()).toContain('Vencendo em 5 dias');
        expect(w.find('[data-test="kpi-toggle-overdue"]').attributes('title')).toBe(t.overdue_hint);
        expect(w.find('.total').text()).toBe('Pendentes: 3');
    });

    it('"Vencidas" filtra a fila (aria-pressed) e o segundo clique tira o filtro', async () => {
        const w = mountPage();

        const overdue = w.find('[data-test="kpi-toggle-overdue"]');
        expect(overdue.element.tagName).toBe('BUTTON');
        expect(overdue.attributes('aria-pressed')).toBe('false');

        await overdue.trigger('click');

        const [url, params, options] = lastVisit();
        expect(url).toBe('/_routes/panel.financial.tiss.glosas.index');
        expect(params).toEqual({ tab: 'pending', from: '2026-09-01', to: '2026-09-30', due: 'overdue' });
        expect(options).toMatchObject({ preserveState: true, preserveScroll: true, replace: true });

        await w.setProps({ filters: { ...baseFilters, due: 'overdue' } });
        expect(w.find('[data-test="kpi-toggle-overdue"]').attributes('aria-pressed')).toBe('true');

        await w.find('[data-test="kpi-toggle-overdue"]').trigger('click');
        expect(lastVisit()[1]).toEqual({ tab: 'pending', from: '2026-09-01', to: '2026-09-30' });
    });

    it('"Recuperado" abre as resolvidas recuperadas; "Em aberto" filtra o status', async () => {
        const w = mountPage();

        await w.find('[data-test="kpi-toggle-recovered"]').trigger('click');
        expect(lastVisit()[1]).toEqual({ tab: 'resolved', from: '2026-09-01', to: '2026-09-30', status: 'recovered' });

        await w.setProps({ filters: { ...baseFilters, tab: 'pending' } });
        await w.find('[data-test="kpi-toggle-open"]').trigger('click');
        expect(lastVisit()[1]).toEqual({ tab: 'pending', from: '2026-09-01', to: '2026-09-30', status: 'open' });
    });
});

describe('GlosasIndex — abas e filtros', () => {
    it('abas acessíveis com contagem; trocar de aba vai ao servidor e descarta status que não existe nela', async () => {
        const w = mountPage([glosa()], { filters: { ...baseFilters, status: 'open' } });

        const pending = w.find('[data-test="glosas-tab-pending"]');
        expect(pending.attributes('role')).toBe('tab');
        expect(pending.attributes('aria-selected')).toBe('true');
        expect(pending.attributes('aria-controls')).toBe('glosas-panel');
        expect(pending.text()).toContain('3');
        expect(w.find('#glosas-panel').attributes('aria-labelledby')).toBe('glosas-tab-pending');

        await w.find('[data-test="glosas-tab-resolved"]').trigger('click');

        expect(lastVisit()[1]).toEqual({ tab: 'resolved', from: '2026-09-01', to: '2026-09-30' });
        expect(w.find('[data-test="glosas-tab-resolved"]').attributes('aria-selected')).toBe('true');
    });

    it('setas do teclado trocam de aba', async () => {
        const w = mountPage();

        await w.find('[data-test="glosas-tab-pending"]').trigger('keydown', { key: 'ArrowRight' });

        expect(lastVisit()[1]).toMatchObject({ tab: 'resolved' });
    });

    it('busca aplica com debounce de 400 ms (termo literal na URL)', async () => {
        vi.useFakeTimers();
        const w = mountPage();

        await w.find('[data-test="glosa-search"] input').setValue('50%_x');
        vi.advanceTimersByTime(399);
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(lastVisit()[1]).toEqual({ tab: 'pending', from: '2026-09-01', to: '2026-09-30', search: '50%_x' });
    });

    it('status e convênio aplicam na hora; o status da aba vem do servidor', async () => {
        const w = mountPage([glosa()], { filters: { ...baseFilters, due: 'overdue' } });

        const status = w.find('#glosas-filter-status');
        expect(status.findAll('option').map((o) => o.text())).toEqual([t.filter_status_all, 'Aberta', 'Recorrida']);

        // Escolher um status tira o filtro de prazo (KPI) — os dois se contradiriam.
        await status.setValue('appealed');
        expect(lastVisit()[1]).toEqual({ tab: 'pending', from: '2026-09-01', to: '2026-09-30', status: 'appealed' });

        await w.find('#glosas-filter-operator').setValue('op-2');
        expect(lastVisit()[1]).toEqual({
            tab: 'pending',
            from: '2026-09-01',
            to: '2026-09-30',
            operator_id: 'op-2',
            due: 'overdue',
        });
    });

    it('Pendentes são de qualquer data (sem período); nas outras abas o período aplica na hora', async () => {
        const w = mountPage();

        expect(w.find('[data-test="period-any"]').text()).toContain(t.period_any);
        expect(w.find('[data-test="period-preset"]').exists()).toBe(false);

        await w.setProps({ filters: { ...baseFilters, tab: 'resolved' } });

        expect(w.find('[data-test="period-hint"]').text()).toBe(t.period_hint);
        await w.find('[data-test="period-preset"]').setValue('last_month');
        expect(lastVisit()[1]).toEqual({ tab: 'resolved', from: '2026-08-01', to: '2026-08-31' });
    });

    it('limpar filtros mantém só a aba e o resumo por convênio só aparece no histórico', async () => {
        const w = mountPage([glosa()], { filters: { ...baseFilters, search: 'x' } });

        expect(w.find('[data-test="by-operator"]').exists()).toBe(false);
        await w.find('[data-test="glosa-clear-filters"]').trigger('click');
        expect(lastVisit()[1]).toEqual({ tab: 'pending' });

        await w.setProps({ filters: { ...baseFilters, tab: 'all' } });
        expect(w.find('[data-test="by-operator"]').exists()).toBe(true);
    });

    // Fase 4: sem teto de 300 nem aviso "Mostrando X de N" — a lista é paginada.
    it('lista vazia explica se é filtro ou fila zerada; lista grande é paginada (sem aviso de corte)', async () => {
        const w = mountPage([]);

        expect(w.find('[data-test="glosas-empty"]').text()).toContain(t.empty_pending);

        await w.setProps({ filters: { ...baseFilters, search: 'nada' } });
        expect(w.find('[data-test="glosas-empty"]').text()).toContain(t.empty_filtered);

        await w.setProps({ glosas: paginator([glosa()], { total: 450, last_page: 15 }) });
        expect(w.find('[data-test="glosas-truncated"]').exists()).toBe(false);
        expect(w.find('[data-test="glosas-pagination"]').exists()).toBe(true);
    });
});

describe('GlosasIndex — linhas', () => {
    it('mostra o prazo como badge com ícone + texto (vence em N dias / vencida / hoje), não só cor', () => {
        const w = mountPage([
            glosa({ id: 'a', deadline: '2026-09-29' }),
            glosa({ id: 'b', deadline: '2026-09-20' }),
            glosa({ id: 'c', deadline: '2026-09-26' }),
            glosa({ id: 'd', deadline: '2026-09-25' }),
            glosa({ id: 'e', deadline: '2026-10-20' }),
        ]);

        const cells = w.findAll('[data-test="deadline-cell"]');
        expect(cells[0].text()).toContain('Vence em 3 dias');
        expect(cells[0].find('.badge').classes()).toContain('badge-soft-warning');
        expect(cells[0].find('.badge i').exists()).toBe(true);
        expect(cells[0].text()).toContain('29/09/2026');

        expect(cells[1].text()).toContain('Vencida há 6 dias');
        expect(cells[1].find('.badge').classes()).toContain('badge-soft-danger');

        expect(cells[2].text()).toContain('Vence hoje');
        expect(cells[3].text()).toContain('Venceu ontem');

        expect(cells[4].text()).toContain('Vence em 24 dias');
        expect(cells[4].find('.badge').classes()).toContain('badge-soft-info');
    });

    it('para glosa que não está em aberto mostra só a data do prazo (sem alerta)', () => {
        const w = mountPage([
            glosa({
                status: 'reversed',
                status_label: 'Revertida',
                status_color: 'success',
                is_actionable: false,
                deadline: '2026-09-20',
            }),
        ]);

        const cell = w.find('[data-test="deadline-cell"]');
        expect(cell.find('.badge').exists()).toBe(false);
        expect(cell.text()).toBe('20/09/2026');
    });

    it('motivo truncado com a descrição completa no tooltip; guia abre o detalhe (rótulo acessível)', () => {
        const w = mountPage();

        const reason = w.find('[data-test="reason-text"]');
        expect(reason.classes()).toContain('text-truncate');
        expect(reason.attributes('title')).toBe('Procedimento não autorizado pela operadora conforme contrato');

        const guide = w.find('[data-test="open-details"]');
        expect(guide.attributes('aria-label')).toBe('Ver detalhes e histórico da glosa GUI-1');
        expect(w.find('[data-test="guide-cell"]').text()).toContain('Faturamento GUI-0000000001');
    });

    it('mostra o nº REC, o status do recurso (badge soft) e o prazo de resposta da operadora', () => {
        const w = mountPage([
            glosa({
                status: 'appealed',
                status_label: 'Recorrida',
                status_color: 'warning',
                is_actionable: false,
                appeals: [
                    appeal({
                        id: 'old',
                        appeal_number: 'REC-202608-00001',
                        status: 'rejected',
                        status_label: 'Rejeitado',
                        status_color: 'danger',
                        can_be_resolved: false,
                    }),
                    appeal(),
                ],
            }),
        ]);

        const cell = w.find('[data-test="appeal-cell"]');
        expect(cell.text()).toContain('REC-202609-00007');
        expect(cell.find('.badge').classes()).toContain('badge-soft-info');
        expect(cell.text()).toContain('Resposta até 19/11/2026');
        expect(cell.text()).toContain('+1 recurso(s) anterior(es)');
    });

    it('usa badges soft (legíveis no escuro) e mapeia "light" para secondary', () => {
        const w = mountPage([
            glosa({ status: 'cancelled', status_label: 'Cancelada', status_color: 'light', is_actionable: false }),
        ]);

        const html = w.find('[data-test="glosa-row"]').html();
        expect(html).toContain('badge-soft-secondary');
        expect(html).not.toContain('text-dark');
        expect(html).not.toMatch(/\bbg-(warning|info|light|white)\b/);
    });

    it('próxima ação é o botão da linha; "Detalhes" fica no menu (ou é o botão, se não houver ação)', async () => {
        const w = mountPage([
            glosa({ id: 'open' }),
            glosa({
                id: 'opened',
                status: 'appealed',
                is_actionable: false,
                appeals: [appeal({ status: 'opened', can_be_submitted: true, can_be_resolved: false })],
            }),
            glosa({ id: 'sent', status: 'appealed', is_actionable: false, appeals: [appeal()] }),
            glosa({
                id: 'done',
                status: 'reversed',
                is_actionable: false,
                appeals: [appeal({ status: 'accepted', can_be_resolved: false })],
            }),
        ]);

        const [open, opened, sent, done] = w.findAll('[data-test="glosa-row"]');
        expect(open.find('[data-test="btn-appeal"]').text()).toBe(t.appeal_btn);
        expect(opened.find('[data-test="btn-submit"]').text()).toBe(t.submit_appeal_btn);
        expect(sent.find('[data-test="btn-resolve"]').text()).toBe(t.resolve_appeal_btn);
        expect(done.find('[data-test="btn-details"]').text()).toBe(t.details_btn);
        expect(done.find('[aria-haspopup="menu"]').exists()).toBe(false);

        const more = open.find('[aria-haspopup="menu"]');
        expect(more.attributes('aria-label')).toBe('Mais ações da glosa GUI-1');
        await more.trigger('click');
        await nextTick();
        await w.find('[data-test="menu-details"]').trigger('click');

        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['glosaDetail'], data: { detail: 'open' }, preserveUrl: true }),
        );
    });
});

describe('GlosasIndex — painel de detalhes (recarga parcial)', () => {
    it('abre carregando e mostra guia, motivo completo, recursos e linha do tempo', async () => {
        const w = mountPage();

        await w.find('[data-test="open-details"]').trigger('click');

        const reload = vi.mocked(router.reload).mock.calls[0][0];
        expect(reload).toMatchObject({ only: ['glosaDetail'], data: { detail: 'g1' }, preserveUrl: true });
        expect(w.find('[role="status"] .visually-hidden').text()).toBe(t.detail_loading);

        await w.setProps({ glosaDetail: detailFixture() });
        reload.onFinish();
        await nextTick();

        expect(w.find('[data-test="detail-title"]').text()).toContain('Glosa GUI-1');
        const guide = w.find('[data-test="detail-guide"]').text();
        expect(guide).toContain('GUI-202609-000001');
        expect(guide).toContain('Maria Souza');
        expect(w.find('[data-test="detail-reason"]').text()).toContain(
            'Procedimento não autorizado pela operadora conforme contrato',
        );
        expect(w.find('[data-test="detail-appeal"]').text()).toContain('REC-202609-00007');
        expect(w.find('[data-test="detail-appeal"]').text()).toContain('Cobertura contratual válida.');

        const events = w.findAll('[data-test="timeline-item"]');
        expect(events).toHaveLength(2);
        expect(events[0].text()).toContain('Glosa identificada');
        expect(events[1].text()).toContain('Glosa: Aberta → Recorrida');
        expect(events[1].find('time').attributes('datetime')).toBe('2026-09-12T10:00:00-03:00');
    });

    it('glosa de outra clínica / inexistente: avisa sem mostrar dado', async () => {
        const w = mountPage();

        await w.find('[data-test="open-details"]').trigger('click');
        await w.setProps({ glosaDetail: { missing: true } });

        expect(w.find('[data-test="detail-missing"]').attributes('role')).toBe('alert');
        expect(w.find('[data-test="detail-guide"]').exists()).toBe(false);
    });

    it('link direto (?detail=) já abre o painel com o detalhe do servidor', () => {
        const w = mountPage([glosa()], { glosaDetail: detailFixture() });

        expect(w.find('[data-test="glosa-detail"]').exists()).toBe(true);
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('ação no rodapé abre o modal; Esc fecha o modal e depois o painel; sucesso recarrega o detalhe', async () => {
        const w = mountPage([glosa()], { glosaDetail: detailFixture() });

        await w.find('[data-test="detail-action"]').trigger('click');
        expect(w.find('[data-test="submit-confirm"]').exists()).toBe(true);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(w.find('.modal-stub').exists()).toBe(false);
        expect(w.find('[data-test="glosa-detail"]').exists()).toBe(true);

        await w.find('[data-test="detail-action"]').trigger('click');
        await w.find('[data-test="confirm-submit"]').trigger('click');
        vi.mocked(router.post).mock.calls[0][2].onSuccess();
        await nextTick();

        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['glosaDetail'], data: { detail: 'g1' } }),
        );

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(w.find('[data-test="glosa-detail"]').exists()).toBe(false);
    });
});

describe('GlosasIndex — recurso', () => {
    it('"Marcar como enviado" pede confirmação explicando que não é envio eletrônico e que o prazo começa', async () => {
        const g = glosa({
            status: 'appealed',
            is_actionable: false,
            appeals: [
                appeal({
                    status: 'opened',
                    status_label: 'Aberto',
                    can_be_submitted: true,
                    can_be_resolved: false,
                    deadline: null,
                }),
            ],
        });
        const w = mountPage([g]);

        await w.find('[data-test="btn-submit"]').trigger('click');

        expect(router.post).not.toHaveBeenCalled();
        const summary = w.find('[data-test="modal-summary"]');
        expect(summary.text()).toContain('REC-202609-00007');
        expect(summary.text()).toContain('Unimed');
        expect(norm(summary.text())).toContain(norm(brl(150)));
        const confirm = w.find('[data-test="submit-confirm"]').text();
        expect(confirm).toContain('não envia o recurso eletronicamente');
        expect(confirm).toContain('(60 dias)');

        await w.find('[data-test="confirm-submit"]').trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            '/appeals/a1/submit',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('mostra o erro do servidor dentro do modal quando a ação falha', async () => {
        const g = glosa({
            status: 'appealed',
            is_actionable: false,
            appeals: [appeal({ status: 'opened', can_be_submitted: true, can_be_resolved: false })],
        });
        const w = mountPage([g]);

        await w.find('[data-test="btn-submit"]').trigger('click');
        await w.find('[data-test="confirm-submit"]').trigger('click');

        const options = vi.mocked(router.post).mock.calls[0][2];
        options.onError({ appeal: 'Este recurso não pode ser enviado no estado atual.' });
        await nextTick();

        expect(w.find('[data-test="modal-error"]').text()).toContain(
            'Este recurso não pode ser enviado no estado atual.',
        );
        expect(w.find('.modal-stub').exists()).toBe(true);
    });

    it('recorrer: justificativa curta mostra o erro e não posta; válida posta na URL do servidor', async () => {
        const w = mountPage();

        await w.find('[data-test="btn-appeal"]').trigger('click');
        appealForm().reason = 'curto';
        await nextTick();
        await w.find('#glosa-appeal-form').trigger('submit');

        expect(appealForm().post).not.toHaveBeenCalled();
        expect(w.find('[data-test="reason-error"]').text()).toBe('A justificativa precisa ter ao menos 10 caracteres.');
        expect(w.find('#glosa-appeal-reason').attributes('aria-describedby')).toContain('glosa-appeal-reason-error');

        appealForm().reason = 'Procedimento coberto pelo contrato vigente.';
        await nextTick();
        expect(w.find('[data-test="reason-counter"]').text()).toBe('43/1000');

        await w.find('#glosa-appeal-form').trigger('submit');
        expect(appealForm().post).toHaveBeenCalledWith(
            '/glosas/g1/appeal',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('decisão: "Aceito" pré-preenche o valor solicitado, mostra a prévia e bloqueia valor acima do glosado', async () => {
        const g = glosa({ status: 'appealed', is_actionable: false, appeals: [appeal()] });
        const w = mountPage([g]);

        await w.find('[data-test="btn-resolve"]').trigger('click');
        await w.find('#glosa-decision-accepted').setValue(true);
        await nextTick();

        expect(resolveForm().accepted_amount).toBe(150);
        expect(w.find('#glosa-accepted-amount').element.value).toBe('150,00');
        expect(w.find('[data-test="resolve-preview"]').text()).toContain('A glosa ficará: Revertida');

        await w.find('#glosa-accepted-amount').setValue('60,00');
        expect(resolveForm().accepted_amount).toBe(60);
        expect(w.find('[data-test="resolve-preview"]').text()).toContain('Revertida parcialmente');

        resolveForm().accepted_amount = 200;
        await nextTick();
        await w.find('#glosa-resolve-form').trigger('submit');

        expect(resolveForm().post).not.toHaveBeenCalled();
        expect(norm(w.find('[data-test="accepted-error"]').text())).toBe(
            norm(`O valor aceito não pode ser maior que o valor glosado (${brl(150)}).`),
        );
    });

    // numeric(14,2): 199.995 viraria 200,00 no banco com a glosa "Revertida parcialmente".
    it('decisão: valor aceito com mais de 2 casas decimais não posta nem mostra prévia', async () => {
        const w = mountPage([glosa({ status: 'appealed', is_actionable: false, appeals: [appeal()] })]);

        await w.find('[data-test="btn-resolve"]').trigger('click');
        await w.find('#glosa-decision-accepted').setValue(true);
        resolveForm().accepted_amount = 149.995;
        await nextTick();

        expect(w.find('[data-test="resolve-preview"]').exists()).toBe(false);

        await w.find('#glosa-resolve-form').trigger('submit');

        expect(resolveForm().post).not.toHaveBeenCalled();
        expect(w.find('[data-test="accepted-error"]').text()).toBe(
            'O valor aceito deve ter no máximo 2 casas decimais (centavos).',
        );

        resolveForm().accepted_amount = 149.99;
        await nextTick();
        await w.find('#glosa-resolve-form').trigger('submit');
        expect(resolveForm().post).toHaveBeenCalledWith('/appeals/a1/resolve', expect.any(Object));
    });

    it('decisão: "Aceito" sem valor não posta', async () => {
        const w = mountPage([glosa({ status: 'appealed', is_actionable: false, appeals: [appeal()] })]);

        await w.find('[data-test="btn-resolve"]').trigger('click');
        await w.find('#glosa-decision-accepted').setValue(true);
        resolveForm().accepted_amount = null;
        await nextTick();
        await w.find('#glosa-resolve-form').trigger('submit');

        expect(resolveForm().post).not.toHaveBeenCalled();
        expect(w.find('[data-test="accepted-error"]').text()).toBe('Informe o valor aceito.');
    });

    it('decisão: "Rejeitado" não envia o valor aceito que sobrou no formulário', async () => {
        const w = mountPage([glosa({ status: 'appealed', is_actionable: false, appeals: [appeal()] })]);

        await w.find('[data-test="btn-resolve"]').trigger('click');
        await w.find('#glosa-decision-accepted').setValue(true);
        await nextTick();
        await w.find('#glosa-decision-rejected').setValue(true);
        await nextTick();
        await w.find('#glosa-resolve-form').trigger('submit');

        expect(resolveForm().post).toHaveBeenCalledWith('/appeals/a1/resolve', expect.any(Object));
        const payload = resolveForm().transformer({ decision: 'rejected', accepted_amount: 90, result_notes: 'x' });
        expect(payload).toEqual({ decision: 'rejected', result_notes: 'x' });
        expect(w.find('[data-test="resolve-preview"]').text()).toContain('Mantida');
    });

    it('Esc fecha o modal', async () => {
        const w = mountPage();

        await w.find('[data-test="btn-appeal"]').trigger('click');
        expect(w.find('.modal-stub').exists()).toBe(true);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();

        expect(w.find('.modal-stub').exists()).toBe(false);
    });
});

describe('GlosasIndex — importar retorno', () => {
    it('botão do cabeçalho abre o modal de importação (mesma rota do Faturamento)', async () => {
        const w = mountPage();

        await w.find('[data-test="open-import"]').trigger('click');
        await flushPromises();

        expect(w.text()).toContain(t.import.import_return_title);
        expect(
            w
                .find('#billing-import-covenant')
                .findAll('option')
                .map((o) => o.text()),
        ).toContain('Unimed');
    });
});
