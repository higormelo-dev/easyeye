import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import Cid10Index from '@/Pages/Panel/Manager/Cid10/Index.vue';

/**
 * Manager → CID-10 (catálogo global de diagnósticos): KPIs que filtram,
 * filtros server-side, tabela com selos oficial/editado/personalizado,
 * exclusão com justificativa, gaveta de registros a revisar (lista sob
 * demanda) e importação com progresso por WebSocket (sem polling).
 */
const form = vi.hoisted(() => ({ current: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        usePage: () => ({ props: { locale: 'pt_BR', t_ui: { realtime_offline: 'Sem tempo real' } } }),
        router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn() },
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
const live = vi.hoisted(() => ({ options: null, connected: true, resync: null, importRef: null }));
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
vi.mock('@/Pages/Panel/Manager/Cid10/Cid10FormModal.vue', () => ({
    default: {
        props: ['open', 'code'],
        template: '<div class="form-stub" :data-open="String(open)" :data-code="code?.id ?? \'\'" />',
    },
}));
vi.mock('@/Pages/Panel/Manager/Cid10/Cid10DetailDrawer.vue', () => ({
    default: {
        props: ['open', 'code'],
        template: '<div class="drawer-stub" :data-open="String(open)" :data-code="code?.id ?? \'\'" />',
    },
}));
vi.mock('@/Pages/Panel/Manager/Cid10/Cid10ReviewDrawer.vue', () => ({
    default: {
        props: ['open', 'summary', 'records', 'loading'],
        template:
            '<div class="review-stub" :data-open="String(open)" :data-loading="String(loading)" :data-records="records === null ? \'null\' : records.length" />',
    },
}));
vi.mock('@/Components/Panel/ConfirmationWithReasonModal.vue', () => ({
    default: {
        props: ['open', 'title', 'message', 'error'],
        emits: ['confirm', 'close'],
        template: `<div class="reason-stub" :data-open="String(open)"><p class="msg">{{ message }}</p><p class="err">{{ error }}</p>
            <button class="reason-confirm" @click="$emit('confirm', 'Código criado por engano no catálogo.')" /></div>`,
    },
}));

// Chave sem tradução no fixture devolve a própria chave.
const t = new Proxy(
    {
        page_title: 'Catálogo CID-10',
        confirm_delete_text: '":code – :description" sai da busca.',
        confirm_delete_official: 'Volta na próxima importação.',
        confirm_delete_title: 'Excluir :code?',
        usage_hint: ':records prontuário(s) · :exams exame(s) · :clinics clínica(s)',
        import_progress_rows: ':processed de :total códigos',
        edited_hint: 'Oficial: ":official"',
        delete_blocked: 'Em uso por :count',
    },
    { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
);

const usage = (total = 0, extra = {}) => ({
    records: total,
    exams: 0,
    clinics: total ? 1 : 0,
    links: 0,
    total,
    ...extra,
});

const rows = [
    {
        id: 'c-1',
        code: 'H25.1',
        description: 'Catarata nuclear (texto da casa)',
        official_description: 'Catarata senil nuclear',
        category: 'Cristalino',
        group_name: 'Transtornos do cristalino',
        chapter: 'VII',
        chapter_name: 'Doenças do olho e anexos',
        source: 'datasus',
        is_custom: false,
        is_edited: true,
        usage: usage(1234, { exams: 10, clinics: 3 }),
    },
    {
        id: 'c-2',
        code: 'H59.7',
        description: 'Complicação pós-operatória',
        official_description: null,
        category: 'Retina',
        group_name: null,
        chapter: 'VII',
        source: 'custom',
        is_custom: true,
        is_edited: false,
        usage: usage(0),
    },
];

const running = {
    id: 'imp-1',
    status: 'processing',
    status_label: 'Processando',
    status_color: 'primary',
    phase: 'applying',
    phase_label: 'Atualizando o catálogo',
    original_name: 'CID10CSV.zip',
    total_rows: 12451,
    processed_rows: 6000,
    progress: 48,
    is_done: false,
    idle_seconds: 0,
    stall_after_seconds: 660,
    channel: 'manager.imports.cid10.imp-1',
};

function mountPage(props = {}) {
    return mount(Cid10Index, {
        global: { stubs: { teleport: true } },
        props: {
            codes: { data: rows, links: [], last_page: 1, total: 2 },
            filters: {},
            stats: { total: 12452, official: 12451, custom: 1, edited: 1, used: 300, review: 4 },
            chapters: [{ chapter: 'VII', name: 'Doenças do olho e anexos', label: 'VII – Doenças do olho e anexos' }],
            categories: ['Cristalino', 'Retina'],
            usageAt: '2026-10-05T14:30:00-03:00',
            review: { total: 4, clinics: [] },
            reviewRecords: null,
            imports: [],
            runningImport: null,
            t,
            ...props,
        },
    });
}

const lastGet = () => router.get.mock.calls.at(-1)[1];

describe('Manager → CID-10', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    });
    afterEach(() => vi.useRealTimers());

    it('KPIs do catálogo com números localizados; "registros a revisar" é ação, os demais filtram', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-test="kpi-total"]').text()).toBe('12.452');
        expect(wrapper.find('[data-test="kpi-used"]').text()).toBe('300');
        expect(wrapper.find('[data-test="kpi-review"]').text()).toBe('4');
        expect(wrapper.findAll('button[aria-pressed]')).toHaveLength(5);
    });

    it('card "Personalizados" filtra pela origem; clicar de novo tira o filtro', async () => {
        const wrapper = mountPage();
        const custom = wrapper.findAll('button[aria-pressed]')[2];

        await custom.trigger('click');
        expect(lastGet()).toMatchObject({ source: 'custom' });
        expect(custom.attributes('aria-pressed')).toBe('true');

        await custom.trigger('click');
        expect(lastGet().source).toBeUndefined();
    });

    it('card "Usados pelas clínicas" e "Descrição editada" filtram no servidor', async () => {
        const wrapper = mountPage();
        const cards = wrapper.findAll('button[aria-pressed]');

        await cards[4].trigger('click');
        expect(lastGet()).toMatchObject({ usage: 'used' });

        await cards[3].trigger('click');
        expect(lastGet()).toMatchObject({ usage: 'used', edited: 1 });
    });

    it('filtros de capítulo/categoria/uso vão para o servidor; trocar o capítulo limpa a categoria', async () => {
        const wrapper = mountPage({ filters: { category: 'Retina' } });

        await wrapper.find('#cid-filter-chapter').setValue('VII');
        await flushPromises();
        expect(lastGet()).toMatchObject({ chapter: 'VII' });
        expect(lastGet().category).toBeUndefined();

        await wrapper.find('#cid-filter-usage').setValue('unused');
        expect(lastGet()).toMatchObject({ chapter: 'VII', usage: 'unused' });
        expect(router.get.mock.calls.at(-1)[2].only).toEqual(['codes', 'filters', 'categories']);
    });

    it('busca espera parar de digitar antes de consultar', async () => {
        const wrapper = mountPage();
        await wrapper.find('input[type="text"]').setValue('glaucoma');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        expect(lastGet()).toMatchObject({ search: 'glaucoma' });
    });

    it('ordenar pela coluna de uso vai para o servidor', async () => {
        const wrapper = mountPage();
        const usageTh = wrapper.findAll('th button').find((b) => b.text().includes('col_usage'));
        await usageTh.trigger('click');

        expect(lastGet()).toMatchObject({ sort: 'usage', direction: 'asc' });
    });

    it('mostra quando o uso foi apurado (data/hora local)', () => {
        expect(mountPage().text()).toContain('usage_updated');
        expect(mountPage({ usageAt: null }).text()).not.toContain('usage_updated');
    });

    it('excluir pede justificativa (aviso de que o oficial volta) e envia o motivo; erro do servidor fica no modal', async () => {
        const wrapper = mountPage();
        const row = wrapper.findAll('tbody tr')[1];
        await row.find('button[aria-haspopup="menu"]').trigger('click');
        await row.find('[data-test="delete"]').trigger('click');

        expect(wrapper.find('.reason-stub').attributes('data-open')).toBe('true');
        expect(wrapper.find('.reason-stub .msg').text()).toBe('"H59.7 – Complicação pós-operatória" sai da busca.');

        router.delete.mockImplementation((url, options) => options.onError({ code: 'Em uso em 2 registro(s).' }));
        await wrapper.find('.reason-confirm').trigger('click');
        await flushPromises();

        expect(router.delete).toHaveBeenCalledWith(
            '/_routes/manager.cid10.destroy/c-2',
            expect.objectContaining({ data: { reason: 'Código criado por engano no catálogo.' } }),
        );
        expect(wrapper.find('.reason-stub .err').text()).toBe('Em uso em 2 registro(s).');
    });

    it('código oficial: a confirmação avisa que ele volta na próxima importação', async () => {
        const wrapper = mountPage({ codes: { data: [{ ...rows[0], usage: usage(0) }], links: [], total: 1 } });
        const row = wrapper.find('tbody tr');
        await row.find('button[aria-haspopup="menu"]').trigger('click');
        await row.find('[data-test="delete"]').trigger('click');

        expect(wrapper.find('.reason-stub .msg').text()).toContain('Volta na próxima importação.');
    });

    it('"Novo código" abre o formulário vazio; editar abre com o item', async () => {
        const wrapper = mountPage();
        await wrapper.find('[data-test="new-code"]').trigger('click');
        expect(wrapper.find('.form-stub').attributes()).toMatchObject({ 'data-open': 'true', 'data-code': '' });

        const row = wrapper.findAll('tbody tr')[0];
        await row.find('button[aria-haspopup="menu"]').trigger('click');
        await row.find('[data-test="edit"]').trigger('click');
        expect(wrapper.find('.form-stub').attributes('data-code')).toBe('c-1');
    });

    it('"ver detalhes" abre o drawer com o item', async () => {
        const wrapper = mountPage();
        await wrapper.findAll('tbody tr')[1].find('button[title="action_view"]').trigger('click');

        expect(wrapper.find('.drawer-stub').attributes()).toMatchObject({ 'data-open': 'true', 'data-code': 'c-2' });
    });

    it('"Registros a revisar" abre a gaveta e só então busca a lista (parcial, sem recarregar a página)', async () => {
        const wrapper = mountPage();
        await wrapper.find('[data-test="kpi-review"]').element.closest('button').click();
        await flushPromises();

        expect(wrapper.find('.review-stub').attributes()).toMatchObject({
            'data-open': 'true',
            'data-loading': 'true',
        });
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['reviewRecords'] }));

        router.reload.mock.calls[0][0].onFinish();
        await flushPromises();
        expect(wrapper.find('.review-stub').attributes('data-loading')).toBe('false');
    });

    it('sem registros a revisar o card só informa (não é botão)', () => {
        const wrapper = mountPage({ stats: { review: 0 } });
        expect(wrapper.find('[data-test="kpi-review"]').element.closest('button')).toBeNull();
    });

    describe('importação', () => {
        it('aba Importações: exige arquivo; envia o zip/CSVs como multipart', async () => {
            const wrapper = mountPage();
            await wrapper.find('[data-test="open-import"]').trigger('click');

            const submit = wrapper.find('[data-test="import-submit"]');
            expect(submit.attributes('disabled')).toBeDefined();

            const zip = new File(['PK'], 'CID10CSV.zip', { type: 'application/zip' });
            const input = wrapper.find('#cid-import-files');
            Object.defineProperty(input.element, 'files', { value: [zip] });
            await input.trigger('input');
            expect(submit.attributes('disabled')).toBeUndefined();

            await wrapper.find('form').trigger('submit');
            expect(form.current.post).toHaveBeenCalledWith(
                '/_routes/manager.cid10.imports.store',
                expect.objectContaining({ forceFormData: true }),
            );
            expect(form.current.files).toEqual([zip]);
        });

        it('erro de validação de arquivo aparece no campo', async () => {
            const wrapper = mountPage();
            form.current.errors = { 'files.0': 'Tipo de arquivo inválido.' };
            await flushPromises();

            expect(wrapper.find('[data-test="import-error"]').text()).toBe('Tipo de arquivo inválido.');
        });

        it('importação em andamento: abre na aba, mostra fase/linhas e bloqueia novo envio', () => {
            const wrapper = mountPage({ runningImport: running });
            const bar = wrapper.find('.progress-bar');

            expect(bar.attributes('style')).toContain('width: 48%');
            expect(bar.classes()).toContain('progress-bar-animated');
            expect(wrapper.text()).toContain('Atualizando o catálogo');
            expect(wrapper.text()).toContain('6.000 de 12.451 códigos');
            expect(wrapper.find('[data-test="import-submit"]').attributes('disabled')).toBeDefined();
        });

        it('evento WebSocket atualiza a barra; ao terminar mostra o resultado e recarrega catálogo e números', async () => {
            const wrapper = mountPage({ runningImport: running });

            live.importRef.value = { ...running, processed_rows: 11000, progress: 88 };
            await flushPromises();
            expect(wrapper.find('.progress-bar').attributes('style')).toContain('width: 88%');

            live.importRef.value = {
                ...running,
                status: 'done',
                status_color: 'success',
                progress: 100,
                is_done: true,
                read_count: 12451,
                created_count: 12,
                corrected_count: 7,
                kept_edited_count: 1,
                skipped_invalid: 0,
            };
            live.options.onDone();
            await flushPromises();

            expect(router.reload).toHaveBeenCalledWith({ only: ['imports', 'stats', 'codes', 'categories'] });
            expect(wrapper.find('.progress-bar').classes()).toContain('bg-success');
            expect(wrapper.find('[data-test="import-progress"]').text()).toContain('12.451');
        });

        it('sem tempo real: avisa e "Atualizar status" relê o estado (sem polling)', async () => {
            live.connected = false;
            const wrapper = mountPage({ runningImport: running });

            await wrapper.find('[data-test="import-resync"]').trigger('click');
            expect(live.resync).toHaveBeenCalled();
            live.connected = true;
        });

        it('parada além do limite: avisa e cancela pela rota própria', async () => {
            const wrapper = mountPage({
                runningImport: { ...running, status: 'pending', idle_seconds: 120, stall_after_seconds: 90 },
            });

            await wrapper.find('[data-test="import-cancel"]').trigger('click');
            expect(router.post).toHaveBeenCalledWith(
                '/_routes/manager.cid10.imports.cancel/imp-1',
                {},
                expect.any(Object),
            );
        });

        it('histórico mostra quem importou, arquivos reconhecidos e o resultado', () => {
            const wrapper = mountPage({
                imports: [
                    {
                        ...running,
                        id: 'imp-0',
                        status: 'done',
                        status_label: 'Concluída',
                        status_color: 'success',
                        is_done: true,
                        user: 'Ana Admin',
                        created_at: '05/10/2026 10:00',
                        files: [{ name: 'CID-10-SUBCATEGORIAS.CSV', kind: 'subcategorias', converted: true }],
                        read_count: 12451,
                        created_count: 3,
                        corrected_count: 7,
                        official_updated_count: 0,
                        kept_edited_count: 1,
                        skipped_invalid: 2,
                    },
                ],
            });
            const row = wrapper.find('[data-test="import-row"]').text();

            expect(row).toContain('Ana Admin');
            expect(row).toContain('CID-10-SUBCATEGORIAS.CSV (kind_subcategorias · converted_hint)');
            expect(row).toContain('12.451');
        });
    });
});
