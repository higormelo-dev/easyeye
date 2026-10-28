import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import DoctorTable from '@/Pages/Panel/Doctors/DoctorTable.vue';

/**
 * Tabela de médicos no padrão de PatientTable: coluna Telefone com WhatsApp,
 * menu "Colunas" (ordem no navegador), ordenação pelos cabeçalhos e ações
 * por `mode` (ActionPolicy).
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { template: '<div class="dd"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'href'],
        emits: ['click'],
        template: '<button type="button" :title="title" :data-href="href" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_name: 'Nome',
    col_phone: 'Telefone',
    col_record: 'CRM',
    col_email: 'E-mail',
    col_created_at: 'Cadastro',
    col_code: 'Código',
    sort_by: 'Ordenar por :column',
    action_view: 'Visualizar',
    action_work_schedule: 'Horários de atendimento',
    action_edit: 'Editar',
    action_delete: 'Excluir',
    whatsapp: 'WhatsApp',
    empty_list: 'Nenhum médico encontrado.',
};

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function doctor(overrides = {}) {
    return {
        id: 'd1',
        code: 'MED-1',
        full_name: 'DRA ANA',
        record: '12345',
        record_specialty: 'RETINA',
        email: 'ana@clinica.test',
        cellphone: '(61) 99999-8888',
        whatsapp: true,
        color: '#ff0000',
        active: true,
        created_at: '01/09/2026',
        photo_url: '/img.png',
        work_schedule_url: '/doctors/d1/work-schedule',
        mode: 'full',
        ...overrides,
    };
}

function mountTable(rows = [doctor()], filters = { sort: 'created_at', direction: 'desc' }) {
    wrapper = mount(DoctorTable, {
        props: { doctors: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [] }, filters, t },
    });

    return wrapper;
}

const headerLabels = (w) => w.findAll('thead th').map((th) => th.text());

describe('DoctorTable', () => {
    it('mostra as colunas na ordem padrão, com Telefone logo após o Nome (igual a pacientes)', () => {
        const w = mountTable();

        expect(headerLabels(w).slice(0, 6)).toEqual(['Nome', 'Telefone', 'CRM', 'E-mail', 'Cadastro', 'Código']);
    });

    it('exibe telefone formatado com ícone de WhatsApp acessível', () => {
        const w = mountTable([doctor({ whatsapp: true })]);
        const phoneCell = w.findAll('tbody td')[1];

        expect(phoneCell.text()).toContain('(61) 99999-8888');
        expect(phoneCell.find('.fa-whatsapp').attributes('aria-label')).toBe('WhatsApp');
    });

    it('mostra travessão quando o médico não tem celular', () => {
        const w = mountTable([doctor({ cellphone: null, whatsapp: false })]);

        expect(w.findAll('tbody td')[1].text()).toBe('—');
    });

    it('respeita a ordem de colunas salva no navegador', () => {
        window.localStorage.setItem(
            'doc_table_columns_order',
            JSON.stringify(['codigo', 'nome', 'telefone', 'crm', 'email', 'cadastro']),
        );
        const w = mountTable();

        expect(headerLabels(w).slice(0, 2)).toEqual(['Código', 'Nome']);
    });

    it('ordena pelo cabeçalho: inverte a direção da coluna atual e começa ascendente nas outras', async () => {
        const w = mountTable([doctor()], { sort: 'full_name', direction: 'asc' });
        const ths = w.findAll('thead th');

        expect(ths[0].attributes('aria-sort')).toBe('ascending');
        expect(ths[1].attributes('aria-sort')).toBe('none');

        await ths[0].find('button').trigger('click');
        await ths[1].find('button').trigger('click');

        expect(w.emitted('sort')).toEqual([
            [{ sort: 'full_name', direction: 'desc' }],
            [{ sort: 'cellphone', direction: 'asc' }],
        ]);
    });

    it('médico da clínica (full): visualizar, horários e menu editar/excluir', () => {
        const w = mountTable([doctor({ mode: 'full' })]);

        expect(w.find('button[title="Visualizar"]').exists()).toBe(true);
        expect(w.find('button[title="Horários de atendimento"]').attributes('data-href')).toBe(
            '/doctors/d1/work-schedule',
        );
        expect(w.text()).toContain('Editar');
        expect(w.text()).toContain('Excluir');
    });

    it('sem permissão de escrita (view_only): só visualizar', () => {
        const w = mountTable([doctor({ mode: 'view_only' })]);

        expect(w.find('button[title="Visualizar"]').exists()).toBe(true);
        expect(w.find('button[title="Horários de atendimento"]').exists()).toBe(false);
        expect(w.text()).not.toContain('Editar');
    });

    it('emite o id nas ações', async () => {
        const w = mountTable();

        await w.find('button[title="Visualizar"]').trigger('click');

        expect(w.emitted('view')[0]).toEqual(['d1']);
    });

    it('mostra o estado vazio', () => {
        const w = mountTable([]);

        expect(w.text()).toContain('Nenhum médico encontrado.');
    });
});
