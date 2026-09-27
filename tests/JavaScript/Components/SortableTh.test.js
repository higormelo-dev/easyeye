import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SortableTh from '@/Components/Panel/SortableTh.vue';

function mountTh(props = {}) {
    return mount({
        components: { SortableTh },
        data: () => ({ props }),
        template: '<table><thead><tr><SortableTh v-bind="props" class="text-end">Nome</SortableTh></tr></thead></table>',
    });
}

describe('SortableTh', () => {
    it('é acionável por teclado (botão dentro do th) e mantém classes do th', () => {
        const w = mountTh({ colKey: 'name' });

        expect(w.find('th').classes()).toContain('text-end');
        expect(w.find('th button[type="button"]').text()).toContain('Nome');
    });

    it('expõe aria-sort conforme a coluna/direção atual', () => {
        expect(mountTh({ colKey: 'name', currentSort: 'code' }).find('th').attributes('aria-sort')).toBe('none');
        expect(mountTh({ colKey: 'name', currentSort: 'name', currentDir: 'asc' }).find('th').attributes('aria-sort')).toBe('ascending');
        expect(mountTh({ colKey: 'name', currentSort: 'name', currentDir: 'desc' }).find('th').attributes('aria-sort')).toBe('descending');
    });

    it('inverte a direção da coluna atual e começa ascendente nas outras', async () => {
        const current = mount(SortableTh, { props: { colKey: 'name', currentSort: 'name', currentDir: 'asc' } });
        await current.find('button').trigger('click');
        expect(current.emitted('sort')[0][0]).toEqual({ sort: 'name', direction: 'desc' });

        const other = mount(SortableTh, { props: { colKey: 'code', currentSort: 'name', currentDir: 'desc' } });
        await other.find('button').trigger('click');
        expect(other.emitted('sort')[0][0]).toEqual({ sort: 'code', direction: 'asc' });
    });

    it('aceita dica traduzida', () => {
        const w = mount(SortableTh, { props: { colKey: 'name', title: 'Sort by Name' } });

        expect(w.find('button').attributes('title')).toBe('Sort by Name');
    });
});
