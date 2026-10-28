import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

/**
 * Fase 1 / B3 — "Prontuários" no menu (busca geral) e relatório de primeira
 * consulta: filtros na URL, escopo do médico (não manda médico; o servidor
 * decide), ações por linha e exportação com o mesmo recorte.
 */
const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR', t_ui: {} } }),
    router: { get: routerGet, post: vi.fn(), reload: vi.fn() },
    Link: { name: 'InertiaLink', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { template: '<div />' },
}));

const Search = (await import('@/Pages/Panel/MedicalRecords/Search.vue')).default;
const FirstConsultations = (await import('@/Pages/Panel/Reports/FirstConsultations.vue')).default;

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    PageHeader: { props: ['title', 'subtitle'], template: '<header>{{ title }} {{ subtitle }}</header>' },
    TablePagination: true,
    MedicalRecordDetailDrawer: {
        name: 'MedicalRecordDetailDrawer',
        props: ['open', 'record', 'patient', 't'],
        template:
            '<div data-testid="drawer" :data-open="open" :data-record="record?.id" :data-patient="patient?.id" />',
    },
};

const ts = {
    title: 'Prontuários',
    subtitle: 'Busca nos prontuários da clínica',
    subtitle_mine: 'Os prontuários registrados por você',
    search_label: 'Paciente ou código',
    doctor_all: 'Todos os médicos',
    signed_all: 'Todos',
    signed_yes: 'Assinados',
    signed_no: 'Não assinados',
    filter: 'Buscar',
    clear: 'Limpar',
    status_signed: 'Assinado',
    status_unsigned: 'Não assinado',
    signed_at: 'Assinado em :date',
    age_years: ':age anos',
    urgent_badge: 'Pronto atendimento',
    action_view: 'Ver prontuário',
    action_edit: 'Editar',
    action_history: 'Histórico do paciente',
    empty: 'Nenhum prontuário encontrado.',
    empty_hint: 'Mude a busca ou o período.',
};

const row = (over = {}) => ({
    id: 'r1',
    code: 'PMR-0000000001',
    created_at: '06/10/2026 10:00',
    patient_id: 'p1',
    patient_name: 'JOÃO DA SILVA',
    patient_code: 'PAC-0000000001',
    age: 46,
    doctor_name: 'DRA. ANA',
    is_signed: false,
    is_urgent_care: false,
    show_url: '/show/r1',
    history_url: '/history/p1',
    edit_url: null,
    ...over,
});

beforeEach(() => routerGet.mockReset());

