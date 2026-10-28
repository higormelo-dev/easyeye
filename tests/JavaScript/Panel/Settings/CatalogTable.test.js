import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import CatalogTable from '@/Pages/Panel/Settings/Catalog/CatalogTable.vue';

/**
 * Tabela dos catálogos no padrão de PatientTable: cabeçalho ordenável,
 * menu "Colunas" (ordem por catálogo no navegador) e ações por `mode`
 * (ActionPolicy) — convênio padrão do sistema (global) não é editável.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { template: '<div class="dd"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon'],
        emits: ['click'],
        template: '<button type="button" :title="title" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const columns = [
    { key: 'code', label: 'Código', type: 'code' },
    { key: 'name', label: 'Nome', type: 'text', sortable: true },
    { key: 'color', label: 'Cor', type: 'color' },
];

const t = {
    action_view: 'Ver detalhes',
    action_edit: 'Editar',
    action_delete: 'Excluir',
    action_restore: 'Restaurar',
    sort_by: 'Ordenar por :column',
    columns_label: 'Colunas',
};

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function row(overrides = {}) {
    return {
        id: 'a',
        code: 'CVP-1',
        name: 'UNIMED',
        color: '#3699ff',
        active: true,
        deleted: false,
        is_global: false,
        mode: 'full',
        ...overrides,
    };
}

function mountTable({ rows = [row()], filters = { sort: 'name', dir: 'asc' }, storageKey = 'covenants_view' } = {}) {
    wrapper = mount(CatalogTable, {
        props: {
            items: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [] },
            columns,
            sortable: ['name', 'code', 'created_at'],
            filters,
            t,
            storageKey,
        },
    });

    return wrapper;
}

describe('CatalogTable — ações por mode (ActionPolicy)', () => {
    it('registro da clínica (full): ver + editar/ativar/excluir', () => {
        const w = mountTable({ rows: [row({ mode: 'full' })] });

        expect(w.find('button[title="Ver detalhes"]').exists()).toBe(true);
        expect(w.text()).toContain('Editar');
        expect(w.text()).toContain('Excluir');
    });

    it('padrão do sistema (view_only): só visualizar — sem editar/excluir que o backend recusaria', () => {
        const w = mountTable({ rows: [row({ mode: 'view_only', is_global: true })] });

        expect(w.find('button[title="Ver detalhes"]').exists()).toBe(true);
        expect(w.text()).not.toContain('Editar');
        expect(w.text()).not.toContain('Excluir');
    });

    it('excluído da clínica (restore): só restaurar', () => {
        const w = mountTable({ rows: [row({ mode: 'restore', deleted: true })] });

        expect(w.find('button[title="Restaurar"]').exists()).toBe(true);
        expect(w.find('button[title="Ver detalhes"]').exists()).toBe(false);
    });

    it('emite o item nas ações', async () => {
        const item = row({ mode: 'view_only' });
        const w = mountTable({ rows: [item] });

        await w.find('button[title="Ver detalhes"]').trigger('click');

        expect(w.emitted('view')[0][0]).toMatchObject({ id: 'a' });
    });
});

describe('CatalogTable — ordenação pelos cabeçalhos', () => {
    it('só colunas da whitelist viram botão ordenável, com aria-sort', () => {
        const w = mountTable();
        const ths = w.findAll('thead th');

        // code e name são ordenáveis; color não
        expect(ths[0].find('button').exists()).toBe(true);
        expect(ths[1].find('button').exists()).toBe(true);
        expect(ths[2].find('button').exists()).toBe(false);
        expect(ths[1].attributes('aria-sort')).toBe('ascending');
        expect(ths[0].attributes('aria-sort')).toBe('none');
        expect(ths[2].attributes('aria-sort')).toBeUndefined();
        expect(ths[1].find('button').attributes('title')).toBe('Ordenar por Nome');
    });

    it('clicar na coluna já ordenada inverte a direção; em outra, começa ascendente', async () => {
        const w = mountTable({ filters: { sort: 'name', dir: 'asc' } });
        const ths = w.findAll('thead th');

        await ths[1].find('button').trigger('click');
        await ths[0].find('button').trigger('click');

        expect(w.emitted('sort')).toEqual([[{ sort: 'name', dir: 'desc' }], [{ sort: 'code', dir: 'asc' }]]);
    });
});

describe('CatalogTable — ordem de colunas personalizada', () => {
    it('respeita a ordem salva no navegador para o catálogo', () => {
        window.localStorage.setItem('covenants_columns_order', JSON.stringify(['name', 'color', 'code']));
        const w = mountTable();

        const labels = w.findAll('thead th').map((th) => th.text());

        expect(labels.slice(0, 3)).toEqual(['Nome', 'Cor', 'Código']);
    });

    it('ignora ordem salva inválida (coluna removida) e usa a padrão', () => {
        window.localStorage.setItem('covenants_columns_order', JSON.stringify(['name', 'antiga']));
        const w = mountTable();

        expect(
            w
                .findAll('thead th')
                .map((th) => th.text())
                .slice(0, 3),
        ).toEqual(['Código', 'Nome', 'Cor']);
    });
});
