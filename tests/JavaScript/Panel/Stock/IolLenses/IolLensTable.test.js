import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import IolLensTable from '@/Pages/Panel/Stock/IolLenses/IolLensTable.vue';

/**
 * Tabela de lentes IOL no padrão de PatientTable: ordem de colunas
 * personalizável/persistida, todos os cabeçalhos ordenáveis pela whitelist
 * do backend (SortableTh real, padrão fabricante A→Z), dioptria/valor/
 * estoque no idioma do usuário, ações e estado vazio.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: {
        props: ['title', 'btnClass'],
        template:
            '<div class="dd" :data-title="title" :data-btn-class="btnClass"><span class="dd-trigger"><slot name="trigger" /></span><slot /></div>',
    },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'variant', 'inertiaHref'],
        emits: ['click'],
        template:
            '<button type="button" :title="title" :data-href="inertiaHref" :data-variant="variant" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_model: 'Modelo',
    col_manufacturer: 'Fabricante',
    col_category: 'Tipo',
    col_diopters: 'Dioptrias',
    col_price: 'Valor',
    col_stock: 'Estoque',
    col_status: 'Status',
    col_actions: 'Ações',
    sort_by: 'Ordenar por :column',
    diopter_range: ':min a :max D',
    not_informed: 'Não informado',
    status_active: 'Ativa',
    status_inactive: 'Inativa',
    action_movements: 'Movimentações da lente',
    action_edit: 'Editar',
    action_activate: 'Ativar',
    action_deactivate: 'Desativar',
    action_delete: 'Excluir',
    more_actions: 'Mais ações',
    empty_list: 'Nenhuma lente encontrada.',
    columns_label: 'Colunas',
    columns_customize: 'Personalizar colunas',
};

const nbsp = (s) => s.replace(/ /g, ' ');

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function lens(overrides = {}) {
    return {
        id: 'l1',
        manufacturer: 'Alcon',
        model_name: 'AcrySof IQ',
        category: 'Monofocal',
        diopter_min: 10,
        diopter_max: 30,
        price: 2500.5,
        image_url: null,
        active: true,
        entity_product_id: 'prod-1',
        stock: { id: 'prod-1', unit_label: 'Unidade', qty_on_hand: 3 },
        ...overrides,
    };
}

function mountTable(rows = [lens()], filters = { sort: 'manufacturer', direction: 'asc' }) {
    wrapper = mount(IolLensTable, {
        props: {
            items: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [] },
            filters,
            t,
            movementsIndexUrl: '/stock/movements',
        },
    });

    return wrapper;
}

const headerLabels = (w) => w.findAll('thead th').map((th) => th.text());

describe('IolLensTable', () => {
    it('mostra as colunas na ordem padrão com Status e Ações no fim', () => {
        const w = mountTable();

        expect(headerLabels(w)).toEqual([
            'Modelo',
            'Fabricante',
            'Tipo',
            'Dioptrias',
            'Valor',
            'Estoque',
            'Status',
            'Ações',
        ]);
    });

    it('respeita a ordem de colunas salva no navegador', () => {
        window.localStorage.setItem(
            'stock_iollenses_columns_order',
            JSON.stringify(['fabricante', 'modelo', 'valor', 'tipo', 'dioptrias', 'estoque']),
        );
        const w = mountTable();

        expect(headerLabels(w).slice(0, 3)).toEqual(['Fabricante', 'Modelo', 'Valor']);
    });

    it('marca a ordenação atual (fabricante) e emite pelo SortableTh', async () => {
        const w = mountTable();
        const ths = w.findAll('thead th');

        expect(ths[1].attributes('aria-sort')).toBe('ascending');
        expect(ths[0].attributes('aria-sort')).toBe('none');
        expect(ths[4].find('button').attributes('title')).toBe('Ordenar por Valor');

        await ths[1].find('button').trigger('click');
        await ths[4].find('button').trigger('click');
        await ths[3].find('button').trigger('click');

        expect(w.emitted('sort')).toEqual([
            [{ sort: 'manufacturer', direction: 'desc' }],
            [{ sort: 'price', direction: 'asc' }],
            [{ sort: 'diopter_min', direction: 'asc' }],
        ]);
    });

    it('formata dioptrias com sinal, valor em moeda local e estoque com unidade', () => {
        const w = mountTable([lens({ diopter_min: -5, diopter_max: 12.5, price: 2500.5 })]);
        const cells = w.findAll('tbody td').map((td) => nbsp(td.text()));

        expect(cells[3]).toBe('-5,0 a +12,5 D');
        expect(cells[4]).toBe('R$ 2.500,50');
        expect(cells[5]).toBe('3 Unidade');
    });

    it('estoque usa o formatador compartilhado: até 3 casas, sem zeros à direita', () => {
        const w = mountTable([lens({ stock: { id: 'prod-1', unit_label: 'Caixa', qty_on_hand: 1.125 } })]);

        expect(nbsp(w.findAll('tbody td')[5].text())).toBe('1,125 Caixa');
    });

    it('botão "Colunas" acompanha o tema (bg-body) e o "Mais ações" da linha usa o gatilho padrão', () => {
        const w = mountTable();

        const columns = w.find('[data-title="Personalizar colunas"]');
        expect(columns.attributes('data-btn-class')).toContain('bg-body');
        expect(columns.attributes('data-btn-class')).not.toContain('bg-white');
        expect(columns.find('.dd-trigger').text()).toBe('Colunas');

        const more = w.find('[data-title="Mais ações"]');
        expect(more.find('.dd-trigger').text()).toBe('');
        expect(more.find('.visually-hidden').exists()).toBe(false);
    });

    it('mostra "Não informado" sem valor e travessão sem faixa de dioptria', () => {
        const w = mountTable([lens({ price: null, diopter_max: null, category: null })]);
        const cells = w.findAll('tbody td').map((td) => td.text());

        expect(cells[2]).toBe('—');
        expect(cells[3]).toBe('—');
        expect(cells[4]).toBe('Não informado');
    });

    it('mostra a foto da lente quando existe, com texto alternativo', () => {
        const w = mountTable([lens({ image_url: '/storage/l1.jpg' })]);

        expect(w.find('tbody img').attributes('src')).toBe('/storage/l1.jpg');
        expect(w.find('tbody img').attributes('alt')).toBe('AcrySof IQ');
    });

    it('atalho de movimentações usa o produto de estoque vinculado; menu emite a lente', async () => {
        const l = lens();
        const w = mountTable([l]);

        const shortcut = w.find('button[title="Movimentações da lente"]');
        expect(shortcut.attributes('data-href')).toBe('/stock/movements?entity_product_id=prod-1');
        expect(shortcut.attributes('data-variant')).toBe('info');

        const items = w.findAll('.dropdown-item');
        expect(items.map((b) => b.text())).toEqual(['Editar', 'Desativar', 'Excluir']);

        await items[0].trigger('click');
        await items[1].trigger('click');
        await items[2].trigger('click');

        expect(w.emitted('edit')[0]).toEqual([l]);
        expect(w.emitted('toggleActive')[0]).toEqual([l]);
        expect(w.emitted('delete')[0]).toEqual([l]);
    });

    it('status com badge como em pacientes e "Ativar" para lente inativa', () => {
        const w = mountTable([lens({ active: false })]);

        expect(w.find('tbody .badge.border-danger').text()).toBe('Inativa');
        expect(w.findAll('.dropdown-item')[1].text()).toBe('Ativar');
    });

    it('mostra o estado vazio ocupando todas as colunas', () => {
        const w = mountTable([]);
        const td = w.find('tbody td');

        expect(td.text()).toBe('Nenhuma lente encontrada.');
        expect(td.attributes('colspan')).toBe('8');
    });
});