describe('Prontuários — busca geral', () => {
    const mountSearch = (props = {}) =>
        mount(Search, {
            props: {
                filters: {},
                records: { data: [row()], total: 1, last_page: 1 },
                doctors: [{ id: 'd1', name: 'DRA. ANA', active: true }],
                ts,
                ...props,
            },
            global: { stubs },
        });

    it('filtros vão para a URL (só os preenchidos)', async () => {
        const wrapper = mountSearch();

        await wrapper.find('[data-testid="mrs-q"]').setValue('joao');
        await wrapper.find('[data-testid="mrs-doctor"]').setValue('d1');
        await wrapper.find('[data-testid="mrs-signed"]').setValue('no');
        await wrapper.find('form').trigger('submit');

        expect(routerGet).toHaveBeenCalledWith(
            '/_routes/panel.medical-records.index',
            { q: 'joao', doctor: 'd1', signed: 'no' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('médico: sem seletor de médico e o filtro de médico não vai na URL', async () => {
        const wrapper = mountSearch({ isDoctor: true, filters: { doctor: 'd1' }, doctors: [] });

        expect(wrapper.find('[data-testid="mrs-doctor"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Os prontuários registrados por você');

        await wrapper.find('form').trigger('submit');
        expect(routerGet.mock.calls[0][1]).toEqual({});
    });

    it('linha: assinatura com texto, selo do pronto atendimento, editar só com URL e "Ver" abre o drawer', async () => {
        const wrapper = mountSearch({
            records: {
                data: [
                    row({ id: 'r1', is_signed: true, signed_at: '01/10/2026 09:00', is_urgent_care: true }),
                    row({ id: 'r2', edit_url: '/edit/r2' }),
                ],
                total: 2,
                last_page: 1,
            },
        });

        const rows = wrapper.findAll('[data-testid="mrs-row"]');
        expect(rows[0].text()).toContain('Assinado');
        expect(rows[0].text()).toContain('Pronto atendimento');
        expect(rows[0].find('[data-testid="mrs-edit"]').exists()).toBe(false);
        expect(rows[1].text()).toContain('Não assinado');
        expect(rows[1].find('[data-testid="mrs-edit"]').attributes('href')).toBe('/edit/r2');

        await rows[1].find('[data-testid="mrs-view"]').trigger('click');
        const drawer = wrapper.find('[data-testid="drawer"]');
        expect(drawer.attributes('data-open')).toBe('true');
        expect(drawer.attributes('data-record')).toBe('r2');
        expect(drawer.attributes('data-patient')).toBe('p1');
    });

    it('sem resultado: mensagem; médico sem cadastro: só o aviso', () => {
        expect(
            mountSearch({ records: { data: [], total: 0, last_page: 1 } })
                .find('[data-testid="mrs-empty"]')
                .text(),
        ).toContain('Nenhum prontuário encontrado.');

        const missing = mountSearch({
            doctorMissing: true,
            doctorMissingText: 'Sem cadastro de médico',
            records: null,
        });
        expect(missing.text()).toContain('Sem cadastro de médico');
        expect(missing.find('form').exists()).toBe(false);
    });
});

describe('Relatório de primeira consulta', () => {
    const t = {
        title: 'Relatório de primeira consulta',
        subtitle: 'Pacientes…',
        subtitle_mine: 'Pacientes… com você',
        doctor_all: 'Todos os médicos',
        filter: 'Filtrar',
        export_csv: 'Exportar CSV',
        export_xlsx: 'Exportar Excel',
        period: 'Período: :from a :until',
        total: 'Novos pacientes',
        by_doctor: 'Por médico',
        no_covenant: 'Particular / sem convênio',
        urgent_badge: 'Pronto atendimento',
        empty: 'Nenhuma primeira consulta no período.',
    };

    const mountReport = (props = {}) =>
        mount(FirstConsultations, {
            props: {
                filters: { date_from: '2026-10-01', date_until: '2026-10-31', doctor_id: null },
                doctors: [{ id: 'd1', name: 'DRA. ANA' }],
                rows: [
                    {
                        id: 's1',
                        date_time: '05/10/2026 09:00',
                        patient_name: 'PACIENTE NOVO',
                        patient_code: 'PAC-1',
                        doctor_name: 'DRA. ANA',
                        covenant: null,
                        type: 'URGÊNCIA',
                        is_urgent_care: true,
                    },
                ],
                summary: { total: 1, by_doctor: [{ name: 'DRA. ANA', total: 1 }] },
                period: { from: '01/10/2026', until: '31/10/2026' },
                urls: { export: '/export' },
                t,
                ...props,
            },
            global: { stubs },
        });

    it('mostra total, período, linha (convênio vazio = particular) e o selo do pronto atendimento', () => {
        const wrapper = mountReport();

        expect(wrapper.find('[data-testid="fc-total"]').text()).toBe('1');
        expect(wrapper.text()).toContain('Período: 01/10/2026 a 31/10/2026');
        const line = wrapper.find('[data-testid="fc-row"]');
        expect(line.text()).toContain('PACIENTE NOVO');
        expect(line.text()).toContain('Particular / sem convênio');
        expect(line.text()).toContain('Pronto atendimento');
    });

    it('exportar leva o mesmo recorte (período e médico)', async () => {
        const wrapper = mountReport();
        await wrapper.find('[data-testid="fc-doctor"]').setValue('d1');

        expect(wrapper.find('[data-testid="fc-export-csv"]').attributes('href')).toBe(
            '/export?date_from=2026-10-01&date_until=2026-10-31&doctor_id=d1&format=csv',
        );
        expect(wrapper.find('[data-testid="fc-export-xlsx"]').attributes('href')).toContain('format=xlsx');

        await wrapper.find('form').trigger('submit');
        expect(routerGet).toHaveBeenCalledWith(
            '/_routes/panel.schedules.reports.first-consultations',
            { date_from: '2026-10-01', date_until: '2026-10-31', doctor_id: 'd1' },
            expect.any(Object),
        );
    });

    it('médico: sem seletor de médico e sem quebra por médico', () => {
        const wrapper = mountReport({ isDoctor: true, doctors: [] });

        expect(wrapper.find('[data-testid="fc-doctor"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Por médico');
        expect(wrapper.text()).toContain('com você');
    });

    it('vazio: mensagem na tabela', () => {
        const wrapper = mountReport({ rows: [], summary: { total: 0, by_doctor: [] } });

        expect(wrapper.find('[data-testid="fc-empty"]').text()).toContain('Nenhuma primeira consulta no período.');
    });
});
