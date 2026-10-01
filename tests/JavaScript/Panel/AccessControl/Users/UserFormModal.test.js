import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import UserFormModal from '@/Pages/Panel/Users/UserFormModal.vue';

/**
 * Painel de usuário (OffcanvasPanel, como Pacientes/Médicos): textos por
 * idioma (antes "Perfis adicionais" etc. fixos em PT), rótulos ligados aos
 * campos, "ativo" travado na própria conta, erro de carregamento sem botão
 * de salvar e envio encadeado (dados → perfis adicionais).
 */

const state = vi.hoisted(() => ({ forms: [], patch: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    state.patch = vi.fn();

    return {
        router: { patch: (...args) => state.patch(...args) },
        usePage: () => ({ props: {} }),
        useForm: (data) => {
            const initial = { ...data };
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                reset: () => Object.assign(form, initial),
                clearErrors: () => {
                    form.errors = {};
                },
                post: vi.fn(),
                put: vi.fn(),
            });
            state.forms.push(form);

            return form;
        },
    };
});

vi.mock('@/Components/Panel/OffcanvasPanel.vue', () => ({
    default: {
        props: ['open', 'width', 'loading', 'closeLabel'],
        emits: ['close'],
        template: `<div v-if="open" class="panel" :data-close-label="closeLabel">
            <header><slot name="header" /></header>
            <div v-if="loading" class="loading" />
            <template v-else><slot /><footer><slot name="footer" /></footer></template>
        </div>`,
    },
}));
vi.mock('@/Components/Panel/SearchSelect.vue', () => ({
    default: {
        props: ['modelValue', 'options', 'placeholder'],
        template: '<div class="search-select" :data-placeholder="placeholder" />',
    },
}));

const t = {
    form_title_create: 'New user',
    form_title_edit: 'Edit user',
    field_name: 'Full name',
    field_email: 'E-mail',
    field_role: 'Access role',
    field_role_placeholder: 'Select a role',
    field_active: 'Active user',
    field_password: 'Password',
    field_password_confirm: 'Confirm password',
    field_password_hint: 'Min 8 chars.',
    field_extra_roles: 'Additional profiles',
    extra_roles_empty: 'No custom profiles in this clinic yet.',
    extra_roles_hint: 'Additional administrative permissions.',
    credentials_info: 'Credentials info.',
    btn_cancel: 'Cancel',
    btn_save: 'Save changes',
    btn_create: 'Create user',
    close: 'Close',
    required: 'required',
    self_protected: 'You cannot deactivate or remove your own account.',
    js_error_load: 'Error loading user data.',
};

function jsonResponse(body, ok = true) {
    return Promise.resolve({ ok, status: ok ? 200 : 500, json: () => Promise.resolve(body) });
}

let wrapper;

beforeEach(() => {
    state.forms.length = 0;
    state.patch.mockClear();
});
afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
});

async function mountModal(props = {}, fetchImpl = null) {
    vi.stubGlobal(
        'fetch',
        vi.fn(
            fetchImpl ??
                ((url) =>
                    String(url).includes('.edit')
                        ? jsonResponse({ roles: [{ id: 'r1', name: 'Caixa' }], role_ids: ['r1'] })
                        : jsonResponse({
                              data: { name: 'BRUNA', email: 'bruna@clinica.test', rule: 'secretary', active: true },
                          })),
        ),
    );
    wrapper = mount(UserFormModal, {
        props: { open: false, userId: null, roles: { secretary: 'Secretary' }, t, ...props },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
    await nextTick();

    return wrapper;
}

const form = () => state.forms.at(-1);

describe('Users/UserFormModal', () => {
    it('criar: textos traduzidos, rótulos ligados, senha com dica e envio para store', async () => {
        const w = await mountModal();

        expect(w.get('header').text()).toBe('New user');
        expect(w.get('.panel').attributes('data-close-label')).toBe('Close');
        expect(w.get('label[for="ufm_name"]').text()).toContain('Full name');
        expect(w.get('label[for="ufm_password"]').text()).toContain('Password');
        expect(w.get('#ufm_password').attributes('aria-describedby')).toBe('ufm_password_hint');
        expect(w.find('#ufm_active').exists()).toBe(false);
        expect(w.text()).not.toContain('Additional profiles');

        const submit = w.get('footer button[type="submit"]');
        expect(submit.attributes('form')).toBe('user-form');
        expect(submit.text()).toBe('Create user');

        await w.get('form').trigger('submit');
        expect(form().post).toHaveBeenCalledWith('/_routes/panel.accesscontrol.users.store', expect.any(Object));
    });

    it('editar: carrega dados e perfis adicionais traduzidos; salvar encadeia os perfis', async () => {
        const w = await mountModal({ userId: 'u9' });

        expect(w.get('header').text()).toBe('Edit user');
        expect(w.get('#ufm_name').element.value).toBe('BRUNA');
        expect(w.get('legend').text()).toBe('Additional profiles');
        expect(w.get('#ufm_role_r1').element.checked).toBe(true);
        expect(w.text()).toContain('Additional administrative permissions.');

        await w.get('form').trigger('submit');
        const [url, opts] = form().put.mock.calls[0];
        expect(url).toBe('/_routes/panel.accesscontrol.users.update/u9');

        opts.onSuccess();
        expect(state.patch).toHaveBeenCalledWith(
            '/_routes/panel.accesscontrol.users.roles.update/u9',
            { role_ids: ['r1'] },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('própria conta: "ativo" travado com a explicação (o backend recusaria desativar)', async () => {
        const w = await mountModal({ userId: 'u9', lockActive: true });

        expect(w.get('#ufm_active').element.disabled).toBe(true);
        expect(w.get('#ufm_active').attributes('aria-describedby')).toBe('ufm_active_hint');
        expect(w.get('#ufm_active_hint').text()).toBe('You cannot deactivate or remove your own account.');
    });

    it('sem perfis customizados: aviso traduzido', async () => {
        const w = await mountModal({ userId: 'u9' }, (url) =>
            String(url).includes('.edit')
                ? jsonResponse({ roles: [], role_ids: [] })
                : jsonResponse({ data: { name: 'BRUNA', email: 'b@c.test', rule: 'secretary', active: true } }),
        );

        expect(w.text()).toContain('No custom profiles in this clinic yet.');
    });

    it('falha ao carregar: mensagem de erro e sem botão de salvar', async () => {
        const w = await mountModal({ userId: 'u9' }, () => jsonResponse({ message: 'x' }, false));

        expect(w.get('[role="alert"]').text()).toContain('Error loading user data.');
        expect(w.find('form').exists()).toBe(false);
        expect(w.find('footer button[type="submit"]').exists()).toBe(false);
        expect(w.get('footer').text()).toContain('Cancel');
    });
});
