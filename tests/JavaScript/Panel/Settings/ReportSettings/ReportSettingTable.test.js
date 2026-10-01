import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ReportSettingTable from '@/Pages/Panel/Settings/ReportSettings/ReportSettingTable.vue';

/**
 * Tabela de modelos no padrão de PatientTable: ordenáveis só as colunas da
 * whitelist (Blocos/Origem não), blocos com texto acessível (não só ícone),
 * origem/atualização, status, data local, ações (ReportSettingActions real)
 * e ordem de colunas persistida.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: {
        props: ['title'],
        template: '<div class="dd" :data-title="title"><slot name="trigger" /><slot /></div>',
    },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'variant', 'href', 'target', 'inertiaHref'],
        emits: ['click'],
        template:
            '<button type="button" class="action" :title="title" :data-href="href ?? inertiaHref" :data-target="target" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_title: 'Modelo',
    col_category: 'Categoria',
    col_paper: 'Papel',
    col_blocks: 'Blocos',
    col_origin: 'Origem',
    col_updated_at: 'Atualizado em',
    col_status: 'Status',
    col_actions: 'Ações',
    sort_by: 'Ordenar por :column',
    no_description: 'Sem descrição',
    block_header: 'Cabeçalho',
    block_signature: 'Assinatura',
    block_footer: 'Rodapé',
    block_on: ':block: incluído',
    block_off: ':block: não incluído',
    origin_own: 'Próprio',
    origin_adopted: 'Adotado',
    update_available: 'Atualização disponível',
    status_active: 'Ativo',
    status_inactive: 'Inativo',
    action_preview: 'Pré-visualizar',
    action_edit: 'Editar',
    action_reimport: 'Reimportar modelo global',
    action_delete: 'Excluir',
    more_actions: 'Mais ações',
};

function template(overrides = {}) {
    return {
        id: 'r1',
        title: 'Receituário',
        description: 'Óculos',
        category: 'Receitas',
        paper_size: 'A4',
        show_header: true,
        show_signature: false,
        show_footer: true,
        active: true,
        is_adopted: true,
        has_update: true,
        updated_at: '2026-09-27T12:00:00-03:00',
        mode: 'full',
        preview_url: '/rs/r1/preview',
        edit_url: '/rs/r1/edit',
        destroy_url: '/rs/r1',
        reimport_url: '/rs/r1/reimport',
        ...overrides,
    };
}

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function mountTable(rows = [template()]) {
    wrapper = mount(ReportSettingTable, {
        props: {
            items: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [] },
            filters: { sort: 'title', direction: 'asc' },
            t,
            emptyText: 'Nenhum modelo cadastrado.',
        },
    });

    return wrapper;
}

describe('Settings/ReportSettings/ReportSettingTable', () => {
    it('ordenáveis só as colunas da whitelist; padrão = título A→Z', async () => {
        const w = mountTable();
        const ths = w.findAll('thead th');

        expect(ths.map((th) => th.text().trim())).toEqual([
            'Modelo',
            'Categoria',
            'Papel',
            'Blocos',
            'Origem',
            'Atualizado em',
            'Status',
            'Ações',
        ]);
        expect(ths[0].attributes('aria-sort')).toBe('ascending');
        expect(ths[3].find('button').exists()).toBe(false); // Blocos
        expect(ths[4].find('button').exists()).toBe(false); // Origem

        await ths[1].get('button').trigger('click');
        await ths[5].get('button').trigger('click');
        expect(w.emitted('sort')).toEqual([
            [{ sort: 'category', direction: 'asc' }],
            [{ sort: 'updated_at', direction: 'asc' }],
        ]);
    });

    it('linha: título + descrição, blocos com texto acessível, origem, data local e status', () => {
        const w = mountTable();
        const cells = w.findAll('tbody tr td');

        expect(cells[0].text()).toContain('Receituário');
        expect(cells[0].text()).toContain('Óculos');
        expect(cells[3].findAll('.visually-hidden').map((s) => s.text())).toEqual([
            'Cabeçalho: incluído',
            'Assinatura: não incluído',
            'Rodapé: incluído',
        ]);
        expect(cells[4].text()).toContain('Adotado');
        expect(cells[4].text()).toContain('Atualização disponível');
        expect(cells[5].text()).toBe('27/09/2026');
        expect(cells[6].text()).toBe('Ativo');
    });

    it('modelo próprio e inativo: origem Próprio, sem atualização, sem reimportar', () => {
        const w = mountTable([
            template({ is_adopted: false, has_update: false, reimport_url: null, active: false, description: null }),
        ]);
        const cells = w.findAll('tbody tr td');

        expect(cells[0].text()).toContain('Sem descrição');
        expect(cells[4].text()).toBe('Próprio');
        expect(cells[6].text()).toBe('Inativo');
        expect(w.findAll('tbody .dropdown-item').map((b) => b.text())).toEqual(['Excluir']);
    });

    it('ações: pré-visualizar em nova aba, editar pelo formulário, reimportar/excluir emitem o modelo', async () => {
        const row = template();
        const w = mountTable([row]);
        const [preview, edit] = w.findAll('tbody .action');

        expect(preview.attributes('data-href')).toBe('/rs/r1/preview');
        expect(preview.attributes('data-target')).toBe('_blank');
        expect(edit.attributes('data-href')).toBe('/rs/r1/edit');

        const [reimport, remove] = w.findAll('tbody .dropdown-item');
        await reimport.trigger('click');
        await remove.trigger('click');

        expect(w.emitted('reimport')[0][0]).toEqual(row);
        expect(w.emitted('delete')[0][0]).toEqual(row);
    });

    it('fora do modo full: só pré-visualizar', () => {
        const w = mountTable([template({ mode: 'view_only' })]);

        expect(w.findAll('tbody .action').map((b) => b.attributes('title'))).toEqual(['Pré-visualizar']);
        expect(w.find('tbody .dd').exists()).toBe(false);
    });

    it('título é texto, nunca HTML interpretado', () => {
        const w = mountTable([template({ title: '<img src=x onerror="alert(1)">' })]);

        expect(w.find('tbody img').exists()).toBe(false);
        expect(w.get('tbody td').text()).toContain('<img src=x onerror="alert(1)">');
    });

    it('sem modelos: linha com o texto vazio ocupando todas as colunas', () => {
        const cell = mountTable([]).get('tbody td');

        expect(cell.text()).toBe('Nenhum modelo cadastrado.');
        expect(cell.attributes('colspan')).toBe('8');
    });

    it('respeita a ordem de colunas salva no navegador', () => {
        window.localStorage.setItem(
            'report_settings_columns_order',
            JSON.stringify(['atualizado', 'modelo', 'categoria', 'papel', 'blocos', 'origem']),
        );
        const w = mountTable();

        expect(
            w
                .findAll('thead th')
                .map((th) => th.text().trim())
                .slice(0, 2),
        ).toEqual(['Atualizado em', 'Modelo']);
    });
});
