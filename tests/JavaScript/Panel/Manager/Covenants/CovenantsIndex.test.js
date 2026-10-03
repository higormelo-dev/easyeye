import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import CovenantsIndex from '@/Pages/Panel/Manager/Covenants/Index.vue';

/**
 * Manager → Convênios (catálogo global): ações por tipo (PARTICULAR, ANS,
 * manual), filtros server-side e sincronização com a ANS (download ou CSV)
 * acompanhada por WebSocket.
 */
const form = vi.hoisted(() => ({ current: null, transformed: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        usePage: () => ({ props: { locale: 'pt_BR' } }),
        router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn() },
        useForm: (data) => {
            form.current = reactive({
                ...data,
                errors: {},
                processing: false,
                progress: null,
                transform(callback) {
                    form.transformed = callback;
                    return this;
                },
                post: vi.fn(),
                reset: vi.fn(),
            });
            return form.current;
        },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});
const live = vi.hoisted(() => ({ options: null, connected: true, resync: null }));
vi.mock('@/composables/useImportProgress', async () => {
    const { computed } = await import('vue');
    return {
        useImportProgress: (importRef, options) => {
            live.options = options;
            live.importRef = importRef;
            live.resync = vi.fn();
            return { realtimeConnected: computed(() => live.connected), resync: live.resync };
        },
    };
});
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'view', 'showViewToggle'],
        emits: ['set-view'],
        template: `<header><h1>{{ title }}</h1><span class="total">{{ total }}</span>
            <button v-if="showViewToggle" class="to-cards" @click="$emit('set-view', 'cards')" />
            <slot name="actions" /></header>`,
    },
}));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({ default: { props: ['data'], template: '<nav />' } }));
vi.mock('@/Pages/Panel/Manager/Covenants/CovenantFormModal.vue', () => ({
    default: {
        props: ['open', 'covenant'],
        template: '<div class="form-stub" :data-open="String(open)" :data-covenant="covenant?.id ?? \'\'" />',
    },
}));
vi.mock('@/Pages/Panel/Manager/Covenants/CovenantDetailDrawer.vue', () => ({
    default: {
        props: ['open', 'covenant'],
        template: '<div class="drawer-stub" :data-open="String(open)" :data-covenant="covenant?.id ?? \'\'" />',
    },
}));
vi.mock('@/Components/Panel/ConfirmationWithReasonModal.vue', () => ({
    default: {
        props: ['open', 'title', 'message', 'error'],
        emits: ['confirm', 'close'],
        template: `<div class="reason-stub" :data-open="String(open)" :data-error="error">{{ message }}
            <button class="reason-confirm" @click="$emit('confirm', 'Duplicado da operadora importada da ANS.')" /></div>`,
    },
}));

