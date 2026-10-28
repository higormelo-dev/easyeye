import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import MedicinesIndex from '@/Pages/Panel/Manager/Medicines/Index.vue';

/**
 * Manager → Medicamentos (catálogo global do receituário): renderização do
 * catálogo, regras de ação por origem (CMED só edita posologia), filtros
 * server-side e acompanhamento automático de importação em andamento.
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
vi.mock('@/Pages/Panel/Manager/Medicines/MedicineFormModal.vue', () => ({
    default: {
        props: ['open', 'medicine'],
        template: '<div class="form-stub" :data-open="String(open)" :data-medicine="medicine?.id ?? \'\'" />',
    },
}));
vi.mock('@/Pages/Panel/Manager/Medicines/MedicineDetailDrawer.vue', () => ({
    default: {
        props: ['open', 'medicine', 'hasNextPending', 'approveMessage', 'approveError'],
        emits: ['approve'],
        template: `<div class="drawer-stub" :data-open="String(open)" :data-medicine="medicine?.id ?? ''"
            :data-has-next="String(!!hasNextPending)" :data-message="approveMessage" :data-error="approveError">
            <button class="drawer-approve" @click="$emit('approve', medicine, false)" />
            <button class="drawer-approve-next" @click="$emit('approve', medicine, true)" /></div>`,
    },
}));
// Lote de posologia por IA: painel e modal têm testes próprios (o painel usa
// useImportProgress — aqui fica só o contrato com a página).
vi.mock('@/Pages/Panel/Manager/Medicines/PosologyBatchPanel.vue', () => ({
    default: {
        props: ['running', 'batches'],
        emits: ['progress'],
        data: () => ({ shown: null }),
        mounted() {
            this.$emit('progress', this.running);
        },
        methods: {
            show(batch) {
                this.shown = batch;
                this.$emit('progress', batch);
            },
        },
        template: '<div class="panel-stub" :data-running="running?.id ?? \'\'" :data-shown="shown?.id ?? \'\'" />',
    },
}));
vi.mock('@/Pages/Panel/Manager/Medicines/PosologyBatchModal.vue', () => ({
    default: {
        props: ['open', 'filters', 'aiProviders'],
        emits: ['close', 'started', 'running'],
        template: `<div class="batch-modal-stub" :data-open="String(open)" :data-filters="JSON.stringify(filters)">
            <button class="batch-started" @click="$emit('started')" />
            <button class="batch-running" @click="$emit('running', { id: 'b-9', progress: 10, is_done: false })" /></div>`,
    },
}));
vi.mock('@/Components/Panel/ConfirmationWithReasonModal.vue', () => ({
    default: {
        props: ['open', 'title', 'message', 'error'],
        emits: ['confirm', 'close'],
        template: `<div class="reason-stub" :data-open="String(open)">{{ message }}
            <button class="reason-confirm" @click="$emit('confirm', 'Cadastro duplicado do item da CMED.')" /></div>`,
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
        global: { stubs: { teleport: true } },
        props: {
            medicines: { data: rows, links: [], last_page: 1, total: 2 },
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

    // Ações iguais às de Manager → Planos: ver detalhes + menu (⋮).
    async function openMenu(row) {
        await row.find('button[aria-haspopup="menu"]').trigger('click');
        return row.findAll('.dropdown-item').map((item) => item.text());
    }

    it('cada linha tem "ver detalhes" e o menu de ações', () => {
        const [cmedRow, manualRow] = mountPage().findAll('tbody tr');

        for (const row of [cmedRow, manualRow]) {
            expect(row.find('button[title="action_view"]').exists()).toBe(true);
            expect(row.find('button[aria-haspopup="menu"]').attributes('aria-label')).toBe('more_actions');
        }
    });

    it('menu da CMED só tem "editar posologia"; curado tem editar, desativar e excluir', async () => {
        const [cmedRow, manualRow] = mountPage().findAll('tbody tr');

        expect(await openMenu(cmedRow)).toEqual(['edit_posology']);
        expect(await openMenu(manualRow)).toEqual(['edit', 'deactivate', 'delete']);
    });

    it('editar (no menu) abre o formulário com o item', async () => {
        const wrapper = mountPage();
        const row = wrapper.findAll('tbody tr')[0];
        await openMenu(row);
        await row.find('.dropdown-item').trigger('click');

        expect(wrapper.find('.form-stub').attributes('data-medicine')).toBe('cmed-1');
        expect(wrapper.find('.form-stub').attributes('data-open')).toBe('true');
    });

    it('"ver detalhes" abre o drawer com o item', async () => {
        const wrapper = mountPage();
        await wrapper.findAll('tbody tr')[1].find('button[title="action_view"]').trigger('click');

        expect(wrapper.find('.drawer-stub').attributes('data-open')).toBe('true');
        expect(wrapper.find('.drawer-stub').attributes('data-medicine')).toBe('man-1');
    });

    it('excluir pede justificativa e envia o motivo ao servidor', async () => {
        const wrapper = mountPage();
        const row = wrapper.findAll('tbody tr')[1];
        await openMenu(row);
        await row.findAll('.dropdown-item').at(-1).trigger('click');

        expect(wrapper.find('.reason-stub').attributes('data-open')).toBe('true');
        expect(router.delete).not.toHaveBeenCalled();

        await wrapper.find('.reason-confirm').trigger('click');
        expect(router.delete).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ data: { reason: 'Cadastro duplicado do item da CMED.' } }),
        );
    });

    it('"Situação na CMED" e "Status" em colunas separadas (situação com explicação no tooltip)', async () => {
        const base = { source: 'cmed', source_label: 'CMED/Anvisa', is_ophthalmic: false };
        const medicines = {
            data: [
                {
                    ...base,
                    id: 'a',
                    name: 'SEM VENDA',
                    active: true,
                    is_marketed: false,
                    cmed_situation: 'not_marketed',
                },
                { ...base, id: 'b', name: 'COM VENDA', active: true, is_marketed: true, cmed_situation: 'marketed' },
                {
                    ...base,
                    id: 'c',
                    name: 'SAIU DA LISTA',
                    active: false,
                    is_marketed: true,
                    cmed_situation: 'left_list',
                },
                {
                    id: 'd',
                    name: 'CURADO',
                    source: 'manual',
                    source_label: 'Curado',
                    active: true,
                    cmed_situation: null,
                },
            ],
            links: [],
            total: 4,
        };
        const wrapper = mountPage({ medicines });
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        const cells = (i) => wrapper.findAll('tbody tr')[i].findAll('td');
        const situationIdx = headers.findIndex((h) => h.includes('col_cmed_situation'));
        const statusIdx = headers.findIndex((h) => h.includes('col_status'));

        expect(situationIdx).toBeGreaterThan(-1);
        expect(statusIdx).toBe(situationIdx + 1);

        expect(cells(0)[situationIdx].text()).toBe('not_marketed');
        expect(cells(0)[situationIdx].find('.badge').attributes('title')).toBe('not_marketed_hint');
        expect(cells(0)[statusIdx].text()).toBe('status_active');
        expect(cells(1)[situationIdx].text()).toBe('cmed_marketed');
        expect(cells(2)[situationIdx].text()).toBe('cmed_left_list');
        expect(cells(2)[situationIdx].find('.badge').attributes('title')).toBe('cmed_left_list_hint');
        expect(cells(2)[statusIdx].text()).toBe('status_inactive');
        // Curado: não se aplica (texto só para leitor de tela).
        expect(cells(3)[situationIdx].find('.badge').exists()).toBe(false);
        expect(cells(3)[situationIdx].find('.visually-hidden').text()).toBe('cmed_not_applicable');

        // Mesma situação nos cards (só onde se aplica).
        await wrapper.find('.to-cards').trigger('click');
        expect(wrapper.findAll('.card.card-body .badge-soft-warning')).toHaveLength(1);
        expect(wrapper.text()).toContain('cmed_left_list');
        localStorage.removeItem('mgr_medicines_view');
    });

    it('filtro e ordenação pela situação na CMED vão para o servidor', async () => {
        const wrapper = mountPage();

        await wrapper.find('select[aria-label="filter_cmed_situation"]').setValue('not_marketed');
        await flushPromises();
        expect(router.get).toHaveBeenLastCalledWith(
            expect.any(String),
            expect.objectContaining({ cmed_situation: 'not_marketed' }),
            expect.objectContaining({ only: ['medicines', 'filters'] }),
        );

        const header = wrapper.findAll('th').find((th) => th.text().includes('col_cmed_situation'));
        await header.find('button').trigger('click');
        expect(router.get).toHaveBeenLastCalledWith(
            expect.any(String),
            expect.objectContaining({ sort: 'cmed_situation', direction: 'asc' }),
            expect.any(Object),
        );
    });

    it('clicar na coluna ordena no servidor, mantendo os filtros', async () => {
        const wrapper = mountPage({ filters: { source: 'manual' } });
        const labHeader = wrapper.findAll('th').find((th) => th.text().includes('col_laboratory'));
        await labHeader.find('button').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ sort: 'laboratory', direction: 'asc', source: 'manual' }),
            expect.objectContaining({ only: ['medicines', 'filters'] }),
        );
    });

    it('alterna para cards (mesmos itens) e lembra a escolha', async () => {
        localStorage.removeItem('mgr_medicines_view');
        const wrapper = mountPage();
        await wrapper.find('.to-cards').trigger('click');

        expect(wrapper.find('table.table-nowrap').exists()).toBe(false);
        expect(wrapper.findAll('.card.card-body h6').map((h) => h.text())).toEqual([
            'PREDOPTIC 10 MG/ML',
            'TOBRAMICINA 0,3%',
        ]);
        expect(localStorage.getItem('mgr_medicines_view')).toBe('cards');
        localStorage.removeItem('mgr_medicines_view');
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

    it('importação: padrão é baixar da CMED (sem arquivo); no envio exige a planilha; bloqueia com outra em andamento', async () => {
        const wrapper = mountPage();
        const submit = () => wrapper.find('form button[type="submit"]');

        expect(form.current.source).toBe('cmed');
        expect(submit().attributes('disabled')).toBeUndefined();
        expect(submit().text()).toContain('import_submit_cmed');

        await wrapper.find('#imp-mode-upload').setValue(true);
        expect(submit().attributes('disabled')).toBeDefined();

        form.current.cmed_file = new File(['x'], 'lista.xlsx');
        await flushPromises();
        expect(submit().attributes('disabled')).toBeUndefined();

        await wrapper.setProps({ runningImport: running });
        expect(submit().attributes('disabled')).toBeDefined();
    });

    it('"Atualizar agora" envia só a origem e o "forçar" (sem arquivos)', async () => {
        const wrapper = mountPage();
        form.current.cmed_file = new File(['x'], 'sobrou.xlsx'); // escolhido antes de voltar pro download
        await wrapper.find('#imp-force').setValue(true);

        await wrapper.find('form').trigger('submit');

        expect(form.current.post).toHaveBeenCalledWith(
            expect.stringContaining('medicines.imports.store'),
            expect.any(Object),
        );
        expect(form.transformed({ ...form.current })).toEqual({ source: 'cmed', force: true });
    });

    it('envio manual manda os arquivos com a origem "upload"', async () => {
        const wrapper = mountPage();
        await wrapper.find('#imp-mode-upload').setValue(true);
        const file = new File(['x'], 'lista.xlsx');
        form.current.cmed_file = file;
        await flushPromises();

        await wrapper.find('form').trigger('submit');

        expect(form.transformed({ ...form.current })).toEqual({
            source: 'upload',
            cmed_file: file,
            open_data_file: null,
        });
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

    describe('carga na fila / parada / sem tempo real / resultado da sincronização', () => {
        const queued = {
            ...running,
            source: 'cmed',
            source_label: 'Download da CMED/Anvisa',
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
            expect(wrapper.text()).toContain('Download da CMED/Anvisa');
            expect(wrapper.text()).not.toContain('result_skipped_hospital');
        });

        it('parada além do limite: avisa e cancela pela rota própria', async () => {
            const wrapper = mountPage({ runningImport: { ...queued, idle_seconds: 300 } });

            expect(wrapper.find('.alert').classes()).toContain('alert-warning');
            await wrapper
                .findAll('button')
                .find((b) => b.text().includes('import_cancel'))
                .trigger('click');

            expect(router.post).toHaveBeenCalledWith(
                expect.stringContaining('medicines.imports.cancel'),
                {},
                expect.objectContaining({ preserveScroll: true }),
            );
        });

        it('sem tempo real: "Atualizar status" relê o estado', async () => {
            live.connected = false;
            const wrapper = mountPage({ runningImport: queued });

            await wrapper
                .findAll('button')
                .find((b) => b.text().includes('import_refresh_status'))
                .trigger('click');

            expect(live.resync).toHaveBeenCalledOnce();
            live.connected = true;
        });

        it('mostra a data da lista CMED e o aviso da sincronização (ex.: nada mudou)', () => {
            const wrapper = mountPage({
                imports: [
                    {
                        ...queued,
                        id: 'imp-9',
                        status: 'done',
                        status_label: 'Concluída',
                        status_color: 'success',
                        is_done: true,
                        list_published_at: '23/09/2026',
                        notice: 'Nada mudou desde a última carga.',
                        created_at: '03/10/2026 05:00',
                    },
                ],
            });
            const history = wrapper.findAll('tbody').at(-1).text();

            expect(history).toContain('list_published');
            expect(history).toContain('Nada mudou desde a última carga.');
            expect(history).toContain('Download da CMED/Anvisa');
        });
    });

    describe('posologia por IA em lote', () => {
        const AI = [{ code: 'openai', label: 'OpenAI', model: 'gpt-4o' }];
        const runningBatch = {
            id: 'b-1',
            progress: 40,
            is_done: false,
            channel: 'manager.medicines.posology-batches.b-1',
        };

        it('sem IA configurada o botão não aparece', () => {
            expect(mountPage().find('[data-test="batch-button"]').exists()).toBe(false);
        });

        it('botão abre a prévia com os filtros APLICADOS; ao iniciar vai para a aba do lote', async () => {
            const filters = { search: 'pred', source: 'cmed', ophthalmic: true, sort: 'name', direction: 'asc' };
            const wrapper = mountPage({ aiProviders: AI, filters });

            expect(wrapper.find('.batch-modal-stub').attributes('data-open')).toBe('false');
            await wrapper.find('[data-test="batch-button"]').trigger('click');

            const modal = wrapper.find('.batch-modal-stub');
            expect(modal.attributes('data-open')).toBe('true');
            expect(JSON.parse(modal.attributes('data-filters'))).toEqual(filters);

            await wrapper.find('.batch-started').trigger('click');
            expect(wrapper.find('.batch-modal-stub').attributes('data-open')).toBe('false');
            expect(wrapper.find('[data-test="tab-posology"]').classes()).toContain('active');
        });

        it('com um lote rodando o botão mostra o progresso e leva à aba (não abre outro)', async () => {
            const wrapper = mountPage({ aiProviders: AI, runningPosologyBatch: runningBatch });
            await flushPromises();
            const button = wrapper.find('[data-test="batch-button"]');

            expect(button.text()).toContain('40%');
            expect(button.find('.spinner-border').exists()).toBe(true);

            await button.trigger('click');
            expect(wrapper.find('.batch-modal-stub').attributes('data-open')).toBe('false');
            expect(wrapper.find('[data-test="tab-posology"]').classes()).toContain('active');
        });

        it('a prévia achou um lote já rodando: o painel passa a mostrar o progresso dele', async () => {
            const wrapper = mountPage({ aiProviders: AI });
            await wrapper.find('.batch-running').trigger('click');
            await flushPromises();

            expect(wrapper.find('.panel-stub').attributes('data-shown')).toBe('b-9');
            expect(wrapper.find('[data-test="batch-button"]').text()).toContain('10%');
        });

        it('filtro "Posologia" consulta o servidor', async () => {
            const wrapper = mountPage();
            await wrapper.find('[data-test="filter-posology"]').setValue('ai_pending');
            await flushPromises();

            expect(router.get).toHaveBeenCalledWith(
                expect.any(String),
                expect.objectContaining({ posology: 'ai_pending' }),
                expect.objectContaining({ only: ['medicines', 'filters'] }),
            );
        });

        it('selo "IA – revisar" na tabela e nos cards só para a posologia não revisada', async () => {
            const aiRows = [
                { ...rows[0], dosage: '1 gota', posology_pending_review: true, posology_source: 'ai' },
                { ...rows[1], posology_pending_review: false, posology_source: 'manual' },
            ];
            const wrapper = mountPage({ medicines: { data: aiRows, links: [], last_page: 1, total: 2 } });

            const [aiRow, manualRow] = wrapper.findAll('tbody tr');
            expect(aiRow.find('[data-test="ai-review-badge"]').text()).toContain('posology_ai_badge');
            expect(aiRow.find('[data-test="ai-review-badge"]').attributes('title')).toBe('posology_ai_badge_hint');
            expect(manualRow.find('[data-test="ai-review-badge"]').exists()).toBe(false);

            await wrapper.find('.to-cards').trigger('click');
            expect(wrapper.findAll('[data-test="ai-review-badge"]')).toHaveLength(1);
            try {
                localStorage.removeItem('mgr_medicines_view');
            } catch {
                // sem armazenamento
            }
        });
    });
});

describe('revisão da posologia gerada por IA em um clique', () => {
    const pending = (id, name) => ({
        id,
        name,
        source: 'cmed',
        source_label: 'CMED/Anvisa',
        is_ophthalmic: true,
        is_marketed: true,
        active: true,
        dosage: '1 gota',
        frequency: '4x ao dia',
        posology_pending_review: true,
    });
    const reviewRows = [pending('p-1', 'ACU FRESH'), rows[1], pending('p-2', 'LACRIFILM')];

    beforeEach(() => vi.clearAllMocks());

    it('aviso com a contagem; "Revisar agora" filtra as pendentes e abre a primeira', async () => {
        const wrapper = mountPage({ stats: { ai_pending: 2 } });

        expect(wrapper.find('[data-test="review-banner"]').text()).toContain('posology_review_banner');

        await wrapper.find('[data-test="review-now"]').trigger('click');
        await flushPromises();
        expect(router.get.mock.calls.at(-1)[1]).toMatchObject({ posology: 'ai_pending' });

        // A lista volta filtrada: abre a primeira pendente.
        await wrapper.setProps({
            medicines: { data: [reviewRows[0], reviewRows[2]], links: [], last_page: 1, total: 2 },
        });
        await flushPromises();
        expect(wrapper.find('.drawer-stub').attributes('data-open')).toBe('true');
        expect(wrapper.find('.drawer-stub').attributes('data-medicine')).toBe('p-1');
    });

    it('sem pendentes, não mostra o aviso', () => {
        expect(
            mountPage({ stats: { ai_pending: 0 } })
                .find('[data-test="review-banner"]')
                .exists(),
        ).toBe(false);
    });

    it('aprovar na linha da tabela chama a rota de aprovação daquele item', async () => {
        const wrapper = mountPage({ medicines: { data: reviewRows, links: [], last_page: 1, total: 3 } });
        const approveButtons = wrapper.findAll('[data-test="row-approve"]');

        expect(approveButtons).toHaveLength(2); // só as pendentes
        await approveButtons[0].trigger('click');

        expect(router.post.mock.calls[0][0]).toContain('manager.medicines.posology.approve');
        expect(router.post.mock.calls[0][0]).toContain('p-1');
    });

    it('"Aprovar e próximo" aprova e abre a próxima pendente; na última avisa que acabou', async () => {
        router.post.mockImplementation((url, data, options) => {
            options.onSuccess?.();
            options.onFinish?.();
        });
        const wrapper = mountPage({ medicines: { data: reviewRows, links: [], last_page: 1, total: 3 } });

        await wrapper.findAll('[data-test="row-approve"]')[0].trigger('click'); // garante que nada quebra fora da gaveta
        // Abre a gaveta na primeira pendente pelo botão "ver detalhes".
        await wrapper.findAll('tbody tr')[0].find('button[title="action_view"]').trigger('click');
        expect(wrapper.find('.drawer-stub').attributes('data-has-next')).toBe('true');

        await wrapper.find('.drawer-approve-next').trigger('click');
        await flushPromises();
        expect(wrapper.find('.drawer-stub').attributes('data-medicine')).toBe('p-2');

        // p-2 aprovada e servidor devolve as duas já revisadas: não há próxima.
        const reviewed = reviewRows.map((r) => ({ ...r, posology_pending_review: false }));
        router.post.mockImplementation((url, data, options) => {
            options.onSuccess?.();
            options.onFinish?.();
        });
        await wrapper.setProps({ medicines: { data: reviewed, links: [], last_page: 1, total: 3 } });
        await wrapper.find('.drawer-approve-next').trigger('click');
        await flushPromises();
        expect(wrapper.find('.drawer-stub').attributes('data-message')).toBe('posology_review_last');
        router.post.mockReset();
    });

    it('erro do servidor (já não está pendente) aparece na gaveta', async () => {
        router.post.mockImplementation((url, data, options) => {
            options.onError?.({ posology: 'Esta posologia não está mais aguardando revisão.' });
            options.onFinish?.();
        });
        const wrapper = mountPage({ medicines: { data: reviewRows, links: [], last_page: 1, total: 3 } });
        await wrapper.findAll('tbody tr')[0].find('button[title="action_view"]').trigger('click');

        await wrapper.find('.drawer-approve').trigger('click');
        await flushPromises();
        expect(wrapper.find('.drawer-stub').attributes('data-error')).toContain('não está mais aguardando');
        router.post.mockReset();
    });
});
