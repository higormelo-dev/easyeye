import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { computed, defineComponent } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import ImportPage from '@/Pages/Panel/Patients/Import.vue';

const state = vi.hoisted(() => ({
    save: vi.fn(),
    hasChanges: false,
    unresolved: 1,
    progressOptions: null,
    uploadPost: vi.fn(),
    page: { props: { flash: {}, errors: {}, t_ui: {} } },
}));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        usePage: () => state.page,
        useForm: (data) => reactive({ ...data, processing: false, errors: {}, post: state.uploadPost, reset: vi.fn() }),
        router: { reload: vi.fn() },
        Link: { template: '<a><slot /></a>', props: { href: { type: String, default: '' } } },
    };
});
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<main><slot /></main>' } }));
vi.mock('@/composables/useImportProgress', () => ({
    useImportProgress: (live, options) => {
        state.progressOptions = options;
        return { realtimeConnected: true };
    },
}));
const MappingStub = defineComponent({
    props: {
        importId: { type: String, required: true },
        items: { type: Array, default: () => [] },
        totals: { type: Object, default: () => ({}) },
        permissions: { type: Object, default: () => ({}) },
        urls: { type: Object, default: () => ({}) },
        mode: { type: String, default: 'preview' },
        t: { type: Object, default: () => ({}) },
        busy: Boolean,
        filesExpired: Boolean,
        namesCapped: Boolean,
        reportAvailable: { type: Boolean, default: true },
    },
    emits: ['saved', 'reprocessed'],
    setup(_, { expose }) {
        expose({
            save: () => state.save(),
            hasChanges: computed(() => state.hasChanges),
            unresolvedCount: computed(() => state.unresolved),
        });
    },
    template: '<section data-test="mapping-stub" />',
});
const mappingT = {
    title: 'De-para de convênios',
    resolve: 'Resolver convênios',
    close: 'Fechar resolução',
    save_failed: 'Não foi possível salvar.',
    reprocess_started: 'Reprocessamento iniciado.',
    expired_label: 'expirado',
    reprocess_badge: 'Reprocessamento',
    reprocess_of: 'Reprocessamento de #:id',
    available_until: 'Relatório disponível até :date',
    unmatched_confirm:
        ':count convênios ainda não encontrados; as linhas deles falharão e poderão ser reprocessadas depois.',
    truncated: 'Prévia limitada aos primeiros 500 convênios.',
    preview_confirm_title: 'Confirmar importação?',
    preview_confirm_text: 'Iniciar a importação de ":file"?',
    preview_confirm_button: 'Confirmar e importar',
    cancel: 'Cancelar',
    catalog_unavailable: 'Não foi possível consultar o catálogo de convênios.',
};
const urls = {
    store: '/panel/patients/import',
    template: '/panel/patients/import/template',
    patients: '/panel/patients',
    covenant_search: '/panel/covenants/search',
    covenant_plan_search: '/panel/covenant-plans/search',
};
const item = {
    id: 'import-a',
    original_name: 'fixture.csv',
    status: 'done',
    status_label: 'Concluído',
    status_color: 'success',
    is_done: true,
    total_rows: 6,
    imported_rows: 0,
    skipped_rows: 0,
    error_rows: 6,
    has_covenant_errors: true,
    has_errors_file: true,
    reprocess_of_id: null,
    is_reprocess: false,
    files_expire_at: '2026-11-22T12:00:00-03:00',
    files_expired: false,
    urls: {
        confirm: '/panel/patients/import/import-a/confirm',
        cancel: '/panel/patients/import/import-a/cancel',
        errors: '/panel/patients/import/import-a/errors',
        covenants: '/panel/patients/import/import-a/covenants',
        covenants_reprocess: '/panel/patients/import/import-a/covenants/reprocess',
    },
    preview: {
        mapped_columns: [{ field: 'full_name', label: 'Nome', required: true }],
        sample_rows: [{ Nome: 'Amostra sintética' }],
        can_proceed: true,
        covenant_summary: {
            items: [
                {
                    source_name: 'CONVÊNIO A',
                    source_name_normalized: 'opaco1',
                    rows: 6,
                    failed_rows: 6,
                    status: 'unmatched',
                    covenant: null,
                    plan: null,
                    mapping: null,
                    retryable: true,
                },
            ],
            totals: { file_rows: 6, unmatched_names: 1, mapped_names: 0 },
            truncated: false,
        },
    },
};
const report = {
    data: {
        import: {
            id: item.id,
            original_name: item.original_name,
            status: 'done',
            is_reprocess: false,
            reprocess_of_id: null,
            files_expire_at: item.files_expire_at,
            files_expired: false,
            report_available: true,
        },
        permissions: { can_create_covenant: false, can_reprocess: true },
        totals: { file_rows: 6, failed_rows: 6, failed_rows_exact: true, unmatched_names: 1, mapped_names: 0 },
        items: structuredClone(item.preview.covenant_summary.items),
    },
};
const response = (body = {}, status = 200) => ({ ok: status < 400, status, json: async () => body });
const wrappers = [];
function render(extra = {}) {
    const wrapper = mount(ImportPage, {
        attachTo: document.body,
        props: {
            imports: [structuredClone(item)],
            urls,
            can_create_covenant: true,
            t: { allergies_hint: 'Alergias opcionais.', covenant_mapping: mappingT },
            ...extra,
        },
        global: { stubs: { PatientImportCovenantMapping: MappingStub } },
    });
    wrappers.push(wrapper);
    return wrapper;
}
const mapping = (wrapper) => wrapper.getComponent(MappingStub);
async function confirm(wrapper) {
    await wrapper.get('[data-test="confirm-import"]').trigger('click');
    await flushPromises();
}
beforeEach(() => {
    vi.clearAllMocks();
    state.hasChanges = false;
    state.unresolved = 1;
    state.save.mockResolvedValue(true);
    state.page.props.flash = {};
    state.page.props.errors = {};
    fetch.mockReset();
    fetch.mockResolvedValue(response(report));
    window.Swal = { fire: vi.fn().mockResolvedValue({ isConfirmed: true }) };
});
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    delete window.Swal;
    vi.restoreAllMocks();
});

