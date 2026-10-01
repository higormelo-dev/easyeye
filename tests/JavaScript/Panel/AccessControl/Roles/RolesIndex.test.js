import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import RolesIndex from '@/Pages/Panel/AccessControl/Roles/Index.vue';

/**
 * Perfis de acesso no layout de Panel/Patients/Index: tabela como padrão,
 * alternância tabela/cards persistida, textos via `t`, busca server-side
 * com debounce que preserva a ordenação, flash de retorno visível, perfis
 * do sistema numa seção recolhível e exclusão com confirmação traduzida.
 */

// usePage reativo (o mock global de setup.js devolve props fixas): o alerta
// de flash precisa reagir a uma visita nova.
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
        Link: { template: '<a><slot /></a>', props: ['href'] },
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
vi.mock('@/Pages/Panel/AccessControl/Roles/RoleTable.vue', () => ({
    default: {
        props: ['roles', 'filters', 't', 'emptyText'],
        emits: ['sort', 'edit', 'delete'],
        template: `<div class="table-stub" :data-empty="emptyText">{{ roles.data.length }}
            <button class="sort" @click="$emit('sort', { sort: 'users_count', direction: 'desc' })" />
            <button class="edit-row" @click="$emit('edit', roles.data[0])" />
            <button class="delete-row" @click="$emit('delete', roles.data[0])" />
            <button class="delete-row-2" @click="$emit('delete', roles.data[1])" />
        </div>`,
    },
}));
vi.mock('@/Pages/Panel/AccessControl/Roles/RoleCards.vue', () => ({
    default: {
        props: ['roles', 't', 'emptyText'],
        template: '<div class="cards-stub">{{ roles.data.map((r) => r.name).join(",") }}</div>',
    },
}));
vi.mock('@/Pages/Panel/AccessControl/Roles/RoleFormModal.vue', () => ({
    default: {
        props: ['open', 'role', 't'],
        template:
            '<div class="modal-stub" :data-open="open" :data-role="role?.name ?? \'\'" :data-title="open ? (role ? t.form_title_edit : t.form_title_create) : \'\'" />',
    },
}));

const t = {
    page_title: 'Access profiles',
    total_label: 'Total:',
    btn_new: 'New profile',
    search_placeholder: 'Search profiles by name or description...',
    search_clear: 'Clear search',
    close: 'Close',
    system_profiles_title: 'System profiles',
    system_profiles_count: ':count predefined by the platform',
    system_profile_badge: 'Default',
    notice: 'System profiles are defined by the platform.',
    confirm_delete: 'Delete the profile ":name"?',
    confirm_delete_with_users: 'Delete the profile ":name"? :count user(s) will lose these additional permissions.',
    empty_list: 'No custom profiles yet.',
    empty_search: 'No profiles match this search.',
    form_title_create: 'New profile',
    form_title_edit: 'Edit profile',
};

const routes = {
    index: '/panel/accesscontrol/roles',
    store: '/panel/accesscontrol/roles',
    update: '/panel/accesscontrol/roles/__ID__',
    destroy: '/panel/accesscontrol/roles/__ID__',
};

const baseFilters = { search: '', sort: 'name', direction: 'asc' };

const roles = {
    data: [
        { id: 'r1', name: 'Faturista', description: null, permissions: [], permission_ids: [], users_count: 0 },
        { id: 'r2', name: 'Recepção', description: 'Balcão', permissions: [], permission_ids: [], users_count: 3 },
    ],
    total: 14,
};

const systemProfiles = [
    { value: 'admin', label: 'Administrador', description: 'Acesso total.' },
    { value: 'secretary', label: 'Secretária', description: 'Agenda e recepção.' },
];

let wrapper;

beforeEach(() => {
    inertia.pageProps.flash = {};
    window.localStorage.clear();
    vi.mocked(router.get).mockClear();
    vi.mocked(router.delete).mockClear();
});
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

function mountPage(props = {}) {
    wrapper = mount(RolesIndex, {
        props: { roles, filters: baseFilters, systemProfiles, availablePermissions: [], routes, t, ...props },
    });

    return wrapper;
}

