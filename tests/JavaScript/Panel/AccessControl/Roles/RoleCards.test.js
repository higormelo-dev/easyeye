import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import RoleCards from '@/Pages/Panel/AccessControl/Roles/RoleCards.vue';

/**
 * Cards de perfis no padrão de PatientCards: mesmo paginator da tabela,
 * linhas rotuladas ("Permissões: 2 permissões · Financeiro"), mesmas ações
 * e estado vazio.
 */

vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'variant'],
        emits: ['click'],
        template: '<button type="button" class="action" :title="title" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_permissions: 'Permissions', col_users: 'Users', col_created_at: 'Created', no_description: 'No description',
    permissions_one: ':count permission', permissions_other: ':count permissions',
    users_one: ':count user', users_other: ':count users', action_edit: 'Edit', action_delete: 'Delete',
    pagination_showing: 'Showing', pagination_of: 'of', pagination_suffix: 'profiles',
};

const role = {
    id: 'r1',
    name: 'Billing',
    description: null,
    permissions: [
        { key: 'financial.view', label: 'View financials', group: 'Financial' },
        { key: 'financial.manage', label: 'Manage financials', group: 'Financial' },
    ],
    users_count: 4,
    created_at: '2026-09-27T12:00:00-03:00',
};

let wrapper;

afterEach(() => wrapper?.unmount());

function mountCards(data = [role], extra = {}) {
    wrapper = mount(RoleCards, {
        props: {
            roles: { data, total: data.length, last_page: 1, current_page: 1, links: [], ...extra },
            t,
            emptyText: 'No custom profiles yet.',
        },
    });

    return wrapper;
}

describe('AccessControl/Roles/RoleCards', () => {
    it('mostra nome, descrição de apoio e linhas rotuladas no idioma de `t`', () => {
        const w = mountCards();
        const rows = w.findAll('dl > div').map((row) => (
            `${row.get('dt').text()} ${row.get('dd').text().replace(/\s+/g, ' ')}`
        ));

        expect(w.get('h6').text()).toBe('Billing');
        expect(w.text()).toContain('No description');
        expect(rows).toEqual([
            'Permissions: 2 permissions · Financial',
            'Users: 4 users',
            'Created: 27/09/2026',
        ]);
    });

    it('mesmas ações da tabela: editar e excluir emitem o perfil', async () => {
        const w = mountCards();
        const [edit, remove] = w.findAll('.action');

        await edit.trigger('click');
        await remove.trigger('click');

        expect(w.emitted('edit')[0][0]).toEqual(role);
        expect(w.emitted('delete')[0][0]).toEqual(role);
    });

    it('sem perfis: estado vazio e nenhum card', () => {
        const w = mountCards([]);

        expect(w.text()).toContain('No custom profiles yet.');
        expect(w.find('.card').exists()).toBe(false);
    });

    it('usa a paginação do paginator (mais de uma página mostra o rodapé)', () => {
        const w = mountCards([role], { last_page: 2, from: 1, to: 12, total: 13 });

        expect(w.text()).toContain('Showing');
        expect(w.text()).toContain('profiles');
    });
});