describe('Patient imports page — mapping integration', () => {
    it('preview repassa props/URLs exatas e mostra o componente depois das amostras', () => {
        const wrapper = render({ preview_id: item.id });
        const props = mapping(wrapper).props();
        expect(props.importId).toBe(item.id);
        expect(props.mode).toBe('preview');
        expect(props.items).toEqual(item.preview.covenant_summary.items);
        expect(props.permissions.can_create_covenant).toBe(true);
        expect(props.urls).toEqual({
            show: item.urls.covenants,
            update: item.urls.covenants,
            reprocess: item.urls.covenants_reprocess,
            covenantSearch: urls.covenant_search,
            covenantPlanSearch: urls.covenant_plan_search,
        });
        expect(
            wrapper.get('table').element.compareDocumentPosition(mapping(wrapper).element) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(props.totals.failed_rows_exact).toBe(false);
    });
    it('salva rascunho antes de confirmar, avisa pendentes e so depois envia POST', async () => {
        state.hasChanges = true;
        const events = [];
        state.save.mockImplementation(async () => {
            events.push('save');
            return true;
        });
        window.Swal.fire.mockImplementation(async (options) => {
            events.push('dialog');
            expect(options.text).toContain('1 convênios ainda não encontrados');
            return { isConfirmed: true };
        });
        fetch.mockImplementation(async () => {
            events.push('post');
            return response({});
        });
        const wrapper = render({ preview_id: item.id });
        await confirm(wrapper);
        expect(events).toEqual(['save', 'dialog', 'post']);
        expect(fetch.mock.calls[0][0]).toBe(item.urls.confirm);
        expect(fetch.mock.calls[0][1].method).toBe('POST');
        expect(router.reload).toHaveBeenCalledWith({ only: ['imports', 'pending_import', 'preview_id'] });
    });
    it('falha do de-para impede confirmacao e envio, preservando a pagina', async () => {
        state.hasChanges = true;
        state.save.mockResolvedValue(false);
        const wrapper = render({ preview_id: item.id });
        await confirm(wrapper);
        expect(state.save).toHaveBeenCalledOnce();
        expect(window.Swal.fire).not.toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
        expect(router.reload).not.toHaveBeenCalled();
    });
    it('resposta 422 do confirm exibe erro sem reload; excecao de rede tambem', async () => {
        const wrapper = render({ preview_id: item.id });
        fetch.mockResolvedValueOnce(
            response({ message: 'Validação', errors: { import: ['Arquivo não disponível.'] } }, 422),
        );
        await confirm(wrapper);
        expect(wrapper.get('[role="alert"]').text()).toContain('Arquivo não disponível.');
        expect(router.reload).not.toHaveBeenCalled();
        fetch.mockRejectedValueOnce(new Error('offline'));
        await confirm(wrapper);
        expect(wrapper.get('[role="alert"]').text()).toContain(mappingT.save_failed);
    });
    it('nao duplica confirm enquanto POST esta em andamento', async () => {
        fetch.mockImplementation(() => new Promise(() => {}));
        const wrapper = render({ preview_id: item.id });
        await confirm(wrapper);
        await confirm(wrapper);
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(wrapper.get('[data-test="confirm-import"]').element.disabled).toBe(true);
    });
    it('historico antigo com error_rows permite GET do relatorio e reprocessar inline', async () => {
        const wrapper = render({ imports: [{ ...item, has_covenant_errors: false }] });
        await wrapper.get('[data-test="resolve-covenants"]').trigger('click');
        await flushPromises();
        expect(fetch.mock.calls[0][0]).toBe(item.urls.covenants);
        expect(mapping(wrapper).props('mode')).toBe('reprocess');
        expect(mapping(wrapper).props('permissions')).toEqual(report.data.permissions);
        expect(wrapper.get('[data-test="covenant-resolution"]').element).toBe(document.activeElement);
        mapping(wrapper).vm.$emit('reprocessed', {
            import_id: 'child',
            status: 'pending',
            total_rows: 6,
            channel: 'imports.patients.child',
        });
        await flushPromises();
        expect(router.reload).toHaveBeenCalledWith({ only: ['imports', 'pending_import', 'preview_id'] });
        expect(wrapper.get('[role="status"]').text()).toContain(mappingT.reprocess_started);
    });
    it('fechar resolucao nao envia DELETE e devolve o foco ao botao', async () => {
        const wrapper = render();
        const opener = wrapper.get('[data-test="resolve-covenants"]');
        opener.element.focus();
        await opener.trigger('click');
        await flushPromises();
        await wrapper.get('[data-test="close-covenant-resolution"]').trigger('click');
        await flushPromises();
        expect(wrapper.findComponent(MappingStub).exists()).toBe(false);
        expect(fetch.mock.calls.some(([, options]) => options?.method === 'DELETE')).toBe(false);
        expect(document.activeElement).toBe(opener.element);
    });
    it('ignora resposta antiga quando usuario abre outro import', async () => {
        let finish;
        const second = {
            ...item,
            id: 'import-b',
            urls: { ...item.urls, covenants: '/panel/patients/import/import-b/covenants' },
        };
        fetch
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finish = resolve;
                    }),
            )
            .mockResolvedValueOnce(
                response({ data: { ...report.data, import: { ...report.data.import, id: second.id } } }),
            );
        const wrapper = render({ imports: [item, second] });
        await wrapper.findAll('[data-test="resolve-covenants"]')[0].trigger('click');
        const signal = fetch.mock.calls[0][1].signal;
        await wrapper.findAll('[data-test="resolve-covenants"]')[1].trigger('click');
        await flushPromises();
        finish(response(report));
        await flushPromises();
        expect(signal.aborted).toBe(true);
        expect(mapping(wrapper).props('importId')).toBe(second.id);
    });
    it('expiracao bloqueia download e chega ao componente; filho identifica pai no historico', async () => {
        const child = { ...item, id: 'child', is_reprocess: true, reprocess_of_id: item.id, files_expired: true };
        const wrapper = render({ imports: [item, child] });
        expect(wrapper.text()).toContain('Reprocessamento de #import-a');
        expect(wrapper.find('a[href="#patient-import-import-a"]').exists()).toBe(true);
        expect(wrapper.findAll(`a[href="${item.urls.errors}"]`)).toHaveLength(1);
        expect(wrapper.text()).toContain(mappingT.expired_label);
        fetch.mockResolvedValueOnce(
            response({
                data: {
                    ...report.data,
                    import: { ...report.data.import, id: child.id, files_expired: true },
                    permissions: { ...report.data.permissions, can_reprocess: false },
                },
            }),
        );
        await wrapper.findAll('[data-test="resolve-covenants"]')[1].trigger('click');
        await flushPromises();
        expect(mapping(wrapper).props('filesExpired')).toBe(true);
    });
    it('preview truncado permite buscar relatorio completo e nao promete contagem exata', async () => {
        const wrapper = render({
            preview_id: item.id,
            imports: [
                {
                    ...item,
                    preview: {
                        ...item.preview,
                        covenant_summary: { ...item.preview.covenant_summary, truncated: true },
                    },
                },
            ],
        });
        expect(wrapper.text()).toContain(mappingT.truncated);
        await wrapper.get('[data-test="full-covenant-report"]').trigger('click');
        await flushPromises();
        expect(fetch.mock.calls[0][0]).toBe(item.urls.covenants);
    });
});

