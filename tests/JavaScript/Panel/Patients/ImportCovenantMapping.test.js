import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, ref } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';
import Mapping from '@/Components/Panel/PatientImportCovenantMapping.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

vi.mock('@inertiajs/vue3', async (original) => ({ ...(await original()), usePage: () => ({ props: {} }) }));
const SearchStub = defineComponent({
    props: {
        id: { type: String, default: '' },
        modelValue: { type: [String, Number], default: null },
        options: { type: Array, default: () => [] },
        disabled: Boolean,
        invalid: Boolean,
        ariaLabelledby: { type: String, default: '' },
        ariaDescribedby: { type: String, default: '' },
        remoteSearchUrl: { type: String, default: '' },
    },
    emits: ['update:modelValue', 'option-selected'],
    setup(_, { expose }) {
        const control = ref(null);
        expose({ focus: () => control.value.focus() });
        return { control };
    },
    template: `<select ref="control" :id="id" :disabled="disabled" :aria-invalid="invalid ? 'true' : 'false'" :aria-labelledby="ariaLabelledby" :aria-describedby="ariaDescribedby" :value="modelValue" @change="$emit('update:modelValue', $event.target.value); $emit('option-selected', options.find(o => o.id === $event.target.value))"><option value="">Escolha</option><option v-for="option in options" :key="option.id" :value="option.id">{{ option.label ?? option.name }}</option></select>`,
});
const covenant = {
    id: 'catalog-a',
    label: 'CATÁLOGO A',
    sub_label: 'CV-001',
    name: 'CATÁLOGO A',
    code: 'CV-001',
    is_own: true,
    active: true,
};
const plan = { id: 'plan-a', label: 'PLANO A', sub_label: 'Ambulatorial', name: 'PLANO A' };
const report = {
    data: {
        import: {
            id: 'import-a',
            original_name: 'fixture.csv',
            status: 'done',
            is_reprocess: false,
            reprocess_of_id: null,
            files_expire_at: '2026-11-22T12:00:00-03:00',
            files_expired: false,
            report_available: true,
        },
        permissions: { can_create_covenant: true, can_reprocess: true },
        totals: { file_rows: 12, failed_rows: 9, failed_rows_exact: true, unmatched_names: 2, mapped_names: 0 },
        items: [
            {
                source_name: 'CONVÊNIO A',
                source_name_normalized: 'opacoa1',
                rows: 6,
                failed_rows: 6,
                status: 'unmatched',
                covenant: null,
                plan: null,
                mapping: null,
                retryable: true,
            },
            {
                source_name: 'CONVÊNIO B',
                source_name_normalized: 'opacob2',
                rows: 3,
                failed_rows: 3,
                status: 'inactive',
                covenant: { ...covenant, active: false },
                plan: null,
                mapping: null,
                retryable: true,
            },
            {
                source_name: 'CATÁLOGO A',
                source_name_normalized: 'opacoc3',
                rows: 3,
                failed_rows: 0,
                status: 'found',
                covenant,
                plan: null,
                mapping: null,
                retryable: false,
            },
        ],
    },
};
const t = {
    names_capped:
        'A lista de convênios foi limitada a 1.000 nomes distintos; resolva estes e reprocesse para ver os demais.',
    target_unavailable_hint: 'Indisponível: escolha outro convênio.',
    no_options: 'Nenhuma opção',
    no_search_results: 'Nenhuma opção encontrada',
    title: 'De-para de convênios',
    subtitle: 'Associe os nomes da planilha ao catálogo desta clínica.',
    status_found: 'Encontrado',
    status_unmatched: 'Não encontrado',
    status_inactive: 'Inativo',
    status_mapped: 'Mapeado',
    status_particular: 'Particular',
    status_invalid_mapping: 'De-para inválido',
    rows: ':count linhas',
    failed_rows: ':count com erro de convênio',
    target_covenant: 'Convênio de destino',
    target_plan: 'Plano de destino (opcional)',
    plan_hint:
        'Opcional. Se preenchido, este plano vale para todas as linhas deste convênio e prevalece sobre a coluna plano da planilha. Se vazio, a planilha decide; plano não encontrado gera aviso e o paciente entra sem plano.',
    plan_override_hint: 'O plano escolhido prevalece sobre a planilha.',
    particular_hint: 'Particular: plano e carteirinha serão ignorados.',
    action_map: 'Mapear para existente',
    action_create: 'Criar convênio',
    action_particular: 'Importar como Particular',
    action_clear: 'Remover de-para',
    bulk_apply: 'Aplicar aos selecionados',
    select_pending: 'Selecionar todos os pendentes',
    save: 'Salvar mapeamentos',
    save_and_reprocess: 'Salvar e reprocessar',
    reprocess: 'Reprocessar linhas com erro de convênio',
    reprocess_confirm:
        'Salvar os mapeamentos e reprocessar somente as linhas que falharam por convênio? A importação original será preservada.',
    no_pending: 'Todos os convênios da planilha foram encontrados.',
    expired: 'O arquivo desta importação expirou; envie novamente para reprocessar.',
    file_missing: 'Arquivo original indisponível; envie novamente para reprocessar.',
    report_unavailable: 'Relatório indisponível; importe novamente.',
    mapping_saved: 'Mapeamentos salvos.',
    reprocess_started: 'Reprocessamento iniciado.',
    create_requires_settings:
        'Criar convênio exige permissão para gerenciar Configurações. Você pode mapear para um existente ou usar Particular.',
    created_covenant: 'Convênio criado.',
    reused_covenant: 'Convênio existente reutilizado.',
    clear_confirm: 'Remover os de-para selecionados? Essa alteração também vale para importações futuras.',
    target_unavailable: 'Indisponível: escolha outro convênio.',
    plan_unavailable: 'Plano indisponível: escolha outro plano.',
    no_retry_lines: 'Não há linhas com erro de convênio para reprocessar.',
    reprocess_running: 'Já existe uma importação em andamento. Aguarde a conclusão.',
    source_not_in_file: 'Este convênio não consta no arquivo desta importação.',
    invalid_action: 'Escolha uma ação válida.',
    filter_pending: 'Pendentes',
    filter_all: 'Todos',
    filter_found: 'Encontrados',
    search: 'Buscar nome na planilha',
    bulk_action: 'Ação em lote',
    select_covenant: 'Selecione um convênio.',
    search_failed: 'Não foi possível carregar as opções. Tente buscar novamente.',
    save_failed: 'Não foi possível salvar. Tente novamente.',
    draft_applied: ':count de-para preparados; salve para confirmar.',
    persistent_hint:
        'Os de-para e convênios criados ficam salvos nesta clínica, mesmo que a importação seja cancelada.',
    create_hint: 'Criar convênio não cria plano. Cadastre planos em Configurações › Convênios › Planos.',
    select: 'Selecionar',
    source_name: 'Nome na planilha',
    counts: 'Linhas no arquivo',
    status: 'Status',
    edit: 'Editar',
    action: 'Ação',
    apply: 'Aplicar',
    pending_save: 'Alteração ainda não salva',
    no_results: 'Nenhum convênio neste filtro.',
    empty_source: 'Sem convênio — entra como Particular',
    all_particular: 'Mapear todos os não encontrados para Particular',
    particular_confirm:
        'Mapear todos os convênios não encontrados para Particular? Plano e carteirinha serão ignorados.',
    cancel: 'Cancelar',
    confirm: 'Confirmar',
    approximate: 'aprox.',
    resolve: 'Resolver convênios',
    close: 'Fechar resolução',
    reprocess_badge: 'Reprocessamento',
    reprocess_of: 'Reprocessamento de #:id',
    available_until: 'Relatório disponível até :date',
    expired_label: 'expirado',
    unmatched_confirm:
        ':count convênios ainda não encontrados; as linhas deles falharão e poderão ser reprocessadas depois.',
    truncated: 'Prévia limitada aos primeiros 500 convênios; abra o relatório completo para revisar os demais.',
    too_many_mappings: 'Salve até 1.000 mapeamentos por vez.',
};
const urls = {
    show: '/imports/import-a/covenants',
    update: '/imports/import-a/covenants',
    reprocess: '/imports/import-a/covenants/reprocess',
    covenantSearch: '/covenants/search',
    covenantPlanSearch: '/covenant-plans/search',
};
const wrappers = [];
const response = (body, status = 200) => ({ ok: status < 400, status, json: async () => body });
async function render(extra = {}, realSearch = false) {
    const wrapper = mount(Mapping, {
        attachTo: document.body,
        props: {
            importId: 'import-a',
            items: structuredClone(report.data.items),
            totals: report.data.totals,
            permissions: report.data.permissions,
            urls,
            mode: 'reprocess',
            t,
            ...extra,
        },
        global: { stubs: { SearchSelect: realSearch ? false : SearchStub, teleport: true } },
    });
    wrappers.push(wrapper);
    await flushPromises();
    return wrapper;
}
const writeCalls = () => fetch.mock.calls.filter(([, options]) => options?.method === 'PUT');
async function applyBulk(wrapper, action = 'particular') {
    await wrapper.get('[data-test="select-pending"]').setValue(true);
    await wrapper.get('[data-test="bulk-action"]').setValue(action);
    await wrapper.get('[data-test="bulk-apply"]').trigger('click');
}
beforeEach(() => {
    fetch.mockReset();
    fetch.mockImplementation(async (url, options) => {
        if (options?.method === 'PUT')
            return response({
                ...structuredClone(report),
                meta: { saved: 2, created_covenants: 0, reused_covenants: 0, cleared: 0 },
            });
        if (options?.method === 'POST')
            return response(
                {
                    data: {
                        import_id: 'child-a',
                        status: 'pending',
                        total_rows: 9,
                        channel: 'imports.patients.child-a',
                    },
                },
                202,
            );
        return response({ data: url.includes('covenant-plans') ? [plan] : [covenant] });
    });
});
afterEach(() => {
    wrappers.splice(0).forEach((w) => w.unmount());
    vi.restoreAllMocks();
});

