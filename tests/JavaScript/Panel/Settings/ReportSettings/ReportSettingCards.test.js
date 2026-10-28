import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ReportSettingCards from '@/Pages/Panel/Settings/ReportSettings/ReportSettingCards.vue';

/**
 * Cards de modelos: mesmo paginator da tabela, linhas rotuladas (blocos por
 * extenso, data local), selos de status/origem e as mesmas ações.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd"><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'variant', 'href', 'target', 'inertiaHref'],
        emits: ['click'],
        template: '<button type="button" class="action" :title="title" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_category: 'Category',
    col_paper: 'Paper',
    col_blocks: 'Blocks',
    col_updated_at: 'Updated',
    no_description: 'No description',
    block_header: 'Header',
    block_signature: 'Signature',
    block_footer: 'Footer',
    block_on: ':block: included',
    block_off: ':block: not included',
    origin_adopted: 'Adopted',
    update_available: 'Update available',
    status_active: 'Active',
    status_inactive: 'Inactive',
    action_preview: 'Preview',
    action_edit: 'Edit',
    action_reimport: 'Re-import global template',
    action_delete: 'Delete',
    pagination_showing: 'Showing',
    pagination_of: 'of',
    pagination_suffix: 'templates',
};

const row = {
    id: 'r1',
    title: 'Prescription',
    description: null,
    category: null,
    paper_size: 'A5',
    show_header: true,
    show_signature: true,
    show_footer: false,
    active: false,
    is_adopted: true,
    has_update: true,
    updated_at: '2026-09-27T12:00:00-03:00',
    mode: 'full',
    preview_url: '/p',
    edit_url: '/e',
    destroy_url: '/d',
    reimport_url: '/r',
};

let wrapper;

afterEach(() => wrapper?.unmount());

function mountCards(data = [row], extra = {}) {
    wrapper = mount(ReportSettingCards, {
        props: {
            items: { data, total: data.length, last_page: 1, current_page: 1, links: [], ...extra },
            t,
            emptyText: 'No templates yet.',
        },
    });

    return wrapper;
}

describe('Settings/ReportSettings/ReportSettingCards', () => {
    it('título, selos e linhas rotuladas no idioma de `t`', () => {
        const w = mountCards();
        const rows = w.findAll('dl > div').map((div) => `${div.get('dt').text()} ${div.get('dd').text()}`);

        expect(w.get('h6').text()).toBe('Prescription');
        expect(w.text()).toContain('Inactive');
        expect(w.text()).toContain('Adopted');
        expect(w.text()).toContain('Update available');
        expect(w.text()).toContain('No description');
        expect(rows).toEqual(['Category: —', 'Paper: A5', 'Blocks: Header, Signature', 'Updated: 27/09/2026']);
    });

    it('nenhum bloco incluído mostra —', () => {
        const w = mountCards([{ ...row, show_header: false, show_signature: false }]);

        expect(w.findAll('dl > div')[2].get('dd').text()).toBe('—');
    });

    it('mesmas ações da tabela', async () => {
        const w = mountCards();

        expect(w.findAll('.action').map((b) => b.attributes('title'))).toEqual(['Preview', 'Edit']);
        const [reimport, remove] = w.findAll('.dropdown-item');
        await reimport.trigger('click');
        await remove.trigger('click');

        expect(w.emitted('reimport')[0][0]).toEqual(row);
        expect(w.emitted('delete')[0][0]).toEqual(row);
    });

    it('sem modelos: estado vazio; com mais de uma página, rodapé de paginação', () => {
        expect(mountCards([]).text()).toContain('No templates yet.');
        wrapper.unmount();

        const w = mountCards([row], { last_page: 2, from: 1, to: 12, total: 13 });
        expect(w.text()).toContain('Showing');
        expect(w.text()).toContain('templates');
    });
});