describe('Patient imports page — full preview and request failures', () => {
    it('aviso de pendentes usa total do servidor mesmo quando a previa esta truncada', async () => {
        const wrapper = render({
            preview_id: item.id,
            imports: [
                {
                    ...item,
                    preview: {
                        ...item.preview,
                        covenant_summary: {
                            ...item.preview.covenant_summary,
                            truncated: true,
                            totals: { ...item.preview.covenant_summary.totals, unmatched_names: 800 },
                        },
                    },
                },
            ],
        });
        await confirm(wrapper);
        expect(window.Swal.fire.mock.calls[0][0].text).toContain('800 convênios ainda não encontrados');
    });

    it('relatorio completo da previa atualiza o mesmo editor e salva rascunho antes do GET', async () => {
        state.hasChanges = true;
        const events = [];
        state.save.mockImplementation(async () => {
            events.push('save');
            return true;
        });
        fetch.mockImplementation(async () => {
            events.push('get');
            return response({
                data: {
                    ...report.data,
                    items: [
                        ...report.data.items,
                        { ...report.data.items[0], source_name: 'OUTRO', source_name_normalized: 'opaque2' },
                    ],
                },
            });
        });
        const wrapper = render({
            preview_id: item.id,
            imports: [
                {
                    ...item,
                    preview: {
                        ...item.preview,
                        covenant_summary: { ...item.preview.covenant_summary, truncated: true },
                    },
                },
            ],
        });
        await wrapper.get('[data-test="full-covenant-report"]').trigger('click');
        await flushPromises();
        expect(events).toEqual(['save', 'get']);
        expect(wrapper.findAllComponents(MappingStub)).toHaveLength(1);
        expect(mapping(wrapper).props('items')).toHaveLength(2);
        expect(wrapper.find('[data-test="full-covenant-report"]').exists()).toBe(false);
    });
    it('GET 404 anuncia falha, mantem historico e nao abre editor de outro import', async () => {
        const wrapper = render();
        fetch.mockResolvedValueOnce(response({ message: 'Importação indisponível.' }, 404));
        await wrapper.get('[data-test="resolve-covenants"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Importação indisponível.');
        expect(wrapper.findComponent(MappingStub).exists()).toBe(false);
        expect(wrapper.text()).toContain('Histórico de importações');
    });
    it('cancelar confirmacao nao confirma nem recarrega', async () => {
        const wrapper = render({ preview_id: item.id });
        window.Swal.fire.mockResolvedValueOnce({ isConfirmed: false });
        await confirm(wrapper);
        expect(fetch).not.toHaveBeenCalled();
        expect(router.reload).not.toHaveBeenCalled();
        expect(wrapper.get('[data-test="confirm-import"]').element.disabled).toBe(false);
    });
});

