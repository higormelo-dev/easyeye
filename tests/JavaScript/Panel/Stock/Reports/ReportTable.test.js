import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import ReportTable from '@/Pages/Panel/Stock/Reports/ReportTable.vue';

/**
 * Tabela dos relatórios de estoque no padrão de PatientTable: menu "Colunas"
 * (ordem no navegador, coluna fixa sempre no fim), ordenação via SortableTh
 * só nas colunas da whitelist, estado vazio e células por slot.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd" :data-title="title"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ColumnOrderMenu.vue', () => ({
    default: {
        props: ['columns', 'title', 'labels'],
        emits: ['move', 'reset'],
        template: `<div class="column-menu">
            <span v-for="c in columns" :key="c.key" class="column-menu-item">{{ c.label }}</span>
            <button type="button" class="move-first-down" @click="$emit('move', 0, 1)" />
            <button type="button" class="reset" @click="$emit('reset')" />
        </div>`,
    },
}));

const STORAGE_KEY = 'stock_reports_test_columns_order';

const t = {
    sort_by: 'Ordenar por :column', columns_label: 'Colunas', columns_customize: 'Personalizar colunas',
};

const columns = [
    { key: 'name', label: 'Produto', sortable: true },
    { key: 'qty', label: 'Saldo', sortable: true, align: 'end' },
    { key: 'note', label: 'Observação' },
    { key: 'abc', label: 'Classe', align: 'center', fixed: true },
];

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function mountTable(props = {}, slots = {}) {
    wrapper = mount(ReportTable, {
        props: {
            rows: [{ id: 'p1', name: 'Lente', qty: 3, note: null, abc: 'A' }],
            columns,
            sort: 'qty',
            direction: 'desc',
            storageKey: STORAGE_KEY,
            emptyText: 'Nada por aqui.',
            t,
            ...props,
        },
        slots,
    });

    return wrapper;
}

const headerLabels = (w) => w.findAll('thead th').map((th) => th.text());

describe('ReportTable', () => {
    it('mostra as colunas na ordem padrão com a coluna fixa por último e o botão "Colunas"', () => {
        const w = mountTable();

        expect(headerLabels(w)).toEqual(['Produto', 'Saldo', 'Observação', 'Classe']);
        expect(w.find('.dd').text()).toContain('Colunas');
        expect(w.find('.dd').attributes('data-title')).toBe('Personalizar colunas');
        // O menu só reordena as colunas de conteúdo, não a fixa.
        expect(w.findAll('.column-menu-item').map((i) => i.text())).toEqual(['Produto', 'Saldo', 'Observação']);
    });

    it('respeita a ordem salva no navegador e ignora ordem salva inválida', () => {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(['note', 'name', 'qty']));
        expect(headerLabels(mountTable())).toEqual(['Observação', 'Produto', 'Saldo', 'Classe']);
        wrapper.unmount();

        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(['note', 'abc']));
        expect(headerLabels(mountTable())).toEqual(['Produto', 'Saldo', 'Observação', 'Classe']);
    });

    it('reordenar pelo menu atualiza o cabeçalho e guarda a preferência', async () => {
        const w = mountTable();

        await w.find('.move-first-down').trigger('click');
        await nextTick();

        expect(headerLabels(w)).toEqual(['Saldo', 'Produto', 'Observação', 'Classe']);
        expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY))).toEqual(['qty', 'name', 'note']);

        await w.find('.reset').trigger('click');
        expect(headerLabels(w)).toEqual(['Produto', 'Saldo', 'Observação', 'Classe']);
    });

    it('só colunas da whitelist são ordenáveis (SortableTh com aria-sort e dica traduzida)', () => {
        const w = mountTable();
        const ths = w.findAll('thead th');

        expect(ths[0].attributes('aria-sort')).toBe('none');
        expect(ths[1].attributes('aria-sort')).toBe('descending');
        expect(ths[1].classes()).toContain('text-end');
        expect(ths[0].find('button').attributes('title')).toBe('Ordenar por Produto');
        expect(ths[2].find('button').exists()).toBe(false);
        expect(ths[2].attributes('aria-sort')).toBeUndefined();
        expect(ths[3].find('button').exists()).toBe(false);
    });

    it('emite a ordenação: inverte a coluna atual e começa ascendente nas outras', async () => {
        const w = mountTable();
        const ths = w.findAll('thead th');

        await ths[1].find('button').trigger('click');
        await ths[0].find('button').trigger('click');

        expect(w.emitted('sort')).toEqual([
            [{ sort: 'qty', direction: 'asc' }],
            [{ sort: 'name', direction: 'asc' }],
        ]);
    });

    it('renderiza células por slot e usa travessão para valor vazio', () => {
        const w = mountTable({}, { 'cell-qty': '<template #cell-qty="{ row }"><b class="qty">{{ row.qty }} un</b></template>' });
        const cells = w.findAll('tbody td');

        expect(cells[0].text()).toBe('Lente');
        expect(w.find('.qty').text()).toBe('3 un');
        expect(cells[1].classes()).toContain('text-end');
        expect(cells[2].text()).toBe('—');
    });

    it('mostra o estado vazio ocupando todas as colunas', () => {
        const w = mountTable({ rows: [], emptyIcon: 'ti ti-package-off' });
        const cell = w.find('tbody td');

        expect(cell.attributes('colspan')).toBe('4');
        expect(cell.text()).toBe('Nada por aqui.');
        expect(cell.find('i.ti-package-off').attributes('aria-hidden')).toBe('true');
    });
});
