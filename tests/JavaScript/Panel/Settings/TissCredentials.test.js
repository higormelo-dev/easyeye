import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

/**
 * Financeiro > Credenciais TISS: aviso de transporte simulado × real, situação de cada credencial (pronta,
 * incompleta, sem autenticação, certificado vencido), segredos só de escrita no formulário e ações por linha.
 */
const inertia = vi.hoisted(() => ({ forms: [] }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        usePage: () => ({ props: { t_ui: { close: 'Fechar' }, errors: {} } }),
        router: { post: vi.fn(), delete: vi.fn() },
        useForm: (initial) => {
            let defaults = structuredClone(initial);
            const form = reactive({
                ...structuredClone(initial),
                errors: {},
                processing: false,
                isDirty: false,
                defaults(values) {
                    defaults = structuredClone(values);
                },
                reset() {
                    Object.assign(form, structuredClone(defaults));
                },
                clearErrors() {
                    form.errors = {};
                },
                post: vi.fn(),
            });
            inertia.forms.push(form);

            return form;
        },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'subtitle', 'titleTag'],
        template: '<header><h1>{{ title }}</h1><slot name="actions" /></header>',
    },
}));
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open', 'size', 'density'],
        emits: ['close'],
        template:
            '<div v-if="open" class="modal-stub" :data-density="density"><slot name="header" /><slot /><slot name="footer" /></div>',
    },
}));
vi.mock('@/Components/Panel/SearchSelect.vue', () => ({
    default: {
        props: ['modelValue', 'remoteSearchUrl'],
        template: '<div class="search-stub" :data-url="remoteSearchUrl" />',
    },
}));

const Page = (await import('@/Pages/Panel/Settings/TissCredentials/Index.vue')).default;
const { router } = await import('@inertiajs/vue3');

const t = {
    title: 'Credenciais TISS',
    new: 'Nova credencial',
    empty: 'Nenhuma credencial cadastrada.',
    transport: { simulated: 'Simulação (:driver): protocolo SIMULADO-', real: 'Envio real: ambiente :environment' },
    environments: { sandbox: 'Homologação', production: 'Produção' },
    missing: { endpoint: 'endereço do webservice', review: 'revisão de um administrador' },
    columns: { operator: 'Operadora' },
    status: { ready: 'Pronta', incomplete: 'Incompleta: falta :missing', inactive: 'Inativa', ans: 'ANS :code' },
    auth: {
        login: 'Usuário e senha',
        token: 'Token',
        certificate: 'Certificado',
        valid_to: 'válido até :date',
        expired: 'vencido em :date',
        no_auth: 'Sem autenticação',
    },
    form: {
        title_new: 'Nova credencial TISS',
        title_edit: 'Editar credencial TISS',
        password: 'Senha',
        token: 'Token de acesso',
        secret_saved: 'Salvo. Deixe em branco para manter.',
        secret_not_saved: 'Nada salvo.',
        remove_password: 'Apagar a senha salva',
        save: 'Salvar',
        cancel: 'Cancelar',
    },
    actions: {
        test: 'Testar conexão',
        testing: 'Testando…',
        edit: 'Editar',
        delete: 'Remover',
        confirm_delete: 'Remover :operator (:environment)?',
    },
    test: { never: 'Nunca testada', at: ':result em :date' },
};

const credential = (extra = {}) => ({
    id: 'c1',
    operator: 'UNIMED TESTE',
    ans_code: '326305',
    environment: 'sandbox',
    endpoint_submission: 'https://homolog.operadora.test/tiss',
    endpoint_status: null,
    effective_endpoint: 'https://homolog.operadora.test/tiss',
    username: 'prestador01',
    has_password: true,
    has_token: false,
    has_certificate: false,
    has_authentication: true,
    certificate: null,
    ssl_enabled: true,
    active: true,
    missing: [],
    placeholder: false,
    last_test: null,
    urls: { update: '/cred/c1', destroy: '/cred/c1', test: '/cred/c1/test' },
    ...extra,
});

function mountPage(credentials, transport = { simulated: true, driver: 'mock', environment: 'production' }) {
    return mount(Page, {
        props: {
            credentials,
            environments: ['sandbox', 'production'],
            transport,
            routes: { store: '/cred', operators: '/cred/operators?q=__Q__' },
            t,
        },
    });
}