// Chave sem tradução no fixture devolve a própria chave.
const t = new Proxy(
    { page_title: 'Catálogo de convênios', confirm_delete_text: 'Excluir ":name"?' },
    { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
);

const MODALITIES = ['Cooperativa Médica', 'Medicina de Grupo', 'Odontologia de Grupo'];

const rows = [
    {
        id: 'part-1',
        name: 'PARTICULAR',
        source: 'manual',
        source_label: 'Manual',
        color: '#64748B',
        active: true,
        is_particular: true,
    },
    {
        id: 'ans-1',
        name: 'UNIMED CAMPINAS',
        trade_name: 'Unimed Campinas',
        company_name: 'Unimed Campinas Cooperativa de Trabalho Médico',
        ans_registry: '335690',
        ans_modality: 'Cooperativa Médica',
        city: 'Campinas',
        uf: 'SP',
        source: 'ans',
        source_label: 'ANS',
        color: '#22C55E',
        active: true,
        ans_status: 'active',
        is_particular: false,
    },
    {
        id: 'man-1',
        name: 'CONVÊNIO LOCAL',
        source: 'manual',
        source_label: 'Manual',
        color: '#2563EB',
        active: true,
        is_particular: false,
    },
];

function mountPage(props = {}) {
    return mount(CovenantsIndex, {
        global: { stubs: { teleport: true } },
        props: {
            covenants: { data: rows, links: [], last_page: 1, total: 3 },
            filters: {},
            stats: { active: 900, ans: 898, manual: 2, cancelled: 20 },
            imports: [],
            runningImport: null,
            modalities: MODALITIES,
            defaultModalities: ['Cooperativa Médica', 'Medicina de Grupo'],
            ufs: ['RS', 'SP'],
            autoSync: true,
            t,
            ...props,
        },
    });
}

async function openMenu(row) {
    await row.find('button[aria-haspopup="menu"]').trigger('click');
    return row.findAll('.dropdown-item').map((item) => item.text());
}

const running = {
    id: 'imp-1',
    source: 'ans',
    source_label: 'Download da ANS',
    status: 'processing',
    status_label: 'Processando',
    status_color: 'primary',
    phase: 'processing',
    phase_label: 'Atualizando operadoras',
    total_rows: 4183,
    processed_rows: 2000,
    progress: 48,
    created_count: 60,
    updated_count: 700,
    unchanged_count: 0,
    deactivated_count: 0,
    skipped_modality: 120,
    skipped_invalid: 0,
    error: null,
    is_done: false,
    channel: 'manager.imports.covenants.imp-1',
};

describe('Manager → Convênios', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        form.transformed = null;
    });

    it('mostra nome, razão social, registro, modalidade, cidade/UF e números', () => {
        const text = mountPage().text();

        expect(text).toContain('UNIMED CAMPINAS');
        expect(text).toContain('Unimed Campinas Cooperativa de Trabalho Médico');
        expect(text).toContain('335690');
        expect(text).toContain('Cooperativa Médica');
        expect(text).toContain('Campinas / SP');
        expect(text).toContain('898');
    });

    it('menu: PARTICULAR só edita; ANS edita e desativa; manual também exclui', async () => {
        const [particular, ans, manual] = mountPage().findAll('tbody tr');

        expect(await openMenu(particular)).toEqual(['edit']);
        expect(await openMenu(ans)).toEqual(['edit', 'deactivate']);
        expect(await openMenu(manual)).toEqual(['edit', 'deactivate', 'delete']);
        expect(particular.text()).toContain('particular_badge');
    });

    it('cancelada na ANS mostra aviso ao lado do status, com explicação no tooltip', () => {
        const covenants = {
            data: [{ ...rows[1], ans_status: 'cancelled', active: false }],
            links: [],
            total: 1,
        };
        const warning = mountPage({ covenants }).find('.badge-soft-warning');

        expect(warning.text()).toBe('cancelled_badge');
        expect(warning.attributes('title')).toBe('cancelled_hint');
    });

    it('desativar envia só o "active" do convênio', async () => {
        const row = mountPage().findAll('tbody tr')[1];
        await openMenu(row);
        await row.findAll('.dropdown-item')[1].trigger('click');

        expect(router.put).toHaveBeenCalledWith(
            expect.stringContaining('ans-1'),
            { active: false },
            expect.any(Object),
        );
    });

    it('excluir pede justificativa; erro do servidor ("em uso") fica no modal', async () => {
        const wrapper = mountPage();
        const row = wrapper.findAll('tbody tr')[2];
        await openMenu(row);
        await row.findAll('.dropdown-item').at(-1).trigger('click');

        expect(wrapper.find('.reason-stub').text()).toContain('Excluir "CONVÊNIO LOCAL"?');
        await wrapper.find('.reason-confirm').trigger('click');

        expect(router.delete).toHaveBeenCalledWith(
            expect.stringContaining('man-1'),
            expect.objectContaining({ data: { reason: 'Duplicado da operadora importada da ANS.' } }),
        );

        router.delete.mock.calls[0][1].onError({ reason: 'Este convênio já é usado por clínicas.' });
        await flushPromises();
        expect(wrapper.find('.reason-stub').attributes('data-error')).toBe('Este convênio já é usado por clínicas.');
    });

    it('"ver detalhes" abre o drawer e "editar" abre o formulário com o convênio', async () => {
        const wrapper = mountPage();
        const row = wrapper.findAll('tbody tr')[1];

        await row.find('button[title="action_view"]').trigger('click');
        expect(wrapper.find('.drawer-stub').attributes('data-covenant')).toBe('ans-1');

        await openMenu(row);
        await row.find('.dropdown-item').trigger('click');
        expect(wrapper.find('.form-stub').attributes('data-open')).toBe('true');
        expect(wrapper.find('.form-stub').attributes('data-covenant')).toBe('ans-1');
    });

    it('filtros de modalidade/UF/canceladas e ordenação consultam o servidor', async () => {
        const wrapper = mountPage({ filters: { source: 'ans' } });

        await wrapper.find('select[aria-label="filter_modality"]').setValue('Cooperativa Médica');
        await flushPromises();
        expect(router.get).toHaveBeenLastCalledWith(
            expect.any(String),
            expect.objectContaining({ modality: 'Cooperativa Médica', source: 'ans' }),
            expect.objectContaining({ only: ['covenants', 'filters'] }),
        );

        await wrapper.find('#flt-cancelled').setValue(true);
        await flushPromises();
        expect(router.get).toHaveBeenLastCalledWith(
            expect.any(String),
            expect.objectContaining({ cancelled: 1 }),
            expect.any(Object),
        );

        const registryHeader = wrapper.findAll('th').find((th) => th.text().includes('col_registry'));
        await registryHeader.find('button').trigger('click');
        expect(router.get).toHaveBeenLastCalledWith(
            expect.any(String),
            expect.objectContaining({ sort: 'ans_registry', direction: 'asc' }),
            expect.any(Object),
        );
    });

    it('alterna para cards com as mesmas ações por tipo', async () => {
        localStorage.removeItem('mgr_covenants_view');
        const wrapper = mountPage();
        await wrapper.find('.to-cards').trigger('click');

        expect(wrapper.find('table.table-nowrap').exists()).toBe(false);
        expect(wrapper.findAll('.card.card-body h6').map((h) => h.text())).toEqual([
            'PARTICULAR',
            'UNIMED CAMPINAS',
            'CONVÊNIO LOCAL',
        ]);
        // PARTICULAR não tem menu (nem desativar nem excluir).
        expect(wrapper.findAll('.card.card-body')[0].find('button[aria-haspopup="menu"]').exists()).toBe(false);
        expect(localStorage.getItem('mgr_covenants_view')).toBe('cards');
        localStorage.removeItem('mgr_covenants_view');
    });

    it('sincronização: "Atualizar agora" envia só origem e modalidades (padrão sem odontologia)', async () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('import_auto_hint');
        expect(form.current.modalities).toEqual(['Cooperativa Médica', 'Medicina de Grupo']);

        form.current.active_file = new File(['x'], 'sobrou.csv'); // escolhido antes de voltar pra ANS
        await wrapper.find('form').trigger('submit');

        expect(form.current.post).toHaveBeenCalledWith(expect.stringContaining('imports.store'), expect.any(Object));
        expect(form.transformed({ ...form.current })).toEqual({
            source: 'ans',
            modalities: ['Cooperativa Médica', 'Medicina de Grupo'],
        });
    });

    it('envio manual exige o CSV de ativas; sem modalidade não envia', async () => {
        const wrapper = mountPage();
        const submit = () => wrapper.find('form button[type="submit"]');

        await wrapper.find('#imp-mode-upload').setValue(true);
        expect(wrapper.find('#imp-active').exists()).toBe(true);
        expect(submit().attributes('disabled')).toBeDefined();

        form.current.active_file = new File(['x'], 'Relatorio_cadop.csv');
        await flushPromises();
        expect(submit().attributes('disabled')).toBeUndefined();

        form.current.modalities = [];
        await flushPromises();
        expect(submit().attributes('disabled')).toBeDefined();
    });

    it('barra de progresso por WebSocket; ao terminar recarrega histórico, números e catálogo', async () => {
        const wrapper = mountPage({ runningImport: running });

        expect(wrapper.find('form button[type="submit"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Atualizando operadoras');
        expect(wrapper.find('.progress-bar').attributes('style')).toContain('width: 48%');

        live.importRef.value = { ...running, status: 'done', status_color: 'success', progress: 100, is_done: true };
        live.options.onDone();
        await flushPromises();

        expect(router.reload).toHaveBeenCalledWith({ only: ['imports', 'stats', 'covenants'] });
        expect(wrapper.find('.progress-bar').classes()).toContain('bg-success');
    });

    it('histórico mostra o tipo (automática/manual) e o resultado', () => {
        const wrapper = mountPage({
            imports: [
                {
                    ...running,
                    id: 'imp-0',
                    source: 'scheduled',
                    source_label: 'Automática (semanal)',
                    status: 'done',
                    status_label: 'Concluída',
                    status_color: 'success',
                    is_done: true,
                    created_count: 114,
                    deactivated_count: 20,
                    created_at: '03/10/2026 04:30',
                },
            ],
        });
        const text = wrapper.findAll('tbody').at(-1).text();

        expect(text).toContain('Automática (semanal)');
        expect(text).toContain('03/10/2026 04:30');
        expect(text).toContain('114');
    });

    describe('sincronização na fila / parada / sem tempo real', () => {
        const queued = {
            ...running,
            status: 'pending',
            status_label: 'Aguardando',
            status_color: 'secondary',
            phase: null,
            phase_label: null,
            processed_rows: 0,
            total_rows: 0,
            progress: 0,
            idle_seconds: 5,
            stall_after_seconds: 90,
        };

        it('na fila mostra "aguardando o processamento" em vez de contadores zerados', () => {
            const wrapper = mountPage({ runningImport: queued });

            expect(wrapper.text()).toContain('import_waiting_worker');
            expect(wrapper.text()).not.toContain('result_skipped_modality');
            expect(wrapper.text()).not.toContain('import_cancel');
        });

        it('parada além do limite: avisa e cancela pela rota própria', async () => {
            const wrapper = mountPage({ runningImport: { ...queued, idle_seconds: 300 } });

            expect(wrapper.find('.alert').classes()).toContain('alert-warning');
            expect(wrapper.text()).toContain('import_stalled_pending');

            await wrapper
                .findAll('button')
                .find((b) => b.text().includes('import_cancel'))
                .trigger('click');

            expect(router.post).toHaveBeenCalledWith(
                expect.stringContaining('covenants.imports.cancel'),
                {},
                expect.objectContaining({ preserveScroll: true }),
            );
        });

        it('sem tempo real: botão "Atualizar status" relê o estado', async () => {
            live.connected = false;
            const wrapper = mountPage({ runningImport: queued });

            await wrapper
                .findAll('button')
                .find((b) => b.text().includes('import_refresh_status'))
                .trigger('click');

            expect(live.resync).toHaveBeenCalledOnce();
            live.connected = true;
        });

        it('cancelada aparece neutra (não como erro)', async () => {
            const wrapper = mountPage({ runningImport: queued });
            live.importRef.value = { ...queued, status: 'cancelled', status_label: 'Cancelado', is_done: true };
            await flushPromises();

            expect(wrapper.find('.alert').classes()).toContain('alert-secondary');
        });
    });
});
