import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import UsersIndex from '@/Pages/Panel/Users/Index.vue';

/**
 * Usuários no layout de Panel/Patients/Index: total do paginator, textos
 * via `t`, alternância tabela/cards persistida (mesma chave de antes), busca
 * server-side que preserva a ordenação, flash visível e ações com
 * confirmação traduzida — restaurar por PATCH (antes GET, sem CSRF).
 */

const inertia = vi.hoisted(() => ({ pageProps: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.pageProps = reactive({ flash: {}, errors: {} });

    return {
        usePage: () => ({ props: inertia.pageProps }),
        router: {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            delete: vi.fn(),
            reload: vi.fn(),
            visit: vi.fn(),
        },
        Link: { template: '<a class="link" :href="href"><slot /></a>', props: ['href'] },
        Head: { template: '<div><slot /></div>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'totalLabel', 'view'],
        emits: ['set-view'],
        template: `<div>
            <span class="total">{{ totalLabel }} {{ total }}</span>
            <button class="to-cards" @click="$emit('set-view', 'cards')" />
            <slot name="actions" />
        </div>`,
    },
}));
vi.mock('@/Components/Panel/SearchInput.vue', () => ({
    default: {
        props: ['modelValue', 'placeholder', 'clearLabel', 'maxWidth'],
        emits: ['update:modelValue'],
        template:
            '<input class="search" :placeholder="placeholder" :data-clear-label="clearLabel" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
    },
}));
vi.mock('@/Pages/Panel/Users/UserTable.vue', () => ({
    default: {
        props: ['users', 'filters', 't', 'emptyText'],
        emits: ['sort', 'edit', 'delete', 'restore', 'toggleActive'],
        template: `<div class="table-stub" :data-empty="emptyText">
            <button class="sort" @click="$emit('sort', { sort: 'name', direction: 'asc' })" />
            <button class="edit-self" @click="$emit('edit', users.data[1])" />
            <button class="edit-other" @click="$emit('edit', users.data[0])" />
            <button class="delete-row" @click="$emit('delete', users.data[0])" />
            <button class="restore-row" @click="$emit('restore', users.data[2])" />
            <button class="toggle-row" @click="$emit('toggleActive', users.data[0])" />
        </div>`,
    },
}));
vi.mock('@/Pages/Panel/Users/UserCards.vue', () => ({
    default: {
        props: ['users', 't', 'emptyText'],
        template: '<div class="cards-stub">{{ users.data.map((u) => u.name).join(",") }}</div>',
    },
}));
vi.mock('@/Pages/Panel/Users/UserFormModal.vue', () => ({
    default: {
        props: ['open', 'userId', 'lockActive', 't'],
        template: '<div class="modal-stub" :data-open="open" :data-user="userId ?? \'\'" :data-lock="lockActive" />',
    },
}));

// Convite a quem já usa o EasyEye (painel e pendentes têm teste próprio:
// tests/JavaScript/Panel/Users/UserInvitation.test.js).
vi.mock('@/Pages/Panel/Users/UserInviteModal.vue', () => ({
    default: { props: ['open', 'roles', 't'], template: '<div class="invite-stub" :data-open="open" />' },
}));
vi.mock('@/Pages/Panel/Users/UserInvitationsPending.vue', () => ({
    default: {
        props: ['invitations', 't'],
        template: '<div class="pending-stub">{{ invitations.map((i) => i.email).join(",") }}</div>',
    },
}));

const t = {
    page_title: 'Users',
    total_label: 'Total:',
    new_user: 'New user',
    roles_link: 'Roles & Permissions',
    search_placeholder: 'Search by name or e-mail…',
    search_clear: 'Clear search',
    close: 'Close',
    empty: 'No users yet.',
    empty_search: 'No users match this search.',
    confirm_delete: 'Remove ":name"\'s access to this clinic?',
    confirm_restore: 'Restore ":name"\'s access to this clinic?',
};

const users = {
    data: [
        { id: 'u1', name: 'BRUNA', active: true, is_self: false, mode: 'full' },
        { id: 'u2', name: 'ANA', active: true, is_self: true, mode: 'full' },
        { id: 'u3', name: 'CARLA', active: true, is_self: false, mode: 'restore', deleted: true },
    ],
    total: 27,
};

const baseFilters = { search: '', sort: 'created_at', direction: 'desc' };

let wrapper;

beforeEach(() => {
    inertia.pageProps.flash = {};
    window.localStorage.clear();
    Object.values(router).forEach((fn) => fn.mockClear?.());
});
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

function mountPage(props = {}) {
    wrapper = mount(UsersIndex, {
        props: { users, filters: baseFilters, roles: {}, t, ...props },
        // route() no template vem do Ziggy (@routes/ZiggyVue) em produção.
        global: { mocks: { route: globalThis.route } },
    });

    return wrapper;
}

describe('Users/Index', () => {
    it('usa os textos traduzidos, o total do paginator e o atalho para perfis', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Users');
        expect(w.find('.total').text()).toBe('Total: 27');
        expect(w.text()).toContain('New user');
        expect(w.get('.link').text()).toBe('Roles & Permissions');
        expect(w.get('.link').attributes('href')).toBe('/_routes/panel.accesscontrol.roles.index');
        expect(w.find('.search').attributes('placeholder')).toBe('Search by name or e-mail…');
        expect(w.find('.search').attributes('data-clear-label')).toBe('Clear search');
    });

    it('mostra o flash de retorno (message), que antes sumia', async () => {
        inertia.pageProps.flash = { message: 'User deactivated successfully.' };
        const w = mountPage();

        expect(w.get('[role="status"]').text()).toContain('User deactivated successfully.');
        await w.get('[role="status"] button[aria-label="Close"]').trigger('click');
        expect(w.find('[role="status"]').exists()).toBe(false);

        inertia.pageProps.flash = { message: 'User deactivated successfully.' };
        await nextTick();
        expect(w.find('[role="status"]').exists()).toBe(true);
    });

    it('cards usam o mesmo paginator e a preferência continua na chave users_view', async () => {
        const w = mountPage();

        expect(w.find('.table-stub').exists()).toBe(true);
        await w.find('.to-cards').trigger('click');

        expect(w.find('.cards-stub').text()).toBe('BRUNA,ANA,CARLA');
        expect(window.localStorage.getItem('users_view')).toBe('cards');
    });

    it('a busca espera parar de digitar e preserva a ordenação', async () => {
        vi.useFakeTimers();
        const w = mountPage({ filters: { search: '', sort: 'name', direction: 'asc' } });

        await w.find('.search').setValue('ana');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        await nextTick();

        expect(router.get).toHaveBeenCalledWith(
            '/_routes/panel.accesscontrol.users.index',
            { search: 'ana', sort: 'name', direction: 'asc' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('ordenar mantém a busca', async () => {
        const w = mountPage({ filters: { ...baseFilters, search: 'bru' } });

        await w.find('.sort').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/_routes/panel.accesscontrol.users.index',
            { search: 'bru', sort: 'name', direction: 'asc' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('estado vazio distingue "nenhum cadastrado" de "nada encontrado na busca"', () => {
        expect(
            mountPage({ users: { data: [], total: 0 } })
                .get('.table-stub')
                .attributes('data-empty'),
        ).toBe('No users yet.');
        wrapper.unmount();

        expect(
            mountPage({ users: { data: [], total: 0 }, filters: { ...baseFilters, search: 'x' } })
                .get('.table-stub')
                .attributes('data-empty'),
        ).toBe('No users match this search.');
    });

    it('excluir confirma com o nome; cancelado, nada acontece; confirmado, DELETE na rota do usuário', async () => {
        const confirm = vi.fn(() => false);
        vi.stubGlobal('confirm', confirm);
        const w = mountPage();

        await w.find('.delete-row').trigger('click');
        expect(confirm).toHaveBeenCalledWith('Remove "BRUNA"\'s access to this clinic?');
        expect(router.delete).not.toHaveBeenCalled();

        confirm.mockReturnValue(true);
        await w.find('.delete-row').trigger('click');
        expect(router.delete).toHaveBeenCalledWith('/_routes/panel.accesscontrol.users.destroy/u1', {
            preserveScroll: true,
        });
    });

    it('restaurar confirma com o nome e usa PATCH (não GET)', async () => {
        vi.stubGlobal(
            'confirm',
            vi.fn(() => true),
        );
        const w = mountPage();

        await w.find('.restore-row').trigger('click');

        expect(router.patch).toHaveBeenCalledWith(
            '/_routes/panel.accesscontrol.users.restore/u3',
            {},
            { preserveScroll: true },
        );
        expect(router.get).not.toHaveBeenCalled();
    });

    it('ativar/desativar envia o status invertido pelo update', async () => {
        const w = mountPage();

        await w.find('.toggle-row').trigger('click');

        expect(router.put).toHaveBeenCalledWith(
            '/_routes/panel.accesscontrol.users.update/u1',
            { active: false, type_method: 'toggle' },
            { preserveScroll: true },
        );
    });

    it('editar a própria conta trava o "ativo" no painel; outro usuário, não', async () => {
        const w = mountPage();

        await w.find('.edit-self').trigger('click');
        expect(w.get('.modal-stub').attributes('data-user')).toBe('u2');
        expect(w.get('.modal-stub').attributes('data-lock')).toBe('true');

        await w.find('.edit-other').trigger('click');
        expect(w.get('.modal-stub').attributes('data-user')).toBe('u1');
        expect(w.get('.modal-stub').attributes('data-lock')).toBe('false');
    });

    it('convidar quem já usa o EasyEye: botão só com perfis convidáveis; abre o painel; lista os pendentes', async () => {
        mountPage();
        expect(wrapper.text()).not.toContain('Invite existing user');
        expect(wrapper.find('.pending-stub').exists()).toBe(false);
        wrapper.unmount();

        mountPage({
            t: { ...t, invitation: { button: 'Invite existing user' } },
            invitableRoles: { financial: 'Financial' },
            pendingInvitations: [{ id: 'i1', email: 'maria@example.com' }],
        });

        const button = wrapper.findAll('button').find((b) => b.text() === 'Invite existing user');
        expect(button).toBeTruthy();
        expect(wrapper.find('.pending-stub').text()).toBe('maria@example.com');

        await button.trigger('click');
        expect(wrapper.find('.invite-stub').attributes('data-open')).toBe('true');
    });
});
