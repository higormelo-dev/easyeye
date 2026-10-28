import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import GlosasIndex from '@/Pages/Panel/Financial/Tiss/GlosasIndex.vue';

/**
 * Conciliação de Glosas em escala (fase 4): a lista é uma página do paginator
 * do Laravel (TablePagination com rótulos traduzidos e links que levam os
 * filtros), os KPIs e as contagens das abas valem para o filtro inteiro (vêm
 * do servidor), o detalhe continua por recarga parcial e mudar filtro volta
 * para a página 1.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    const pageProps = reactive({ locale: 'pt_BR', flash: {}, errors: {} });

    return {
        usePage: () => ({ props: pageProps }),
        router: { get: vi.fn(), post: vi.fn(), reload: vi.fn() },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        useForm: (initial) =>
            reactive({
                ...initial,
                errors: {},
                processing: false,
                post: vi.fn(),
                reset() {},
                clearErrors() {},
                transform() {
                    return this;
                },
            }),
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'totalLabel'],
        template:
            '<div class="page-header"><span class="total">{{ totalLabel }} {{ total }}</span><slot name="actions" /></div>',
    },
}));
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open'],
        template: '<div v-if="open" class="modal-stub"><slot name="header" /><slot /><slot name="footer" /></div>',
    },
}));

const t = {
    title: 'Conciliação de Glosas',
    total_label: 'Pendentes:',
    tabs_label: 'Situação das glosas',
    tab_pending: 'Pendentes',
    tab_resolved: 'Resolvidas',
    tab_all: 'Todas',
    kpis_label: 'Indicadores das glosas',
    glosa_count: ':count glosa(s)',
    open_amount: 'Em aberto',
    appealed: 'Recorridas',
    overdue_title: 'Vencidas',
    recovered: 'Recuperado',
    recovered_of_total: 'de :total glosados no período',
    due_soon_title: 'Vencendo em :days dias',
    list_title_pending: 'Fila de glosas pendentes',
    empty_pending: 'Nenhuma glosa pendente.',
    empty_filtered: 'Nenhuma glosa com esses filtros.',
    filters_label: 'Filtros das glosas',
    filter_status: 'Status',
    filter_status_all: 'Todos os status',
    filter_operator: 'Convênio/operadora',
    filter_operator_all: 'Todos os convênios',
    filter_clear: 'Limpar filtros',
    period_any: 'Pendentes de qualquer data',
    details_btn: 'Detalhes',
    details_label: 'Ver detalhes da glosa :code',
    appeal_btn: 'Recorrer',
    more_actions: 'Mais ações da glosa :code',
    deadline_in_days: 'Vence em :days dias',
    pagination_showing: 'Exibindo',
    pagination_of: 'de',
    pagination_suffix: 'glosas',
    pagination_label: 'Paginação das glosas',
    pagination_previous: 'Página anterior',
    pagination_next: 'Próxima página',
    pagination_status: 'Página :page de :pages',
    detail_loading: 'Carregando...',
    close: 'Fechar',
};

const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v);
const norm = (s) => s.replace(/\s+/g, ' ').trim();

function glosa(id) {
    return {
        id,
        identified_at: '2026-09-10',
        deadline: '2026-10-20',
        resolved_at: null,
        operator_name: 'Unimed',
        guide_number: `GUI-${id}`,
        claim_code: null,
        reason_code: '3099',
        reason_text: 'Não autorizado',
        amount: 10,
        recovered_amount: 0,
        status: 'open',
        status_label: 'Aberta',
        status_color: 'danger',
        is_actionable: true,
        appeals_count: 0,
        appeal_url: `/glosas/${id}/appeal`,
        appeals: [],
    };
}

/** Página `current` de um paginator com `total` glosas (30 por página), links com os filtros da URL. */
function paginator(current, total, query = 'tab=pending&operator_id=op-1') {
    const perPage = 30;
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const from = total ? (current - 1) * perPage + 1 : null;
    const to = total ? Math.min(current * perPage, total) : null;
    const url = (page) => `/panel/financial/tiss/glosas?${query}&page=${page}`;
    const data = total ? Array.from({ length: to - from + 1 }, (_, i) => glosa(`g${from + i}`)) : [];

    return {
        data,
        current_page: current,
        last_page: lastPage,
        per_page: perPage,
        total,
        from,
        to,
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

const summary = {
    open: 350,
    open_count: 35,
    appealed: 0,
    appealed_count: 0,
    overdue: 20,
    overdue_count: 2,
    due_soon: 30,
    due_soon_count: 3,
    due_soon_days: 5,
    total: 350,
    count: 35,
    recovered: 0,
    appeal_response_days: 60,
};

let wrapper;

function mountPage(glosas) {
    wrapper = mount(GlosasIndex, {
        attachTo: document.body,
        global: { stubs: { teleport: true } },
        props: {
            filters: {
                tab: 'pending',
                from: '2026-09-01',
                to: '2026-09-30',
                status: null,
                operator_id: 'op-1',
                due: null,
                search: '',
            },
            today: '2026-09-26',
            summary,
            tabCounts: { pending: 35, resolved: 0, all: 35 },
            glosas,
            operators: [{ id: 'op-1', name: 'Unimed' }],
            statusOptions: { pending: [{ value: 'open', label: 'Aberta' }], resolved: [], all: [] },
            t,
        },
    });

    return wrapper;
}

beforeEach(() => {
    vi.mocked(router.get).mockClear();
    vi.mocked(router.reload).mockClear();
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('GlosasIndex — paginação', () => {
    it('mostra só a página atual e o rodapé traduzido, com links que levam os filtros da URL', () => {
        const w = mountPage(paginator(2, 35));

        expect(w.findAll('[data-test="glosa-row"]')).toHaveLength(5);

        const pagination = w.find('[data-test="glosas-pagination"]');
        expect(norm(pagination.find('p').text())).toBe('Exibindo 31–35 de 35 glosas');

        const nav = pagination.find('nav');
        expect(nav.attributes('aria-label')).toBe('Paginação das glosas');

        const previous = nav.find('a[aria-label="Página anterior"]');
        expect(previous.attributes('href')).toBe('/panel/financial/tiss/glosas?tab=pending&operator_id=op-1&page=1');
        expect(nav.find('a[aria-label="Próxima página"]').exists()).toBe(true);
        expect(nav.find('a[aria-current="page"]').attributes('href')).toContain('page=2');

        // Leitor de tela ouve em que página está.
        expect(w.find('[data-test="glosas-page-status"]').attributes('aria-live')).toBe('polite');
        expect(w.find('[data-test="glosas-page-status"]').text()).toBe('Página 2 de 2');
    });

    it('KPIs e contagens das abas valem para o filtro inteiro, não só para a página', () => {
        const w = mountPage(paginator(2, 35));

        expect(w.findAll('[data-test="glosa-row"]')).toHaveLength(5);
        expect(norm(w.find('[data-test="kpi-open"]').text())).toBe(norm(brl(350)));
        expect(w.find('[data-test="kpi-toggle-open"]').text()).toContain('35 glosa(s)');
        expect(w.find('[data-test="glosas-tab-pending"]').text()).toContain('35');
        expect(w.find('.total').text()).toBe('Pendentes: 35');
    });

    it('uma página só: sem rodapé de paginação nem anúncio de página', () => {
        const w = mountPage(paginator(1, 3));

        expect(w.findAll('[data-test="glosa-row"]')).toHaveLength(3);
        expect(w.find('[data-test="glosas-pagination"]').exists()).toBe(false);
        expect(w.find('[data-test="glosas-page-status"]').text()).toBe('');
    });

    it('detalhe de uma linha da página 2 continua por recarga parcial, sem trocar a URL', async () => {
        const w = mountPage(paginator(2, 35));

        await w.findAll('[data-test="open-details"]')[0].trigger('click');

        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['glosaDetail'],
                data: { detail: 'g31' },
                preserveUrl: true,
            }),
        );
    });

    it('mudar um filtro volta para a página 1 (page não vai nos parâmetros)', async () => {
        const w = mountPage(paginator(2, 35));

        await w.find('#glosas-filter-status').setValue('open');

        const [, params] = vi.mocked(router.get).mock.calls.at(-1);
        expect(params).toEqual({
            tab: 'pending',
            from: '2026-09-01',
            to: '2026-09-30',
            status: 'open',
            operator_id: 'op-1',
        });
        expect(params).not.toHaveProperty('page');
    });
});