describe('Patient import covenant mapping', () => {
    it('permite quebrar o texto longo da ação Particular sem reduzir a densidade', async () => {
        const wrapper = await render();
        const button = wrapper.findAll('button').find((node) => node.text() === t.all_particular);
        expect(button.classes()).toContain('text-wrap');
        expect(button.classes()).toEqual(expect.arrayContaining(['btn', 'btn-outline-secondary']));
        expect(button.classes()).not.toContain('btn-sm');
        expect(button.attributes('style')).toBeUndefined();
    });

    it('mantém densidade padrão inclusive na confirmação teleportada', async () => {
        const wrapper = await render();
        expect(wrapper.get('.covenant-mapping').attributes('data-ee-density')).toBe('default');
        expect(wrapper.getComponent({ name: 'CenteredModal' }).props('density')).toBe('default');
        expect(wrapper.html()).not.toMatch(/\bbtn-sm\b/);
    });
    it('renderiza status textual e contagens; filtra pendentes, encontrados e busca local', async () => {
        const wrapper = await render();
        expect(wrapper.findAll('[data-test="mapping-row"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Não encontrado');
        expect(wrapper.text()).toContain('Inativo');
        expect(wrapper.text()).toContain('6 linhas');
        await wrapper.get('[data-filter="found"]').trigger('click');
        expect(wrapper.text()).toContain('Encontrado');
        await wrapper.get('[data-filter="all"]').trigger('click');
        expect(wrapper.findAll('[data-test="mapping-row"]')).toHaveLength(3);
        await wrapper.get('[data-test="mapping-search"]').setValue('CONVÊNIO B');
        expect(wrapper.findAll('[data-test="mapping-row"]')).toHaveLength(1);
    });
    it('selecionar pendentes inclui indeterminate e nao seleciona encontrados', async () => {
        const wrapper = await render();
        await wrapper.get('[data-test="row-select"]').setValue(true);
        expect(wrapper.get('[data-test="select-pending"]').element.indeterminate).toBe(true);
        await wrapper.get('[data-test="select-pending"]').setValue(true);
        expect(wrapper.findAll('[data-test="row-select"]').every((c) => c.element.checked)).toBe(true);
        await wrapper.get('[data-filter="all"]').trigger('click');
        expect(wrapper.findAll('[data-test="row-select"]')[2].element.checked).toBe(false);
    });
    it('Particular em lote gera somente source_name/action e salva com CSRF', async () => {
        const wrapper = await render();
        await applyBulk(wrapper);
        await wrapper.get('[data-test="mapping-save"]').trigger('click');
        await flushPromises();
        expect(JSON.parse(writeCalls()[0][1].body)).toEqual({
            mappings: report.data.items
                .slice(0, 2)
                .map((item) => ({ source_name: item.source_name, action: 'particular' })),
        });
        expect(writeCalls()[0][1].headers['X-CSRF-TOKEN']).toBe('test-csrf-token');
        expect(wrapper.get('[aria-live="polite"]').text()).toContain('Mapeamentos salvos');
    });
    it('Mapear exige convenio, plano desabilitado ate selecionar; envia plano escolhido', async () => {
        const wrapper = await render();
        await applyBulk(wrapper, 'map');
        expect(wrapper.get('[data-test="bulk-plan"]').element.disabled).toBe(true);
        expect(wrapper.get('[aria-live="polite"]').text()).toContain('Selecione um convênio');
        await wrapper.get('[data-test="bulk-covenant"]').setValue(covenant.id);
        await flushPromises();
        expect(wrapper.get('[data-test="bulk-plan"]').element.disabled).toBe(false);
        await wrapper.get('[data-test="bulk-plan"]').setValue(plan.id);
        await wrapper.get('[data-test="bulk-apply"]').trigger('click');
        await wrapper.get('[data-test="mapping-save"]').trigger('click');
        await flushPromises();
        expect(JSON.parse(writeCalls()[0][1].body).mappings[0]).toEqual({
            source_name: 'CONVÊNIO A',
            action: 'map',
            covenant_id: covenant.id,
            covenant_plan_id: plan.id,
        });
        expect(fetch.mock.calls.some(([url]) => url.includes('covenant_id=catalog-a'))).toBe(true);
    });
    it('Criar aparece so com permissao, sem permissao exibe hint', async () => {
        const wrapper = await render({ permissions: { can_create_covenant: false, can_reprocess: true } });
        expect(wrapper.find('[data-test="bulk-action"] option[value="create"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Configurações');
        await wrapper.setProps({ permissions: report.data.permissions });
        expect(wrapper.find('[data-test="bulk-action"] option[value="create"]').exists()).toBe(true);
    });
    it('422 por linha abre editor e foca primeiro campo invalido, anuncia erro e nao reprocessa', async () => {
        const wrapper = await render();
        await applyBulk(wrapper);
        fetch.mockImplementation(async (url, options) =>
            options?.method === 'PUT'
                ? response(
                      { message: 'Dados inválidos.', errors: { 'mappings.0.covenant_id': ['Convênio indisponível.'] } },
                      422,
                  )
                : response({ data: [] }),
        );
        await wrapper.get('[data-test="mapping-save"]').trigger('click');
        await flushPromises();
        const errorInput = wrapper.get('[aria-invalid="true"]');
        expect(document.activeElement).toBe(errorInput.element);
        expect(wrapper.get('[aria-live="polite"]').text()).toContain('Convênio indisponível');
        expect(
            errorInput
                .attributes('aria-describedby')
                .split(' ')
                .some((id) => wrapper.get(`#${id}`).text().includes('Convênio indisponível')),
        ).toBe(true);
        expect(fetch.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false);
    });
    it.each([
        { filesExpired: true },
        { totals: { ...report.data.totals, failed_rows: 0 } },
        { permissions: { ...report.data.permissions, can_reprocess: false } },
    ])('reprocessar bloqueado sem precondicoes: %j', async (extra) => {
        const wrapper = await render(extra);
        expect(wrapper.get('[data-test="mapping-reprocess"]').element.disabled).toBe(true);
    });
    it('confirma reprocessamento, trava envio duplicado e emite filho do contrato 202', async () => {
        const wrapper = await render();
        await wrapper.get('[data-test="mapping-reprocess"]').trigger('click');
        expect(fetch.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false);
        let finish;
        fetch.mockImplementation((url, options) =>
            options?.method === 'POST'
                ? new Promise((resolve) => {
                      finish = resolve;
                  })
                : Promise.resolve(response({ data: [] })),
        );
        await wrapper.get('[data-test="mapping-confirm"]').trigger('click');
        await wrapper.get('[data-test="mapping-confirm"]').trigger('click');
        expect(wrapper.attributes('aria-busy')).toBe('true');
        expect(fetch.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(1);
        finish(
            response(
                {
                    data: {
                        import_id: 'child-a',
                        status: 'pending',
                        total_rows: 9,
                        channel: 'imports.patients.child-a',
                    },
                },
                202,
            ),
        );
        await flushPromises();
        expect(wrapper.emitted('reprocessed')[0][0].import_id).toBe('child-a');
        expect(wrapper.get('[aria-live="polite"]').text()).toContain('Reprocessamento iniciado');
    });
    it('a11y: cabecalhos scope col, labels de controles e checkboxes nomeados', async () => {
        const wrapper = await render();
        expect(wrapper.findAll('thead th').every((th) => th.attributes('scope') === 'col')).toBe(true);
        for (const checkbox of wrapper.findAll('[data-test="row-select"]'))
            expect(checkbox.attributes('aria-label')).toContain('CONVÊNIO');
        for (const input of wrapper.findAll('input[id], select[id]'))
            expect(wrapper.find(`label[for="${input.attributes('id')}"]`).exists()).toBe(true);
        expect(wrapper.find('caption').exists()).toBe(true);
        expect(wrapper.find('[aria-live="polite"]').exists()).toBe(true);
    });
    it('acao individual fixa alvo atual, Particular esconde plano e limpar exige confirmacao', async () => {
        const wrapper = await render();
        await wrapper.get('[data-test="row-edit"]').trigger('click');
        await wrapper.get('[data-test="row-action"]').setValue('particular');
        expect(wrapper.find('[data-test="row-plan"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('carteirinha');
        await wrapper.get('[data-test="row-apply"]').trigger('click');
        await wrapper.get('[data-test="mapping-save"]').trigger('click');
        await flushPromises();
        expect(JSON.parse(writeCalls()[0][1].body).mappings).toHaveLength(1);
    });
    it('nao oferece plano antigo ao trocar o convenio da linha', async () => {
        const second = { ...covenant, id: 'catalog-b', label: 'CATÁLOGO B' };
        fetch.mockImplementation(async (url) =>
            response({ data: url.includes('covenant-plans') ? [] : [covenant, second] }),
        );
        const wrapper = await render({
            items: [
                {
                    ...report.data.items[0],
                    status: 'mapped',
                    covenant,
                    plan: { ...plan, active: true },
                    mapping: { covenant_id: covenant.id, covenant_plan_id: plan.id },
                },
            ],
        });
        await wrapper.get('[data-filter="all"]').trigger('click');
        await wrapper.get('[data-test="row-edit"]').trigger('click');
        await flushPromises();
        const select = wrapper
            .findAllComponents(SearchStub)
            .find((control) => control.props('id').endsWith('-covenant') && !control.props('id').includes('-bulk-'));
        expect(wrapper.get('[data-test="row-plan"]').find('option[value="plan-a"]').exists()).toBe(true);
        await select.setValue(second.id);
        await flushPromises();
        expect(wrapper.get('[data-test="row-plan"]').find('option[value="plan-a"]').exists()).toBe(false);
    });
    it('limpar de-para nao envia antes da confirmacao mesmo pelo botao salvar', async () => {
        const wrapper = await render();
        await wrapper.get('[data-test="row-edit"]').trigger('click');
        await wrapper.get('[data-test="row-action"]').setValue('clear');
        await wrapper.get('[data-test="mapping-save"]').trigger('click');
        await flushPromises();
        expect(writeCalls()).toHaveLength(0);
        expect(wrapper.get('[role="dialog"]').text()).toContain(t.clear_confirm);
        await wrapper.get('[data-test="mapping-confirm"]').trigger('click');
        await wrapper.get('[data-test="mapping-save"]').trigger('click');
        await flushPromises();
        expect(JSON.parse(writeCalls()[0][1].body)).toEqual({
            mappings: [{ source_name: 'CONVÊNIO A', action: 'clear' }],
        });
    });
    it.each([409, 422])('falha %i no reprocessamento mantem secao e anuncia mensagem', async (status) => {
        const wrapper = await render();
        fetch.mockResolvedValue(response({ message: 'Reprocessamento indisponível.' }, status));
        await wrapper.get('[data-test="mapping-reprocess"]').trigger('click');
        await wrapper.get('[data-test="mapping-confirm"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(wrapper.get('[aria-live="polite"]').text()).toContain('Reprocessamento indisponível');
        expect(wrapper.emitted('reprocessed')).toBeUndefined();
    });
    it('save que falha impede POST do reprocessamento e preserva alteracoes', async () => {
        const wrapper = await render();
        await applyBulk(wrapper);
        fetch.mockResolvedValue(
            response({ message: 'Dados inválidos.', errors: { 'mappings.0.action': ['Ação inválida.'] } }, 422),
        );
        await wrapper.get('[data-test="mapping-reprocess"]').trigger('click');
        await wrapper.get('[data-test="mapping-confirm"]').trigger('click');
        await flushPromises();
        expect(fetch.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false);
        expect(wrapper.vm.hasChanges).toBe(true);
        expect(document.activeElement).toBe(wrapper.get('[data-test="row-action"]').element);
    });
    it('respostas antigas de planos nao substituem o ultimo convenio escolhido', async () => {
        const wrapper = await render();
        let finish;
        fetch.mockImplementation((url) =>
            url.includes('covenant_id=catalog-a')
                ? new Promise((resolve) => {
                      finish = resolve;
                  })
                : Promise.resolve(response({ data: [{ ...plan, id: 'plan-b', label: 'PLANO B' }] })),
        );
        const control = wrapper
            .findAllComponents(SearchStub)
            .find((select) => select.attributes('data-test') === 'bulk-covenant');
        control.vm.$emit('update:modelValue', 'catalog-a');
        await flushPromises();
        const signal = fetch.mock.calls.at(-1)[1].signal;
        control.vm.$emit('update:modelValue', 'catalog-b');
        await flushPromises();
        finish(response({ data: [plan] }));
        await flushPromises();
        expect(signal.aborted).toBe(true);
        expect(wrapper.get('[data-test="bulk-plan"]').find('option[value="plan-b"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="bulk-plan"]').find('option[value="plan-a"]').exists()).toBe(false);
    });
    it('linhas sem convenio sao Particular e nao oferecem de-para de nome vazio', async () => {
        const wrapper = await render({
            items: [
                {
                    source_name: '',
                    source_name_normalized: '',
                    rows: 2,
                    failed_rows: 0,
                    status: 'particular',
                    covenant,
                    plan: null,
                    mapping: null,
                    retryable: false,
                },
            ],
        });
        await wrapper.get('[data-filter="all"]').trigger('click');
        expect(wrapper.text()).toContain(t.empty_source);
        expect(wrapper.get('[data-test="row-select"]').element.disabled).toBe(true);
        expect(wrapper.find('[data-test="row-edit"]').exists()).toBe(false);
    });
    it('criar em lote nao envia IDs e Particular em todos exige confirmacao', async () => {
        const wrapper = await render();
        await applyBulk(wrapper, 'create');
        await wrapper.get('[data-test="mapping-save"]').trigger('click');
        await flushPromises();
        expect(JSON.parse(writeCalls()[0][1].body).mappings).toEqual(
            report.data.items.slice(0, 2).map((item) => ({ source_name: item.source_name, action: 'create' })),
        );
        const button = wrapper.findAll('button').find((button) => button.text() === t.all_particular);
        await button.trigger('click');
        expect(wrapper.get('[role="dialog"]').text()).toContain(t.particular_confirm);
        await wrapper.get('[data-test="mapping-confirm"]').trigger('click');
        await wrapper.get('[data-test="mapping-save"]').trigger('click');
        await flushPromises();
        expect(JSON.parse(writeCalls()[1][1].body).mappings).toEqual([
            { source_name: 'CONVÊNIO A', action: 'particular' },
        ]);
    });

    it('SearchSelect real associa labels e erros ao input e recebe foco apos 422', async () => {
        const wrapper = await render({}, true);
        await applyBulk(wrapper, 'particular');
        fetch.mockResolvedValue(
            response(
                { message: 'Dados inválidos.', errors: { 'mappings.0.covenant_id': ['Destino indisponível.'] } },
                422,
            ),
        );
        await wrapper.get('[data-test="mapping-save"]').trigger('click');
        await flushPromises();
        const input = wrapper.get('input[aria-invalid="true"]');
        expect(document.activeElement).toBe(input.element);
        const labelledBy = input.attributes('aria-labelledby').split(' ');
        expect(
            labelledBy.some(
                (id) => wrapper.find(`#${id}`).exists() && wrapper.get(`#${id}`).text() === t.target_covenant,
            ),
        ).toBe(true);
        expect(wrapper.get(`label[for="${input.attributes('id')}"]`).text()).toBe(t.target_covenant);
        for (const id of input.attributes('aria-describedby').split(' '))
            expect(wrapper.find(`#${id}`).exists()).toBe(true);
        expect(wrapper.findAllComponents(SearchSelect).length).toBeGreaterThan(0);
    });
    it('relatorio indisponivel desabilita alteracoes e informa reenvio', async () => {
        const wrapper = await render({ reportAvailable: false });
        expect(wrapper.text()).toContain(t.report_unavailable);
        expect(wrapper.get('fieldset').element.disabled).toBe(true);
        expect(wrapper.get('[data-test="mapping-reprocess"]').element.disabled).toBe(true);
    });
    it('preserva rotulos de convenio e plano remotos fora do seed ao editar linha apos aplicar lote', async () => {
        const wrapper = await render();
        await wrapper.get('[data-test="select-pending"]').setValue(true);
        const remoteCovenant = {
            ...covenant,
            id: 'remote-covenant',
            label: 'CONVÊNIO REMOTO',
            name: 'CONVÊNIO REMOTO',
        };
        const remotePlan = { ...plan, id: 'remote-plan', label: 'PLANO REMOTO' };
        const target = wrapper
            .findAllComponents(SearchStub)
            .find((select) => select.attributes('data-test') === 'bulk-covenant');
        target.vm.$emit('update:modelValue', remoteCovenant.id);
        target.vm.$emit('option-selected', remoteCovenant);
        await flushPromises();
        const planControl = wrapper
            .findAllComponents(SearchStub)
            .find((select) => select.attributes('data-test') === 'bulk-plan');
        planControl.vm.$emit('update:modelValue', remotePlan.id);
        planControl.vm.$emit('option-selected', remotePlan);
        await flushPromises();
        await wrapper.get('[data-test="bulk-apply"]').trigger('click');
        await wrapper.get('[data-test="row-edit"]').trigger('click');
        await flushPromises();
        const rowTarget = wrapper
            .findAllComponents(SearchStub)
            .find((select) => select.props('id').endsWith('-covenant') && !select.props('id').includes('-bulk-'));
        expect(rowTarget.find('option[value="remote-covenant"]').text()).toBe('CONVÊNIO REMOTO');
        expect(wrapper.get('[data-test="row-plan"]').find('option[value="remote-plan"]').text()).toBe('PLANO REMOTO');
    });
    it('avisa teto de nomes sem bloquear reprocessamento e remove aviso quando a flag deixa de valer', async () => {
        const wrapper = await render({ namesCapped: true });
        expect(wrapper.get('[data-test="names-capped"]').text()).toBe(t.names_capped);
        expect(wrapper.get('[data-test="mapping-reprocess"]').element.disabled).toBe(false);
        await wrapper.get('[data-test="mapping-reprocess"]').trigger('click');
        await wrapper.get('[data-test="mapping-confirm"]').trigger('click');
        await flushPromises();
        expect(wrapper.emitted('reprocessed')[0][0].import_id).toBe('child-a');
        await wrapper.setProps({ namesCapped: false });
        expect(wrapper.find('[data-test="names-capped"]').exists()).toBe(false);
    });
});
