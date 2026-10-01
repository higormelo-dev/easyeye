import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import RoleTable from '@/Pages/Panel/AccessControl/Roles/RoleTable.vue';

/**
 * Tabela de perfis no padrão de PatientTable: cabeçalhos ordenáveis pela
 * whitelist do backend (SortableTh real, padrão nome A→Z), ordem de colunas
 * persistida, contagens com singular/plural, grupos das permissões, data no
 * idioma do usuário, ações e estado vazio.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'variant'],
        emits: ['click'],
        template:
            '<button type="button" class="action" :title="title" :data-variant="variant" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_name: 'Perfil',
    col_permissions: 'Permissões',
    col_users: 'Usuários',
    col_created_at: 'Cadastro',
    col_actions: 'Ações',
    sort_by: 'Ordenar por :column',
    no_description: 'Sem descrição',
    permissions_one: ':count permissão',
    permissions_other: ':count permissões',
    users_one: ':count usuário',
    users_other: ':count usuários',
    action_edit: 'Editar',
    action_delete: 'Excluir',
};

function role(overrides = {}) {
    return {
        id: 'r1',
        name: 'Faturista',
        description: 'Cobrança e caixa',
        permission_ids: ['p1', 'p2', 'p3'],
        permissions: [
            { key: 'financial.view', label: 'Visualizar financeiro', group: 'Financeiro' },
            { key: 'financial.manage', label: 'Gerenciar financeiro', group: 'Financeiro' },
            { key: 'users.manage', label: 'Gerenciar usuários', group: 'Usuários' },
        ],
        users_count: 1,
        created_at: '2026-09-27T12:00:00-03:00',
        ...overrides,
    };
}

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function mountTable(rows = [role()], filters = { sort: 'name', direction: 'asc' }, emptyText = 'Nenhum perfil.') {
    wrapper = mount(RoleTable, {
        props: {
            roles: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [] },
            filters,
            t,
            emptyText,
        },
    });

    return wrapper;
}

const headers = (w) => w.findAll('thead th').map((th) => th.text().trim());

describe('AccessControl/Roles/RoleTable', () => {
    it('todas as colunas de dados são ordenáveis; padrão = nome A→Z (aria-sort)', () => {
        const w = mountTable();

        expect(headers(w)).toEqual(['Perfil', 'Permissões', 'Usuários', 'Cadastro', 'Ações']);
        const ths = w.findAll('thead th');
        expect(ths[0].attributes('aria-sort')).toBe('ascending');
        expect(ths[1].attributes('aria-sort')).toBe('none');
        expect(ths[0].get('button').attributes('title')).toBe('Ordenar por Perfil');
        expect(ths[4].find('button').exists()).toBe(false);
    });

    it('clicar num cabeçalho emite a chave da whitelist; no atual, inverte a direção', async () => {
        const w = mountTable();
        const buttons = w.findAll('thead th button');

        await buttons[2].trigger('click');
        await buttons[0].trigger('click');

        expect(w.emitted('sort')).toEqual([
            [{ sort: 'users_count', direction: 'asc' }],
            [{ sort: 'name', direction: 'desc' }],
        ]);
    });

    it('linha: nome, descrição, contagens no plural certo, grupos sem repetição e data local', () => {
        const w = mountTable();
        const cells = w.findAll('tbody tr td');

        expect(cells[0].text()).toContain('Faturista');
        expect(cells[0].text()).toContain('Cobrança e caixa');
        expect(cells[1].text()).toContain('3 permissões');
        expect(cells[1].text()).toContain('Financeiro, Usuários');
        expect(cells[2].text()).toBe('1 usuário');
        expect(cells[3].text()).toBe('27/09/2026');
    });

    it('sem descrição, sem permissões e sem usuários: textos de apoio no singular/plural corretos', () => {
        const w = mountTable([role({ description: null, permissions: [], permission_ids: [], users_count: 0 })]);
        const cells = w.findAll('tbody tr td');

        expect(cells[0].text()).toContain('Sem descrição');
        expect(cells[1].text()).toBe('0 permissões');
        expect(cells[2].text()).toBe('0 usuários');
    });

    it('nome é texto, nunca HTML interpretado', () => {
        const w = mountTable([role({ name: '<img src=x onerror="alert(1)">' })]);

        expect(w.find('tbody img').exists()).toBe(false);
        expect(w.get('tbody td').text()).toContain('<img src=x onerror="alert(1)">');
    });

    it('editar e excluir emitem o perfil da linha', async () => {
        const row = role();
        const w = mountTable([row]);
        const [edit, remove] = w.findAll('.action');

        expect(edit.attributes('title')).toBe('Editar');
        expect(remove.attributes('data-variant')).toBe('danger');
        await edit.trigger('click');
        await remove.trigger('click');

        expect(w.emitted('edit')[0][0]).toEqual(row);
        expect(w.emitted('delete')[0][0]).toEqual(row);
    });

    it('sem perfis: uma linha com o texto vazio ocupando todas as colunas', () => {
        const w = mountTable([], undefined, 'Nenhum perfil encontrado para esta busca.');
        const cell = w.get('tbody td');

        expect(cell.text()).toBe('Nenhum perfil encontrado para esta busca.');
        expect(cell.attributes('colspan')).toBe('5');
    });

    it('respeita a ordem de colunas salva no navegador', () => {
        window.localStorage.setItem(
            'access_roles_columns_order',
            JSON.stringify(['usuarios', 'nome', 'cadastro', 'permissoes']),
        );
        const w = mountTable();

        expect(headers(w)).toEqual(['Usuários', 'Perfil', 'Cadastro', 'Permissões', 'Ações']);
    });
});
