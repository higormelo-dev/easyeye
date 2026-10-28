import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import RoleFormModal from '@/Pages/Panel/AccessControl/Roles/RoleFormModal.vue';

/**
 * Painel de criar/editar perfil: textos por idioma (antes fixos em PT),
 * rótulos acessíveis, marcar/desmarcar grupo e envio para a rota certa.
 */

// useForm reativo (o do setup.js não re-renderiza nem tem clearErrors()).
const forms = vi.hoisted(() => []);
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        useForm: (data) => {
            const initial = { ...data };
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                reset: () => Object.assign(form, { ...initial, permission_ids: [] }),
                clearErrors: () => {
                    form.errors = {};
                },
                post: vi.fn(),
                put: vi.fn(),
            });
            forms.push(form);

            return form;
        },
    };
});

vi.mock('@/Components/Panel/OffcanvasPanel.vue', () => ({
    default: {
        props: ['open', 'width'],
        emits: ['close'],
        template:
            '<div v-if="open" class="offcanvas-stub"><header><slot name="header" /></header><slot /><footer><slot name="footer" /></footer></div>',
    },
}));

const t = {
    form_title_create: 'New profile',
    form_title_edit: 'Edit profile',
    field_name: 'Name',
    field_description: 'Description',
    field_description_hint: 'Optional — explain when to use it',
    field_permissions: 'Permissions',
    no_permissions: 'No permissions available to assign.',
    select_all: 'Select all',
    unselect_all: 'Unselect all',
    btn_cancel: 'Cancel',
    btn_create: 'Create profile',
    btn_save: 'Save changes',
    required: 'required',
};

const routes = { store: '/panel/accesscontrol/roles', update: '/panel/accesscontrol/roles/__ID__' };

const availablePermissions = [
    {
        group: 'Financial',
        items: [
            { id: 'p1', key: 'financial.view', label: 'View financials' },
            { id: 'p2', key: 'financial.manage', label: 'Manage financials' },
        ],
    },
    // Sem PermissionRecord sincronizado (id nulo): o grupo não aparece.
    { group: 'Legacy', items: [{ id: null, key: 'legacy.x', label: 'Legacy' }] },
];

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    forms.length = 0;
});

async function mountModal(props = {}) {
    wrapper = mount(RoleFormModal, {
        props: { open: false, role: null, availablePermissions, routes, t, ...props },
    });
    await wrapper.setProps({ open: true });
    await nextTick();

    return wrapper;
}

describe('AccessControl/Roles/RoleFormModal', () => {
    it('criar: textos traduzidos, rótulos ligados aos campos e envio para store', async () => {
        const w = await mountModal();

        expect(w.get('header').text()).toBe('New profile');
        expect(w.get('label[for="role_name"]').text()).toContain('Name');
        expect(w.get('#role_name').attributes('aria-required')).toBe('true');
        expect(w.get('label[for="role_description"]').text()).toBe('Description');
        expect(w.get('#role_description').attributes('placeholder')).toBe('Optional — explain when to use it');
        expect(w.text()).toContain('Permissions');
        expect(w.text()).not.toContain('Legacy');
        expect(w.get('footer').text()).toContain('Cancel');
        expect(w.get('footer').text()).toContain('Create profile');

        await w.get('#role_name').setValue('Billing');
        await w.findAll('footer button')[1].trigger('click');

        const form = forms.at(-1);
        expect(form.post).toHaveBeenCalledWith(
            '/panel/accesscontrol/roles',
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(form.put).not.toHaveBeenCalled();
    });

    it('editar: preenche com o perfil e envia para a rota do próprio id', async () => {
        const w = await mountModal({
            role: { id: 'r9', name: 'Billing', description: 'Cash', permission_ids: ['p1'] },
        });

        expect(w.get('header').text()).toBe('Edit profile');
        expect(w.get('#role_name').element.value).toBe('Billing');
        expect(w.get('#perm_p1').element.checked).toBe(true);
        expect(w.get('#perm_p2').element.checked).toBe(false);
        expect(w.get('footer').text()).toContain('Save changes');

        await w.findAll('footer button')[1].trigger('click');

        expect(forms.at(-1).put).toHaveBeenCalledWith('/panel/accesscontrol/roles/r9', expect.any(Object));
    });

    it('marcar/desmarcar grupo alterna o texto traduzido e as permissões do grupo', async () => {
        const w = await mountModal();
        const toggle = w.get('button.btn-link');

        expect(toggle.text()).toBe('Select all');
        await toggle.trigger('click');
        expect(forms.at(-1).permission_ids).toEqual(['p1', 'p2']);
        expect(toggle.text()).toBe('Unselect all');

        await toggle.trigger('click');
        expect(forms.at(-1).permission_ids).toEqual([]);
    });

    it('sem permissões atribuíveis: aviso traduzido', async () => {
        const w = await mountModal({ availablePermissions: [] });

        expect(w.text()).toContain('No permissions available to assign.');
    });
});