describe('Patient imports page — distinct name cap', () => {
    it('passa names_capped da previa ao editor mesmo sem corte de payload, e respeita resposta posterior', async () => {
        const wrapper = render({
            preview_id: item.id,
            imports: [
                {
                    ...item,
                    preview: {
                        ...item.preview,
                        covenant_summary: { ...item.preview.covenant_summary, names_capped: true, truncated: false },
                    },
                },
            ],
        });
        expect(mapping(wrapper).props('namesCapped')).toBe(true);
        mapping(wrapper).vm.$emit('saved', { ...report.data, names_capped: false });
        await flushPromises();
        expect(mapping(wrapper).props('namesCapped')).toBe(false);
    });
    it('passa names_capped do GET completo sem alterar permissao de reprocessar', async () => {
        const wrapper = render();
        fetch.mockResolvedValueOnce(response({ data: { ...report.data, names_capped: true } }));
        await wrapper.get('[data-test="resolve-covenants"]').trigger('click');
        await flushPromises();
        expect(mapping(wrapper).props('namesCapped')).toBe(true);
        expect(mapping(wrapper).props('permissions').can_reprocess).toBe(true);
    });
});

describe('Patient imports page — existing behavior regression', () => {
    it('preserva upload com useForm, template, alergias e botoes de cancelamento', async () => {
        const wrapper = render({ preview_id: item.id });
        expect(wrapper.get(`[href="${urls.template}"]`).exists()).toBe(true);
        expect(wrapper.get('[data-testid="import-allergies-hint"]').text()).toContain('Alergias opcionais.');
        const input = wrapper.get('input[type="file"]');
        Object.defineProperty(input.element, 'files', {
            value: [new File(['nome'], 'fixture.csv', { type: 'text/csv' })],
        });
        await input.trigger('change');
        await wrapper.get('form').trigger('submit');
        expect(state.uploadPost).toHaveBeenCalledWith(
            urls.store,
            expect.objectContaining({ forceFormData: true, preserveScroll: true }),
        );
        const cancel = wrapper.findAll('button').find((button) => button.text() === 'Cancelar');
        await cancel.trigger('click');
        await flushPromises();
        expect(fetch.mock.calls[0][1].method).toBe('DELETE');
    });
    it('mantem callbacks WebSocket/Reverb e ressincronizacao sem polling', () => {
        render({ pending_import: { ...item, status: 'processing', is_done: false, processed_rows: 2, progress: 20 } });
        state.progressOptions.onDone();
        state.progressOptions.onResync();
        expect(router.reload.mock.calls.map((call) => call[0])).toEqual([
            { only: ['imports', 'pending_import'] },
            { only: ['pending_import'] },
        ]);
    });
});
