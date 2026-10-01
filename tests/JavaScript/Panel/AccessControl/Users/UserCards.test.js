import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import UserCards from '@/Pages/Panel/Users/UserCards.vue';

/**
 * Cards de usuários: mesmo paginator da tabela (sem fetch próprio — antes
 * buscavam `users/cards`, sem tratar erro), linhas rotuladas, selos e as
 * mesmas ações da tabela.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd"><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'variant'],
        emits: ['click'],
        template: '<button type="button" class="action" :title="title" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_email: 'E-mail',
    col_role: 'Role',
    col_created_at: 'Registered',
    badge_owner: 'Owner',
    badge_self: 'You',
    extra_roles_one: '+:count additional profile',
    extra_roles_other: '+:count additional profiles',
    status_active: 'Active',
    status_inactive: 'Inactive',
    status_deleted: 'Deleted',
    btn_edit: 'Edit',
    btn_restore: 'Restore',
};

const row = {
    id: 'u1',
    name: 'BRUNA',
    email: 'bruna@clinica.test',
    rule_label: 'Secretary',
    roles_count: 1,
    active: false,
    deleted: false,
    mode: 'full',
    is_owner: false,
    is_self: true,
    created_at: '2026-09-27T12:00:00-03:00',
    photo_url: '/team.png',
};

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
});

function mountCards(data = [row]) {
    const fetchSpy = vi.fn();
    vi.stubGlobal('fetch', fetchSpy);
    wrapper = mount(UserCards, {
        props: {
            users: { data, total: data.length, last_page: 1, current_page: 1, links: [] },
            t,
            emptyText: 'No users yet.',
        },
    });

    return { w: wrapper, fetchSpy };
}

describe('Users/UserCards', () => {
    it('mostra os dados do paginator sem nenhuma requisição própria', () => {
        const { w, fetchSpy } = mountCards();
        const rows = w
            .findAll('dl > div')
            .map((div) => `${div.get('dt').text()} ${div.get('dd').text().replace(/\s+/g, ' ')}`);

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(w.get('h6').text()).toBe('BRUNA');
        expect(w.text()).toContain('Inactive');
        expect(w.text()).toContain('You');
        expect(rows).toEqual([
            'E-mail: bruna@clinica.test',
            'Role: Secretary · +1 additional profile',
            'Registered: 27/09/2026',
        ]);
    });

    it('mesmas ações da tabela (própria conta: só Editar)', async () => {
        const { w } = mountCards();

        expect(w.findAll('.action').map((b) => b.attributes('title'))).toEqual(['Edit']);
        await w.get('.action').trigger('click');
        expect(w.emitted('edit')[0][0]).toEqual(row);
    });

    it('removido: selo Deleted e Restaurar', async () => {
        const { w } = mountCards([{ ...row, deleted: true, mode: 'restore', is_self: false }]);

        expect(w.text()).toContain('Deleted');
        await w.get('.action').trigger('click');
        expect(w.emitted('restore')).toHaveLength(1);
    });

    it('sem usuários: estado vazio e nenhum card', () => {
        const { w } = mountCards([]);

        expect(w.text()).toContain('No users yet.');
        expect(w.find('.card').exists()).toBe(false);
    });
});
