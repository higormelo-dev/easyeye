import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import UserTable from '@/Pages/Panel/Users/UserTable.vue';

/**
 * Tabela de usuários no padrão de PatientTable: cabeçalhos ordenáveis pela
 * whitelist (padrão cadastro mais recente), ordem de colunas persistida,
 * selos (proprietário/você/perfis adicionais), status, data local,
 * paginação sem v-html e ações por situação (UserActions real).
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: {
        props: ['title'],
        template: '<div class="dd" :data-title="title"><slot name="trigger" /><slot /></div>',
    },
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
    col_name: 'Nome',
    col_email: 'E-mail',
    col_role: 'Perfil',
    col_created_at: 'Cadastro',
    col_status: 'Status',
    col_actions: 'Ações',
    sort_by: 'Ordenar por :column',
    badge_owner: 'Proprietário',
    badge_self: 'Você',
    extra_roles_one: '+:count perfil adicional',
    extra_roles_other: '+:count perfis adicionais',
    status_active: 'Ativo',
    status_inactive: 'Inativo',
    status_deleted: 'Excluído',
    btn_edit: 'Editar',
    btn_restore: 'Restaurar',
    btn_activate: 'Ativar',
    btn_deactivate: 'Desativar',
    btn_delete: 'Excluir',
    more_actions: 'Mais ações',
    owner_locked: 'O proprietário da clínica não pode ser alterado nesta tela.',
    pagination_showing: 'Exibindo',
    pagination_of: 'de',
    pagination_suffix: 'usuários',
};

function user(overrides = {}) {
    return {
        id: 'u1',
        name: 'BRUNA',
        email: 'bruna@clinica.test',
        rule: 'secretary',
        rule_label: 'Secretária',
        roles_count: 2,
        active: true,
        deleted: false,
        mode: 'full',
        is_owner: false,
        is_self: false,
        created_at: '2026-09-27T12:00:00-03:00',
        photo_url: '/team.png',
        ...overrides,
    };
}

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function mountTable(rows = [user()], paginator = {}) {
    wrapper = mount(UserTable, {
        props: {
            users: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [], ...paginator },
            filters: { sort: 'created_at', direction: 'desc' },
            t,
            emptyText: 'Nenhum usuário cadastrado.',
        },
    });

    return wrapper;
}

const headers = (w) => w.findAll('thead th').map((th) => th.text().trim());

describe('Users/UserTable', () => {
    it('colunas na ordem padrão, ordenáveis pela whitelist; padrão = cadastro desc', async () => {
        const w = mountTable();
        const ths = w.findAll('thead th');

        expect(headers(w)).toEqual(['Nome', 'E-mail', 'Perfil', 'Cadastro', 'Status', 'Ações']);
        expect(ths[3].attributes('aria-sort')).toBe('descending');
        expect(ths[0].get('button').attributes('title')).toBe('Ordenar por Nome');

        await ths[2].get('button').trigger('click');
        expect(w.emitted('sort')[0][0]).toEqual({ sort: 'rule', direction: 'asc' });
    });

    it('linha: nome, perfil com perfis adicionais no plural certo, data local e status', () => {
        const w = mountTable();
        const cells = w.findAll('tbody tr td');

        expect(cells[0].text()).toContain('BRUNA');
        expect(cells[2].text()).toContain('Secretária');
        expect(cells[2].text()).toContain('+2 perfis adicionais');
        expect(cells[3].text()).toBe('27/09/2026');
        expect(cells[4].text()).toBe('Ativo');
    });

    it('sem perfis adicionais não mostra o selo; com 1, singular', () => {
        expect(
            mountTable([user({ roles_count: 0 })])
                .findAll('tbody td')[2]
                .text(),
        ).toBe('Secretária');
        wrapper.unmount();
        expect(
            mountTable([user({ roles_count: 1 })])
                .findAll('tbody td')[2]
                .text(),
        ).toContain('+1 perfil adicional');
    });

    it('usuário comum: Editar + menu com Desativar/Excluir, cada ação emite a linha', async () => {
        const row = user();
        const w = mountTable([row]);

        expect(w.get('tbody .dd').attributes('data-title')).toBe('Mais ações');
        const [edit] = w.findAll('.action');
        const [toggle, remove] = w.findAll('.dropdown-item');
        expect(toggle.text()).toBe('Desativar');

        await edit.trigger('click');
        await toggle.trigger('click');
        await remove.trigger('click');

        expect(w.emitted('edit')[0][0]).toEqual(row);
        expect(w.emitted('toggleActive')[0][0]).toEqual(row);
        expect(w.emitted('delete')[0][0]).toEqual(row);
    });

    it('proprietário: selo e cadeado com explicação, sem Editar (o backend recusaria com 403)', () => {
        const w = mountTable([user({ is_owner: true })]);

        expect(w.get('tbody td').text()).toContain('Proprietário');
        expect(w.find('.action').exists()).toBe(false);
        expect(w.find('tbody .dd').exists()).toBe(false);
        expect(w.text()).toContain('O proprietário da clínica não pode ser alterado nesta tela.');
    });

    it('própria conta: selo "Você", Editar sem menu de desativar/excluir', () => {
        const w = mountTable([user({ is_self: true })]);

        expect(w.get('tbody td').text()).toContain('Você');
        expect(w.findAll('.action').map((b) => b.attributes('title'))).toEqual(['Editar']);
        expect(w.find('tbody .dd').exists()).toBe(false);
    });

    it('removido: status Excluído e só Restaurar', async () => {
        const row = user({ deleted: true, mode: 'restore' });
        const w = mountTable([row]);

        expect(w.findAll('tbody td')[4].text()).toBe('Excluído');
        expect(w.findAll('.action').map((b) => b.attributes('title'))).toEqual(['Restaurar']);

        await w.get('.action').trigger('click');
        expect(w.emitted('restore')[0][0]).toEqual(row);
    });

    it('nome é texto, nunca HTML interpretado', () => {
        const w = mountTable([user({ name: '<img src=x onerror="alert(1)">' })]);

        expect(w.findAll('tbody img').length).toBe(1); // só a foto
        expect(w.get('tbody td').text()).toContain('<img src=x onerror="alert(1)">');
    });

    it('sem usuários: linha com o texto vazio ocupando todas as colunas', () => {
        const w = mountTable([]);
        const cell = w.get('tbody td');

        expect(cell.text()).toBe('Nenhum usuário cadastrado.');
        expect(cell.attributes('colspan')).toBe('6');
    });

    it('paginação acessível do TablePagination (sem v-html)', () => {
        const w = mountTable([user()], { last_page: 2, from: 1, to: 12, total: 13 });

        expect(w.text()).toContain('Exibindo');
        expect(w.text()).toContain('usuários');
    });

    it('respeita a ordem de colunas salva no navegador', () => {
        window.localStorage.setItem(
            'access_users_columns_order',
            JSON.stringify(['cadastro', 'nome', 'perfil', 'email']),
        );
        const w = mountTable();

        expect(headers(w)).toEqual(['Cadastro', 'Nome', 'Perfil', 'E-mail', 'Status', 'Ações']);
    });
});