describe('AccessControl/Roles/Index', () => {
    it('usa os textos traduzidos e o total do paginator (não mais perfis do sistema + customizados)', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Access profiles');
        expect(w.find('.total').text()).toBe('Total: 14');
        expect(w.text()).toContain('New profile');
        expect(w.find('.search').attributes('placeholder')).toBe('Search profiles by name or description...');
        expect(w.find('.search').attributes('data-clear-label')).toBe('Clear search');
    });

    it('perfis do sistema ficam numa seção recolhível, somente leitura, com contagem e aviso', () => {
        const w = mountPage();

        const details = w.get('details.system-profiles');
        expect(details.attributes('open')).toBeUndefined();
        expect(details.get('summary').text()).toContain('System profiles');
        expect(details.get('summary').text()).toContain('2 predefined by the platform');
        expect(details.text()).toContain('System profiles are defined by the platform.');
        expect(details.findAll('li').map((li) => li.text())).toEqual([
            expect.stringContaining('Administrador'),
            expect.stringContaining('Secretária'),
        ]);
        expect(details.find('button').exists()).toBe(false);
    });

    it('sem perfis do sistema, a seção não aparece', () => {
        const w = mountPage({ systemProfiles: [] });

        expect(w.find('details.system-profiles').exists()).toBe(false);
    });

    it('mostra o flash de retorno (message) e o fecha com estado local; um flash novo reaparece', async () => {
        inertia.pageProps.flash = { message: 'Access profile updated successfully.' };
        const w = mountPage();

        const alert = w.get('[role="status"]');
        expect(alert.text()).toContain('Access profile updated successfully.');
        const close = alert.get('button[aria-label="Close"]');
        expect(close.attributes('data-bs-dismiss')).toBeUndefined();

        await close.trigger('click');
        expect(w.find('[role="status"]').exists()).toBe(false);

        inertia.pageProps.flash = { message: 'Access profile updated successfully.' };
        await nextTick();
        expect(w.get('[role="status"]').text()).toContain('Access profile updated successfully.');
    });

    it('abre em tabela por padrão e alterna para cards (mesmo paginator), guardando a preferência', async () => {
        const w = mountPage();

        expect(w.find('.table-stub').exists()).toBe(true);
        await w.find('.to-cards').trigger('click');

        expect(w.find('.cards-stub').text()).toBe('Faturista,Recepção');
        expect(window.localStorage.getItem('access_roles_view')).toBe('cards');
    });

    it('preferência "cards" salva abre direto em cards', () => {
        window.localStorage.setItem('access_roles_view', 'cards');
        const w = mountPage();

        expect(w.find('.cards-stub').exists()).toBe(true);
        expect(w.find('.table-stub').exists()).toBe(false);
    });

    it('a busca espera parar de digitar, volta à página 1 e preserva a ordenação', async () => {
        vi.useFakeTimers();
        const w = mountPage({ filters: { search: '', sort: 'users_count', direction: 'desc' } });

        await w.find('.search').setValue('recep');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        await nextTick();

        expect(router.get).toHaveBeenCalledTimes(1);
        expect(router.get).toHaveBeenCalledWith(
            '/panel/accesscontrol/roles',
            { search: 'recep', sort: 'users_count', direction: 'desc' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('ordenar pela tabela mantém a busca', async () => {
        const w = mountPage({ filters: { ...baseFilters, search: 'fat' } });

        await w.find('.sort').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/panel/accesscontrol/roles',
            { search: 'fat', sort: 'users_count', direction: 'desc' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('estado vazio distingue "nenhum cadastrado" de "nada encontrado na busca"', () => {
        expect(
            mountPage({ roles: { data: [], total: 0 } })
                .get('.table-stub')
                .attributes('data-empty'),
        ).toBe('No custom profiles yet.');
        wrapper.unmount();

        expect(
            mountPage({ roles: { data: [], total: 0 }, filters: { ...baseFilters, search: 'x' } })
                .get('.table-stub')
                .attributes('data-empty'),
        ).toBe('No profiles match this search.');
    });

    it('excluir perfil com usuários avisa quantos perdem as permissões; confirmado, apaga pela rota', async () => {
        const confirm = vi.fn(() => true);
        vi.stubGlobal('confirm', confirm);
        const w = mountPage();

        await w.find('.delete-row-2').trigger('click');

        expect(confirm).toHaveBeenCalledWith(
            'Delete the profile "Recepção"? 3 user(s) will lose these additional permissions.',
        );
        expect(router.delete).toHaveBeenCalledWith('/panel/accesscontrol/roles/r2', { preserveScroll: true });
    });

    it('excluir perfil sem usuários usa a confirmação simples; cancelado, nada é apagado', async () => {
        const confirm = vi.fn(() => false);
        vi.stubGlobal('confirm', confirm);
        const w = mountPage();

        await w.find('.delete-row').trigger('click');

        expect(confirm).toHaveBeenCalledWith('Delete the profile "Faturista"?');
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('novo e editar abrem o painel com os textos traduzidos', async () => {
        const w = mountPage();

        await w.find('button.btn-primary').trigger('click');
        expect(w.get('.modal-stub').attributes('data-open')).toBe('true');
        expect(w.get('.modal-stub').attributes('data-title')).toBe('New profile');

        await w.find('.edit-row').trigger('click');
        expect(w.get('.modal-stub').attributes('data-role')).toBe('Faturista');
        expect(w.get('.modal-stub').attributes('data-title')).toBe('Edit profile');
    });
});
