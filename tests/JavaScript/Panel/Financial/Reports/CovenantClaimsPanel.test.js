import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import CovenantClaimsPanel from '@/Pages/Panel/Financial/Reports/CovenantClaimsPanel.vue';

/**
 * Guias do convênio (linha expandida): JSON paginado do período aplicado,
 * paciente só por código + iniciais, estados carregando/erro/vazio, paginação
 * acessível (foco não se perde), atalhos para Faturamento/Glosas e Esc/Fechar.
 */

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR', flash: {}, errors: {} } }),
    router: { get: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const t = {
    claim_status: { submitted: 'Submitted', paid: 'Paid', denied: 'Denied' },
    covenants: {
        claims_title: ':covenant claims in the period',
        claims_loading: 'Loading claims…',
        claims_error: 'Could not load the claims.',
        claims_retry: 'Try again',
        claims_empty: 'No claims for this insurer.',
        claims_close: 'Close',
        claims_privacy_note: 'Patient shown by code and initials.',
        col_guide: 'Claim',
        col_attendance_date: 'Attendance date',
        col_patient: 'Patient (code · initials)',
        col_status: 'Status',
        col_value: 'Amount',
        col_received: 'Received',
        col_glosa: 'Denied',
        view_in_billing: 'View in billing',
        view_glosas: 'View denials',
        pagination_label: 'Claims pagination',
        pagination_previous: 'Previous page',
        pagination_next: 'Next page',
        pagination_status: 'Page :current of :last',
        claims_count_one: ':count claim',
        claims_count_other: ':count claims',
    },
};

const ROW = { covenant_id: 'c-uni', covenant: 'UNIMED' };

function claim(id, overrides = {}) {
    return {
        id,
        code: `GUI-${id}`,
        attendance_date: '2026-09-05',
        status: 'submitted',
        patient: 'PAC-0000000001 · J. S.',
        amount: 100,
        received: 0,
        glosa: 0,
        ...overrides,
    };
}

function page(current, last, total, data) {
    return { data, meta: { current_page: current, last_page: last, per_page: 10, total, from: 1, to: data.length } };
}

function jsonResponse(status, body) {
    return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

let wrapper;

beforeEach(() => {
    globalThis.fetch = vi.fn(() =>
        jsonResponse(200, page(1, 1, 1, [claim('1', { status: 'paid', received: 180, glosa: 20, amount: 200 })])),
    );
});
afterEach(() => wrapper?.unmount());

function mountPanel(props = {}) {
    wrapper = mount(CovenantClaimsPanel, {
        props: {
            id: 'claims-region',
            row: ROW,
            filters: { from: '2026-09-01', to: '2026-09-26' },
            endpoint: '/reports/covenants/claims',
            t,
            ...props,
        },
        attachTo: document.body,
    });

    return wrapper;
}

const clean = (value) => value.replace(/ | /g, ' ');
const text = (el) => clean(el.text());
const brl = (v) => clean(new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));

describe('Financial/Reports/CovenantClaimsPanel', () => {
    it('busca a página 1 do período aplicado e mostra as guias com paciente por código + iniciais', async () => {
        const w = mountPanel();

        expect(w.find('[data-test="claims-loading"]').exists()).toBe(true);
        expect(w.attributes('aria-busy')).toBe('true');

        await flushPromises();

        expect(fetch).toHaveBeenCalledWith(
            '/reports/covenants/claims?covenant_id=c-uni&from=2026-09-01&to=2026-09-26&page=1',
            expect.objectContaining({
                headers: expect.objectContaining({ Accept: 'application/json' }),
                credentials: 'same-origin',
            }),
        );

        const row = w.find('[data-test="claim-row"]');
        expect(w.attributes('role')).toBe('region');
        expect(w.attributes('aria-labelledby')).toBe(w.find('[data-test="claims-title"]').attributes('id'));
        expect(w.find('[data-test="claims-title"]').text()).toBe('UNIMED claims in the period');
        expect(w.find('[data-test="claims-privacy"]').text()).toBe('Patient shown by code and initials.');
        expect(row.find('[data-test="claim-code"]').text()).toBe('GUI-1');
        expect(row.find('[data-test="claim-date"]').text()).toBe('05/09/2026');
        expect(row.find('[data-test="claim-patient"]').text()).toBe('PAC-0000000001 · J. S.');
        expect(row.find('[data-test="claim-status"]').text()).toBe('Paid');
        expect(row.find('[data-test="claim-status"]').classes()).toContain('badge-soft-success');
        expect(text(row.find('[data-test="claim-received"]'))).toBe(brl(180));
        expect(w.find('[data-test="claims-pagination"]').exists()).toBe(false);
        expect(w.find('[data-test="claims-live"]').text()).toBe('Page 1 of 1 · 1 claim');
        expect(w.attributes('aria-busy')).toBe('false');
    });

    it('paginação: próxima página busca page=2; na última, o foco vai para a região', async () => {
        fetch
            .mockImplementationOnce(() =>
                jsonResponse(
                    200,
                    page(
                        1,
                        2,
                        12,
                        Array.from({ length: 10 }, (_, i) => claim(`a${i}`)),
                    ),
                ),
            )
            .mockImplementationOnce(() => jsonResponse(200, page(2, 2, 12, [claim('b1'), claim('b2')])));

        const w = mountPanel();
        await flushPromises();

        expect(w.find('[data-test="claims-page-status"]').text()).toBe('Page 1 of 2 · 12 claims');
        expect(w.find('[data-test="claims-prev"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="claims-next"]').attributes('aria-label')).toBe('Next page');
        expect(w.find('nav').attributes('aria-label')).toBe('Claims pagination');

        await w.find('[data-test="claims-next"]').trigger('click');
        await flushPromises();

        expect(fetch.mock.calls[1][0]).toBe(
            '/reports/covenants/claims?covenant_id=c-uni&from=2026-09-01&to=2026-09-26&page=2',
        );
        expect(w.findAll('[data-test="claim-row"]')).toHaveLength(2);
        expect(w.find('[data-test="claims-page-status"]').text()).toBe('Page 2 of 2 · 12 claims');
        expect(w.find('[data-test="claims-next"]').attributes('disabled')).toBeDefined();
        expect(document.activeElement).toBe(w.element);
    });

    it('atalhos: faturamento filtrado pelo convênio e período; glosas com o período', async () => {
        const w = mountPanel();
        await flushPromises();

        expect(w.find('[data-test="view-billing"]').attributes('href')).toBe(
            '/_routes/panel.financial.billing.index?tab=claims&covenant_id=c-uni&from=2026-09-01&to=2026-09-26',
        );
        expect(w.find('[data-test="view-glosas"]').attributes('href'))
            // Aba "todas": na padrão (pendentes) o período seria ignorado.
            .toBe('/_routes/panel.financial.tiss.glosas.index?tab=all&from=2026-09-01&to=2026-09-26');
    });

    it('"Sem convênio" (chave vazia) busca com covenant_id vazio e não oferece o atalho do faturamento', async () => {
        const w = mountPanel({ row: { covenant_id: '', covenant: 'Sem convênio' } });
        await flushPromises();

        expect(fetch.mock.calls[0][0]).toBe(
            '/reports/covenants/claims?covenant_id=&from=2026-09-01&to=2026-09-26&page=1',
        );
        expect(w.find('[data-test="view-billing"]').exists()).toBe(false);
        expect(w.find('[data-test="view-glosas"]').exists()).toBe(true);
    });

    it('erro mostra aviso com "Try again" que refaz a busca; vazio mostra a mensagem', async () => {
        fetch.mockImplementationOnce(() => jsonResponse(500, {}));

        const w = mountPanel();
        await flushPromises();

        expect(w.find('[data-test="claims-error"]').attributes('role')).toBe('alert');
        expect(w.find('[data-test="claims-error"]').text()).toContain('Could not load the claims.');

        fetch.mockImplementationOnce(() => jsonResponse(200, page(1, 1, 0, [])));
        await w.find('[data-test="claims-retry"]').trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenCalledTimes(2);
        expect(w.find('[data-test="claims-error"]').exists()).toBe(false);
        expect(w.find('[data-test="claims-empty"]').text()).toBe('No claims for this insurer.');
    });

    it('falha de rede também vira aviso (sem exceção solta)', async () => {
        fetch.mockImplementationOnce(() => Promise.reject(new TypeError('Failed to fetch')));

        const w = mountPanel();
        await flushPromises();

        expect(w.find('[data-test="claims-error"]').exists()).toBe(true);
    });

    it('troca de período aplicado recomeça da página 1; resposta antiga não sobrescreve a nova', async () => {
        let resolveSlow;
        fetch
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        resolveSlow = resolve;
                    }),
            )
            .mockImplementationOnce(() => jsonResponse(200, page(1, 1, 1, [claim('new')])));

        const w = mountPanel();
        await w.setProps({ filters: { from: '2026-08-01', to: '2026-08-31' } });
        await flushPromises();

        resolveSlow({ ok: true, status: 200, json: () => Promise.resolve(page(1, 1, 1, [claim('old')])) });
        await flushPromises();

        expect(fetch.mock.calls[1][0]).toBe(
            '/reports/covenants/claims?covenant_id=c-uni&from=2026-08-01&to=2026-08-31&page=1',
        );
        expect(w.findAll('[data-test="claim-code"]').map((c) => c.text())).toEqual(['GUI-new']);
    });

    it('Esc dentro da região e o botão "Close" pedem para fechar', async () => {
        const w = mountPanel();
        await flushPromises();

        await w.find('[data-test="claim-row"]').trigger('keydown', { key: 'Escape' });
        await w.find('[data-test="claims-close"]').trigger('click');

        expect(w.emitted('close')).toHaveLength(2);
    });
});
