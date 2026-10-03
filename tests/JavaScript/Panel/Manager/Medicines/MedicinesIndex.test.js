import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import MedicinesIndex from '@/Pages/Panel/Manager/Medicines/Index.vue';

/**
 * Manager → Medicamentos (catálogo global do receituário): renderização do
 * catálogo, regras de ação por origem (CMED só edita posologia), filtros
 * server-side e acompanhamento automático de importação em andamento.
 */
const form = vi.hoisted(() => ({ current: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        usePage: () => ({ props: { locale: 'pt_BR' } }),
        router: { get: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn() },
        useForm: (data) => {
            form.current = reactive({
                ...data,
                errors: {},
                processing: false,
                progress: null,
                post: vi.fn(),
                reset: vi.fn(),
            });
            return form.current;
        },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});
const live = vi.hoisted(() => ({ options: null, connected: true }));
vi.mock('@/composables/useImportProgress', async () => {
    const { computed } = await import('vue');
    return {
        useImportProgress: (importRef, options) => {
            live.options = options;
            live.importRef = importRef;
            return { realtimeConnected: computed(() => live.connected) };
        },
    };
});
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: { props: ['title', 'subtitle'], template: '<header><h1>{{ title }}</h1><slot name="actions" /></header>' },
}));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({ default: { props: ['data'], template: '<nav />' } }));
vi.mock('@/Pages/Panel/Manager/Medicines/MedicineFormModal.vue', () => ({
    default: {
        props: ['open', 'medicine'],
        template: '<div class="form-stub" :data-open="String(open)" :data-medicine="medicine?.id ?? \'\'" />',
    },
}));

// Chave sem tradução no fixture devolve a própria chave (só strings — o Vue
// consulta Symbols internamente).
const t = new Proxy(
    { page_title: 'Catálogo de medicamentos', source_cmed: 'CMED/Anvisa' },
    { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
);

const rows = [
    {
        id: 'cmed-1',
        name: 'PREDOPTIC',
        concentration: '10 MG/ML',
        active_ingredient: 'acetato de prednisolona',
        form: 'suspensão oftálmica',
        presentation_detail: '10 MG/ML SUS OFT CT FR X 5 ML',
        laboratory: 'GEOLAB',
        category: 'Similar',
        source: 'cmed',
        source_label: 'CMED/Anvisa',
        is_ophthalmic: true,
        is_marketed: true,
        active: true,
    },
    {
        id: 'man-1',
        name: 'TOBRAMICINA 0,3%',
        source: 'manual',
        source_label: 'Curado',
        is_ophthalmic: true,
        is_marketed: true,
        active: true,
        dosage: '1 gota',
        frequency: 'de 4/4h',
    },
];

function mountPage(props = {}) {
    return mount(MedicinesIndex, {
        props: {
            medicines: { data: rows, links: [], last_page: 1 },
            filters: {},
            stats: { active: 21393, cmed: 21341, manual: 52, ophthalmic: 400 },
            imports: [],
            runningImport: null,
            presentations: [],
            t,
            ...props,
        },
    });
}

describe('Manager → Medicamentos', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(() => vi.useRealTimers());

    it('mostra nome, concentração, genérico, apresentação e laboratório', () => {
        const text = mountPage().text();
        expect(text).toContain('PREDOPTIC');
        expect(text).toContain('10 MG/ML');
        expect(text).toContain('acetato de prednisolona');
        expect(text).toContain('10 MG/ML SUS OFT CT FR X 5 ML');
        expect(text).toContain('GEOLAB');
        expect(text).toContain('21.393');
    });

    it('item da CMED só tem "editar posologia"; curado tem ativar/desativar e excluir', () => {
        const wrapper = mountPage();
        const [cmedRow, manualRow] = wrapper.findAll('tbody tr');

        expect(cmedRow.findAll('button')).toHaveLength(1);
        expect(cmedRow.find('button').attributes('aria-label')).toBe('edit_posology');
        expect(manualRow.findAll('button')).toHaveLength(3);
    });

    it('editar abre o formulário com o item', async () => {
        const wrapper = mountPage();
        await wrapper.find('tbody tr button').trigger('click');
        expect(wrapper.find('.form-stub').attributes('data-medicine')).toBe('cmed-1');
        expect(wrapper.find('.form-stub').attributes('data-open')).toBe('true');
    });

    it('filtro de origem consulta o servidor', async () => {
        const wrapper = mountPage();
        await wrapper.find('select').setValue('cmed');
        await flushPromises();
        expect(router.get).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ source: 'cmed' }),
            expect.objectContaining({ only: ['medicines', 'filters'] }),
        );
    });

    const running = {
        id: 'imp-1',
        status: 'processing',
        status_label: 'Processando',
        status_color: 'primary',
        phase: 'processing',
        phase_label: 'Processando apresentações',
        cmed_original_name: 'lista_pmc.xlsx',
        total_rows: 26000,
        processed_rows: 13000,
        progress: 50,
        created_count: 9000,
        updated_count: 0,
        deactivated_count: 0,
        skipped_hospital: 1200,
        skipped_inactive_registration: 300,
        skipped_invalid: 0,
        error: null,
        is_done: false,
        channel: 'manager.imports.medicines.imp-1',
    };

    it('importação: botão só habilita com o arquivo CMED e fica bloqueado com outra em andamento', async () => {
        const wrapper = mountPage();
        const submit = () => wrapper.find('form button[type="submit"]');
        expect(submit().attributes('disabled')).toBeDefined();

        form.current.cmed_file = new File(['x'], 'lista.xlsx');
        await flushPromises();
        expect(submit().attributes('disabled')).toBeUndefined();

        await wrapper.setProps({ runningImport: running });
        expect(submit().attributes('disabled')).toBeDefined();
    });

    it('mostra a barra de progresso com fase, linhas e contadores', () => {
        const wrapper = mountPage({ runningImport: running });
        const bar = wrapper.find('.progress-bar');

        expect(bar.attributes('style')).toContain('width: 50%');
        expect(bar.classes()).toContain('progress-bar-animated');
        expect(wrapper.text()).toContain('Processando apresentações');
        expect(wrapper.text()).toContain('9.000');
    });

    it('evento WebSocket atualiza a barra; ao terminar recarrega histórico e números', async () => {
        const wrapper = mountPage({ runningImport: running });

        live.importRef.value = { ...running, processed_rows: 20000, progress: 77 };
        await flushPromises();
        expect(wrapper.find('.progress-bar').attributes('style')).toContain('width: 77%');

        live.importRef.value = { ...running, status: 'done', status_color: 'success', progress: 100, is_done: true };
        live.options.onDone();
        await flushPromises();
        expect(router.reload).toHaveBeenCalledWith({ only: ['imports', 'stats', 'medicines'] });
        expect(wrapper.find('.progress-bar').classes()).toContain('bg-success');
    });

    it('terminou antes de conectar: o resultado final vem do histórico na ressincronização', async () => {
        const wrapper = mountPage({ runningImport: running });

        await wrapper.setProps({
            runningImport: null,
            imports: [{ ...running, status: 'done', status_color: 'success', progress: 100, is_done: true }],
        });

        expect(wrapper.find('.progress-bar').classes()).toContain('bg-success');
    });

    it('mostra aviso quando o tempo real cai', async () => {
        live.connected = false;
        const wrapper = mountPage({ runningImport: running });
        expect(wrapper.find('.ti-plug-connected-x').exists()).toBe(true);
        live.connected = true;
    });
});