describe('Panel/Settings/TissCredentials', () => {
    beforeEach(() => {
        inertia.forms.length = 0;
        vi.clearAllMocks();
    });

    it('região de densidade na raiz e aviso de simulação com o driver; envio real mostra o ambiente', () => {
        const simulated = mountPage([]);

        expect(simulated.find('[data-ee-density="default"]').exists()).toBe(true);
        expect(simulated.get('[data-test="tiss-cred-transport"]').classes()).toContain('alert-warning');
        expect(simulated.get('[data-test="tiss-cred-transport"]').text()).toContain(
            'Simulação (mock): protocolo SIMULADO-',
        );
        expect(simulated.find('[data-test="tiss-cred-empty"]').exists()).toBe(true);

        const real = mountPage([], { simulated: false, driver: 'http', environment: 'sandbox' });

        expect(real.get('[data-test="tiss-cred-transport"]').classes()).toContain('alert-info');
        expect(real.get('[data-test="tiss-cred-transport"]').text()).toContain('Envio real: ambiente Homologação');
    });

    it('situação em texto: pronta, incompleta com o que falta, sem autenticação e certificado vencido', () => {
        const wrapper = mountPage([
            credential(),
            credential({
                id: 'c2',
                missing: ['endpoint', 'review'],
                has_password: false,
                has_authentication: false,
                effective_endpoint: null,
            }),
            credential({
                id: 'c3',
                has_password: false,
                has_certificate: true,
                certificate: { subject: 'CLINICA', valid_to: '01/01/2026', expired: true },
            }),
        ]);

        const rows = wrapper.findAll('tbody tr');

        expect(rows[0].text()).toContain('Pronta');
        expect(rows[0].text()).toContain('Usuário e senha');
        expect(rows[0].find('[data-test="tiss-cred-no-auth"]').exists()).toBe(false);
        expect(rows[1].text()).toContain('Incompleta: falta endereço do webservice, revisão de um administrador');
        expect(rows[1].find('[data-test="tiss-cred-no-auth"]').text()).toContain('Sem autenticação');
        expect(rows[2].text()).toContain('Certificado (vencido em 01/01/2026)');
        expect(rows[0].get('[data-test="tiss-cred-last-test"]').text()).toBe('Nunca testada');
    });

    it('testar e remover (com confirmação) chamam as rotas da linha', async () => {
        const wrapper = mountPage([credential()]);

        // Nome acessível por linha: a ação visível primeiro, depois operadora e ambiente.
        expect(wrapper.get('[data-test="tiss-cred-edit"]').attributes('aria-label')).toBe(
            'Editar: UNIMED TESTE (Homologação)',
        );

        await wrapper.get('[data-test="tiss-cred-test"]').trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            '/cred/c1/test',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );

        const confirm = vi.fn(() => false);
        vi.stubGlobal('confirm', confirm);
        // Fim do teste libera os botões da linha (ocupados durante a requisição).
        router.post.mock.calls[0][2].onFinish();
        await nextTick();
        await wrapper.get('[data-test="tiss-cred-delete"]').trigger('click');
        expect(confirm).toHaveBeenCalledWith('Remover UNIMED TESTE (Homologação)?');
        expect(router.delete).not.toHaveBeenCalled();

        confirm.mockReturnValue(true);
        await wrapper.get('[data-test="tiss-cred-delete"]').trigger('click');
        expect(router.delete).toHaveBeenCalledWith('/cred/c1', expect.objectContaining({ preserveScroll: true }));
        vi.unstubAllGlobals();
    });

    it('formulário: segredo nunca vem preenchido; salvo mostra "Salvo" e a opção de apagar; envia multipart', async () => {
        const wrapper = mountPage([credential()]);

        await wrapper.get('[data-test="tiss-cred-new"]').trigger('click');
        await nextTick();
        expect(wrapper.find('.search-stub').attributes('data-url')).toBe('/cred/operators?q=__Q__');
        expect(wrapper.get('.modal-stub').attributes('data-density')).toBe('default');

        await wrapper.get('[data-test="tiss-cred-edit"]').trigger('click');
        await nextTick();

        const form = inertia.forms.at(-1);
        const password = wrapper.get('[data-test="tiss-cred-password"]');

        expect(wrapper.get('[data-test="tiss-cred-identity"]').text()).toContain('UNIMED TESTE');
        expect(wrapper.find('.search-stub').exists()).toBe(false);
        expect(password.attributes('type')).toBe('password');
        expect(password.element.value).toBe('');
        expect(form.username).toBe('prestador01');
        expect(wrapper.text()).toContain('Salvo. Deixe em branco para manter.');
        expect(wrapper.find('[data-test="tiss-cred-remove_password"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="tiss-cred-remove_token"]').exists()).toBe(false);

        await wrapper.get('[data-test="tiss-cred-form"]').trigger('submit');
        expect(form.post).toHaveBeenCalledWith('/cred/c1', expect.objectContaining({ forceFormData: true }));
    });
});
