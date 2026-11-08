import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

/**
 * Pedidos de titulares (LGPD arts. 18–19): lista com prazo e "Vencido" em
 * texto, ações só para pedido aberto, exportação só com paciente vinculado,
 * registro com data de recebimento e resposta obrigatória para concluir/recusar.
 */
const inertia = vi.hoisted(() => ({ forms: [], props: null }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.props = reactive({ t_ui: { close: 'Fechar' }, errors: {} });

    return {
        usePage: () => ({ props: inertia.props }),
        router: { patch: vi.fn(), get: vi.fn() },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        useForm: (initial) => {
            const form = reactive({
                ...structuredClone(initial),
                errors: {},
                processing: false,
                isDirty: false,
                reset() {
                    Object.assign(form, structuredClone(initial));
                },
                clearErrors() {
                    form.errors = {};
                },
                post: vi.fn(),
                patch: vi.fn(),
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
vi.mock('@/Components/Panel/TablePagination.vue', () => ({
    default: { props: ['data'], template: '<nav class="pagination-stub" />' },
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

const LgpdRequests = (await import('@/Pages/Panel/Compliance/LgpdRequests.vue')).default;
const { router } = await import('@inertiajs/vue3');

const t = {
    page_title: 'Pedidos de titulares (LGPD)',
    open_count: 'Em aberto: :count',
    overdue_count: 'Vencidos: :count',
    overdue: 'Vencido',
    empty: 'Nenhum pedido registrado.',
    no_patient: 'Sem paciente vinculado',
    responded: 'Respondido em :date por :name',
    columns: {
        requested_at: 'Recebido em',
        requester: 'Titular',
        type: 'Direito',
        deadline: 'Prazo',
        status: 'Situação',
        actions: 'Ações',
    },
    actions: { start: 'Iniciar análise', complete: 'Concluir', reject: 'Recusar', export: 'Baixar dados do paciente' },
    form: { title: 'Registrar pedido', save: 'Registrar', cancel: 'Cancelar', discard_confirm: 'Descartar?' },
    respond: {
        complete_title: 'Concluir pedido',
        reject_title: 'Recusar pedido',
        complete_label: 'Resposta dada ao titular',
        reject_label: 'Justificativa da recusa',
        confirm: 'Confirmar',
        cancel: 'Cancelar',
    },
    types: { access: 'Confirmação e acesso (art. 18, I e II)', deletion: 'Eliminação (art. 18, VI)' },
    statuses: { pending: 'Pendente', in_progress: 'Em análise', completed: 'Concluído' },
};

const row = (extra = {}) => ({
    id: 'r1',
    requester_name: 'Maria',
    requester_email: 'maria@example.test',
    type: 'access',
    status: 'pending',
    open: true,
    overdue: false,
    description: 'Quer cópia',
    response: null,
    rejection_reason: null,
    requested_at: '03/11/2026',
    deadline_at: '18/11/2026',
    responded_at: null,
    responded_by: null,
    patient: null,
    export_url: null,
    ...extra,
});

function mountPage(rows, counts = { open: rows.length, overdue: 0 }) {
    return mount(LgpdRequests, {
        props: {
            requests: { data: rows, last_page: 1 },
            counts,
            types: ['access', 'deletion'],
            today: '2026-11-10',
            t,
        },
    });
}

describe('Panel/Compliance/LgpdRequests', () => {
    beforeEach(() => {
        inertia.forms.length = 0;
        vi.clearAllMocks();
    });

    it('região de densidade na raiz; vazio mostra orientação', () => {
        const wrapper = mountPage([]);

        expect(wrapper.find('[data-ee-density="default"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="lgpd-empty"]').text()).toBe('Nenhum pedido registrado.');
    });

    it('pedido vencido mostra "Vencido" em texto e a contagem; ações só para pedido aberto', () => {
        const wrapper = mountPage(
            [
                row({
                    id: 'late',
                    overdue: true,
                    patient: { name: 'MARIA SOUZA', code: '0001' },
                    export_url: '/x/late/export',
                }),
                row({
                    id: 'done',
                    status: 'completed',
                    open: false,
                    response: 'Enviado por e-mail',
                    responded_at: '05/11/2026',
                    responded_by: 'Admin',
                }),
            ],
            { open: 1, overdue: 1 },
        );

        expect(wrapper.get('[data-test="lgpd-overdue-count"]').text()).toBe('Vencidos: 1');

        const late = wrapper.get('[data-test="lgpd-row-late"]');
        expect(late.get('[data-test="lgpd-overdue"]').text()).toBe('Vencido');
        expect(late.text()).toContain('MARIA SOUZA (0001)');
        expect(late.find('[data-test="lgpd-start"]').exists()).toBe(true);
        expect(late.find('[data-test="lgpd-complete"]').exists()).toBe(true);
        expect(late.find('[data-test="lgpd-reject"]').exists()).toBe(true);
        expect(late.get('[data-test="lgpd-export"]').attributes('href')).toBe('/x/late/export');

        const done = wrapper.get('[data-test="lgpd-row-done"]');
        expect(done.find('button').exists()).toBe(false);
        expect(done.text()).toContain('Resposta dada ao titular: Enviado por e-mail');
        expect(done.text()).toContain('Respondido em 05/11/2026 por Admin');
        expect(done.text()).toContain('Sem paciente vinculado');
    });

    it('erro de andamento (pedido já encerrado por outra pessoa) aparece em alerta', async () => {
        inertia.props.errors = { action: 'Este pedido já foi encerrado.' };
        const wrapper = mountPage([row()]);

        const alert = wrapper.get('[data-test="lgpd-action-error"]');
        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toBe('Este pedido já foi encerrado.');
        inertia.props.errors = {};
    });

    it('iniciar análise envia a ação "start"', async () => {
        const wrapper = mountPage([row()]);

        await wrapper.get('[data-test="lgpd-start"]').trigger('click');

        expect(router.patch).toHaveBeenCalledWith(
            '/_routes/panel.reports.compliance.lgpd-requests.update/r1',
            { action: 'start' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('recusar abre o modal com a justificativa; confirmar só com texto', async () => {
        const wrapper = mountPage([row()]);

        await wrapper.get('[data-test="lgpd-reject"]').trigger('click');
        await nextTick();

        const modal = wrapper.get('.modal-stub');
        expect(modal.attributes('data-density')).toBe('default');
        expect(modal.text()).toContain('Recusar pedido');
        expect(modal.text()).toContain('Justificativa da recusa');

        const submit = modal.get('[data-test="lgpd-respond-submit"]');
        expect(submit.attributes('disabled')).toBeDefined();

        await modal.get('[data-test="lgpd-respond-text"]').setValue('Identidade não confirmada.');
        expect(submit.attributes('disabled')).toBeUndefined();

        await modal.get('form').trigger('submit');
        const respondForm = inertia.forms.find((form) => 'text' in form);
        expect(respondForm.action).toBe('reject');
        expect(respondForm.patch).toHaveBeenCalledWith(
            '/_routes/panel.reports.compliance.lgpd-requests.update/r1',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('registrar pedido: modal com data de recebimento de hoje e busca de paciente', async () => {
        const wrapper = mountPage([]);

        await wrapper.get('[data-test="lgpd-new"]').trigger('click');
        await nextTick();

        const modal = wrapper.get('.modal-stub');
        expect(modal.text()).toContain('Registrar pedido');
        expect(modal.get('input[type="date"]').element.value).toBe('2026-11-10');
        expect(modal.get('input[type="date"]').attributes('max')).toBe('2026-11-10');
        expect(modal.get('.search-stub').attributes('data-url')).toContain('panel.patients.search');

        await modal.get('form').trigger('submit');
        const createForm = inertia.forms.find((form) => 'requester_name' in form);
        expect(createForm.post).toHaveBeenCalledWith(
            '/_routes/panel.reports.compliance.lgpd-requests.store',
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
