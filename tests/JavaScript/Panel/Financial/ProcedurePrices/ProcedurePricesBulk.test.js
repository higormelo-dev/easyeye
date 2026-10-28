import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import ProcedurePricesIndex from '@/Pages/Panel/Financial/ProcedurePrices/Index.vue';
import { adjustPrice, planAdjustment, planCopy } from '@/Pages/Panel/Financial/ProcedurePrices/pricesBulk.js';

/**
 * Tabela de Preços — fase 4: menu "Ajustar preços" no cabeçalho da grade com
 * "Reajustar %" (aumento/redução, arredondamento a 2 casas, linhas visíveis ou
 * todas) e "Copiar de outro convênio" (preços da origem por recarga parcial,
 * substituir ou não), sempre com prévia e aplicados SÓ na grade; o Salvar
 * envia apenas as linhas alteradas (preço limpo = null = remover).
 */
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    const pageProps = reactive({ locale: 'pt_BR', flash: {}, errors: {}, aiAssistant: { enabled: false } });

    return {
        usePage: () => ({ props: pageProps }),
        router: { get: vi.fn(), post: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: { props: ['title', 'subtitle'], template: '<div><slot name="actions" /></div>' },
}));
vi.mock('@/Components/Panel/SearchSelect.vue', () => ({
    default: {
        props: ['modelValue', 'options'],
        emits: ['update:modelValue'],
        template:
            '<select class="covenant-select" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="o in options" :key="o.id" :value="o.id">{{ o.name }}</option></select>',
    },
}));
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open', 'size'],
        emits: ['close'],
        template:
            '<div v-if="open" class="modal-stub"><div class="m-header"><slot name="header" /></div><slot /><div class="m-footer"><slot name="footer" /></div></div>',
    },
}));

const t = {
    title: 'Tabela de Preços',
    priced_counter: ':priced de :total com preço',
    covenant: 'Convênio',
    code: 'Código',
    procedure: 'Procedimento',
    price: 'Preço',
    price_aria: 'Preço de :procedure',
    inherited_price: 'Padrão do sistema: :price',
    row_changed: 'alterado',
    search_placeholder: 'Buscar por código ou nome',
    filter_label: 'Filtrar procedimentos',
    filter_all: 'Todos',
    filter_priced: 'Com preço',
    filter_unpriced: 'Sem preço',
    charging: 'Cobrar do convênio (guia TISS)',
    charging_aria: 'Cobrar :procedure',
    savebar_label: 'Salvar tabela de preços',
    save: 'Salvar preços',
    saving: 'Salvando...',
    unsaved: ':count alteração(ões) não salva(s)',
    no_changes: 'Nenhuma alteração pendente',
    save_error: 'Não foi possível salvar os preços.',
    rows_with_errors: ':count linha(s) com erro.',
    leave_confirm: 'Sair?',
    charging_legacy: ':count marcado(s) para guia.',
    too_many_changes:
        'São :count alterações e o limite por salvamento é :max. Aplique o reajuste ou a cópia por partes (use a busca ou o filtro) e salve entre uma e outra.',
    bulk_menu: 'Ajustar preços',
    bulk_menu_label: 'Ajustar preços em lote (reajuste ou cópia)',
    bulk_adjust: 'Reajustar %',
    bulk_copy: 'Copiar de outro convênio',
    bulk_local_hint: 'As mudanças vão só para a grade: nada é salvo até você clicar em "Salvar preços".',
    bulk_applied: ':count preço(s) alterado(s) na grade. Nada foi salvo ainda: revise e clique em "Salvar preços".',
    bulk_cancel: 'Cancelar',
    preview_label: 'Prévia',
    preview_changes: ':count preço(s) vão mudar.',
    preview_none: 'Nenhum preço muda com essas opções.',
    preview_examples: 'Exemplos:',
    preview_example: ':procedure: de :from para :to',
    preview_example_new: ':procedure: sem preço próprio, passa a :to',
    adjust_title: 'Reajustar preços',
    adjust_direction: 'Tipo de reajuste',
    adjust_increase: 'Aumento',
    adjust_decrease: 'Redução',
    adjust_percent: 'Percentual',
    adjust_percent_help: 'Até 2 casas decimais.',
    adjust_percent_range: 'Informe um percentual entre :min e :max.',
    adjust_preview_empty: 'Informe o percentual para ver a prévia.',
    adjust_scope: 'Aplicar em',
    adjust_scope_visible: 'Só nas :count linha(s) visíveis (busca e filtro atuais)',
    adjust_scope_all: 'Em todas as :count linha(s) deste convênio',
    adjust_skipped: ':count linha(s) sem preço próprio ficam como estão (o padrão do sistema não é reajustado).',
    adjust_apply: 'Aplicar na grade',
    copy_title: 'Copiar preços de outro convênio',
    copy_source: 'Convênio de origem',
    copy_source_placeholder: 'Selecione o convênio de origem',
    copy_no_sources: 'Não há outro convênio para copiar.',
    copy_scope_hint: 'Vale para os :count procedimentos da tabela.',
    copy_overwrite: 'Substituir também os preços já preenchidos neste convênio',
    copy_overwrite_help: 'Desmarcado: só os sem preço.',
    copy_loading: 'Carregando os preços do convênio de origem...',
    copy_load_error: 'Não foi possível carregar os preços desse convênio. Tente novamente.',
    copy_preview_empty: 'Escolha o convênio de origem para ver a prévia.',
    copy_kept: ':count procedimento(s) já têm preço e ficam como estão.',
    copy_missing: ':count procedimento(s) sem preço no convênio de origem ficam como estão.',
    copy_apply: 'Copiar para a grade',
};

const covenants = [
    { id: 'c1', name: 'Particular', tiss: false },
    { id: 'c2', name: 'Unimed', tiss: true },
    { id: 'c3', name: 'Bradesco', tiss: true },
];
const procedures = [
    { id: 'p1', code: '10101012', name: 'Consulta em consultório' },
    { id: 'p2', code: '41301250', name: 'Mapeamento de retina' },
    { id: 'p3', code: '41301323', name: 'Tonometria' },
    { id: 'p4', code: '41401271', name: 'Paquimetria ultrassônica' },
];

let wrapper;

function mountPage(overrides = {}) {
    wrapper = mount(ProcedurePricesIndex, {
        attachTo: document.body,
        global: { stubs: { teleport: true } },
        props: {
            covenants,
            procedures,
            selectedCovenantId: 'c2',
            prices: { p1: { price: 150, charging: true }, p2: { price: 90, charging: false } },
            inheritedPrices: { p3: 42.5 },
            limits: { max_items: 2000 },
            t,
            ...overrides,
        },
    });

    return wrapper;
}

const norm = (text) => text.replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
const rowsOf = (w) => w.findAll('[data-test="price-row"]');
const priceValue = (w, i) => rowsOf(w)[i].find('[data-test="price-input"]').element.value;
const lastPost = () => vi.mocked(router.post).mock.calls.at(-1);

async function typePrice(row, value) {
    const input = row.find('[data-test="price-input"]');
    await input.trigger('focus');
    await input.setValue(value);
    await input.trigger('blur');
}

async function openMenuItem(w, item) {
    await w.find('[data-test="bulk-menu"] button').trigger('click');
    await nextTick();
    await w.find(`[data-test="${item}"]`).trigger('click');
    await nextTick();
    await nextTick();
}

async function chooseSource(w, id) {
    const select = w.find('[data-test="copy-source"]');
    await select.setValue(id);
    await nextTick();
}

/** Simula a resposta da recarga parcial (only: ['sourcePrices']). */
async function respondSource(w, sourcePrices) {
    const options = vi.mocked(router.reload).mock.calls.at(-1)[0];
    options.onSuccess?.({ props: { sourcePrices } });
    await w.setProps({ sourcePrices });
    options.onFinish?.();
    await nextTick();
}

beforeEach(() => {
    vi.mocked(router.get).mockReset();
    vi.mocked(router.post).mockReset();
    vi.mocked(router.reload).mockReset();
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('pricesBulk — cálculo', () => {
    it('reajuste arredonda a 2 casas (meio para cima) sem erro de ponto flutuante', () => {
        expect(adjustPrice(0.15, 50)).toBe(0.23); // em float: 0,15 × 1,5 = 0,22499… → 0,22
        expect(adjustPrice(10, 3.33)).toBe(10.33);
        expect(adjustPrice(19.99, 10)).toBe(21.99);
        expect(adjustPrice(100, -7.5)).toBe(92.5);
        expect(adjustPrice(123.45, -99.99)).toBe(0.01);
        expect(adjustPrice(1234567.89, 12.34)).toBe(1386913.57);
        expect(adjustPrice(0, 10)).toBe(0);
    });

    it('planAdjustment só reajusta preço próprio e ignora o que não muda', () => {
        const entries = [
            { index: 0, row: { price: 100 } },
            { index: 1, row: { price: null } },
            { index: 2, row: { price: 0 } },
        ];

        expect(planAdjustment(entries, 10)).toEqual({
            changes: [{ index: 0, row: entries[0].row, from: 100, to: 110 }],
            skipped: 1,
        });
    });

    it('planCopy: sem substituir preenche só o vazio; com substituir troca o que difere', () => {
        const entries = [
            { index: 0, row: { procedure_id: 'a', price: 150 } },
            { index: 1, row: { procedure_id: 'b', price: null } },
            { index: 2, row: { procedure_id: 'c', price: 30 } },
            { index: 3, row: { procedure_id: 'd', price: null } },
        ];
        const source = { a: 200, b: 80, c: 30 };

        const keep = planCopy(entries, source, { overwrite: false });
        expect(keep.changes.map((c) => [c.index, c.from, c.to])).toEqual([[1, null, 80]]);
        expect(keep.kept).toBe(1);
        expect(keep.missing).toBe(1);

        const replace = planCopy(entries, source, { overwrite: true });
        expect(replace.changes.map((c) => [c.index, c.from, c.to])).toEqual([
            [0, 150, 200],
            [1, null, 80],
        ]);
        expect(replace.kept).toBe(0);
    });
});

describe('Reajustar %', () => {
    it('menu no cabeçalho da grade abre o reajuste; prévia com exemplos; aplicar mexe só na grade', async () => {
        const w = mountPage();

        expect(w.find('[data-test="bulk-menu"] button').attributes('aria-haspopup')).toBe('menu');
        expect(w.find('[data-test="bulk-menu"] button').attributes('aria-label')).toBe(t.bulk_menu_label);

        await openMenuItem(w, 'bulk-adjust');

        const percent = w.find('[data-test="adjust-percent"]');
        expect(document.activeElement).toBe(percent.element);
        expect(w.find('label[for="pp-adjust-percent"]').text()).toBe('Percentual');

        const preview = w.find('[data-test="adjust-preview"]');
        expect(preview.attributes('aria-live')).toBe('polite');
        expect(preview.text()).toContain('Informe o percentual para ver a prévia.');

        await percent.setValue('10');

        expect(w.find('[data-test="preview-count"]').text()).toBe('2 preço(s) vão mudar.');
        expect(w.findAll('[data-test="preview-example"]').map((li) => norm(li.text()))).toEqual([
            '10101012 Consulta em consultório: de R$ 150,00 para R$ 165,00',
            '41301250 Mapeamento de retina: de R$ 90,00 para R$ 99,00',
        ]);
        expect(w.find('[data-test="preview-skipped"]').text()).toContain('2 linha(s) sem preço próprio');

        await w.find('[data-test="adjust-apply"]').trigger('click');

        expect(w.find('.modal-stub').exists()).toBe(false);
        expect(priceValue(w, 0)).toBe('165,00');
        expect(priceValue(w, 1)).toBe('99,00');
        expect(priceValue(w, 2)).toBe(''); // padrão do sistema não é reajustado
        expect(w.find('[data-test="dirty-count"]').text()).toBe('2 alteração(ões) não salva(s)');
        expect(w.find('[data-test="bulk-notice"]').text()).toContain('Nada foi salvo ainda');
        expect(router.post).not.toHaveBeenCalled();

        await w.find('[data-test="save"]').trigger('click');
        expect(lastPost()[1]).toEqual({
            covenant_id: 'c2',
            items: [
                { procedure_id: 'p1', price: 165, charging: true },
                { procedure_id: 'p2', price: 99, charging: false },
            ],
        });
    });

    it('redução arredonda aos centavos (inclusive meio centavo)', async () => {
        const w = mountPage({ prices: { p1: { price: 150, charging: true }, p2: { price: 0.15, charging: true } } });

        await openMenuItem(w, 'bulk-adjust');
        await w.find('[data-test="adjust-decrease"]').setValue(true);
        await w.find('[data-test="adjust-percent"]').setValue('7,5');

        expect(w.findAll('[data-test="preview-example"]').map((li) => norm(li.text()))).toEqual([
            '10101012 Consulta em consultório: de R$ 150,00 para R$ 138,75',
            '41301250 Mapeamento de retina: de R$ 0,15 para R$ 0,14',
        ]);

        await w.find('[data-test="adjust-increase"]').setValue(true);
        await w.find('[data-test="adjust-percent"]').setValue('50');
        expect(norm(w.findAll('[data-test="preview-example"]')[1].text())).toBe(
            '41301250 Mapeamento de retina: de R$ 0,15 para R$ 0,23',
        );
    });

    it('percentual fora do limite: erro ligado ao campo e "Aplicar" desabilitado', async () => {
        const w = mountPage();

        await openMenuItem(w, 'bulk-adjust');
        const percent = w.find('[data-test="adjust-percent"]');

        await percent.setValue('0');
        expect(percent.attributes('aria-invalid')).toBe('true');
        expect(percent.attributes('aria-describedby')).toBe('pp-adjust-percent-error');
        expect(w.find('#pp-adjust-percent-error').text()).toBe('Informe um percentual entre 0,01% e 1.000%.');
        expect(w.find('[data-test="adjust-apply"]').element.disabled).toBe(true);

        await w.find('[data-test="adjust-decrease"]').setValue(true);
        await percent.setValue('150');
        expect(w.find('#pp-adjust-percent-error').text()).toBe('Informe um percentual entre 0,01% e 99,99%.');
        expect(w.find('[data-test="adjust-apply"]').element.disabled).toBe(true);
    });

    it('escopo: com busca ativa o padrão são as linhas visíveis; "todas" alcança a grade inteira', async () => {
        const w = mountPage();

        await w.find('[data-test="prices-toolbar"] input[type="text"]').setValue('retina');
        await openMenuItem(w, 'bulk-adjust');

        expect(w.find('[data-test="adjust-scope-visible"]').element.checked).toBe(true);
        expect(w.find('label[for="pp-adjust-scope-visible"]').text()).toBe(
            'Só nas 1 linha(s) visíveis (busca e filtro atuais)',
        );
        expect(w.find('label[for="pp-adjust-scope-all"]').text()).toBe('Em todas as 4 linha(s) deste convênio');

        await w.find('[data-test="adjust-percent"]').setValue('10');
        expect(w.find('[data-test="preview-count"]').text()).toBe('1 preço(s) vão mudar.');

        await w.find('[data-test="adjust-scope-all"]').setValue(true);
        expect(w.find('[data-test="preview-count"]').text()).toBe('2 preço(s) vão mudar.');

        await w.find('[data-test="adjust-scope-visible"]').setValue(true);
        await w.find('[data-test="adjust-apply"]').trigger('click');

        await w.find('[data-test="prices-toolbar"] input[type="text"]').setValue('');
        expect(priceValue(w, 0)).toBe('150,00'); // fora da busca: intocada
        expect(priceValue(w, 1)).toBe('99,00');
    });

    it('Esc fecha sem aplicar nada', async () => {
        const w = mountPage();

        await openMenuItem(w, 'bulk-adjust');
        await w.find('[data-test="adjust-percent"]').setValue('10');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();

        expect(w.find('[data-test="adjust-preview"]').exists()).toBe(false);
        expect(priceValue(w, 0)).toBe('150,00');
        expect(w.find('[data-test="dirty-count"]').text()).toBe('Nenhuma alteração pendente');
    });
});

describe('Copiar de outro convênio', () => {
    it('busca os preços da origem por recarga parcial e, sem substituir, só preenche o que está vazio', async () => {
        const w = mountPage();

        await openMenuItem(w, 'bulk-copy');

        // Origem: os outros convênios da tela (o da grade fica de fora).
        const options = w.findAll('[data-test="copy-source"] option').map((o) => o.attributes('value'));
        expect(options).toEqual(['', 'c1', 'c3']);
        expect(document.activeElement).toBe(w.find('[data-test="copy-source"]').element);

        await chooseSource(w, 'c1');

        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['sourcePrices'],
                data: { source_covenant_id: 'c1' },
                preserveUrl: true,
            }),
        );
        expect(w.find('[data-test="copy-loading"]').text()).toBe('Carregando os preços do convênio de origem...');

        await respondSource(w, { covenant_id: 'c1', prices: { p1: 200, p2: 80, p3: 50 } });

        // Sem substituir: só p3 (sem preço próprio) recebe; p1/p2 ficam; p4 não tem preço na origem.
        const preview = w.find('[data-test="copy-preview"]');
        expect(preview.attributes('aria-live')).toBe('polite');
        expect(w.find('[data-test="preview-count"]').text()).toBe('1 preço(s) vão mudar.');
        expect(w.findAll('[data-test="preview-example"]').map((li) => norm(li.text()))).toEqual([
            '41301323 Tonometria: sem preço próprio, passa a R$ 50,00',
        ]);
        expect(w.find('[data-test="preview-kept"]').text()).toBe('2 procedimento(s) já têm preço e ficam como estão.');
        expect(w.find('[data-test="preview-missing"]').text()).toBe(
            '1 procedimento(s) sem preço no convênio de origem ficam como estão.',
        );

        await w.find('[data-test="copy-apply"]').trigger('click');

        expect(priceValue(w, 0)).toBe('150,00');
        expect(priceValue(w, 1)).toBe('90,00');
        expect(priceValue(w, 2)).toBe('50,00');
        expect(router.post).not.toHaveBeenCalled();
        expect(w.find('[data-test="dirty-count"]').text()).toBe('1 alteração(ões) não salva(s)');
    });

    it('com "substituir", sobrescreve o que já tem preço; o Salvar leva só as linhas copiadas', async () => {
        const w = mountPage();

        await openMenuItem(w, 'bulk-copy');
        await chooseSource(w, 'c1');
        await respondSource(w, { covenant_id: 'c1', prices: { p1: 200, p2: 80, p3: 50 } });

        await w.find('[data-test="copy-overwrite"]').setValue(true);

        expect(w.find('[data-test="preview-count"]').text()).toBe('3 preço(s) vão mudar.');
        expect(w.findAll('[data-test="preview-example"]').map((li) => norm(li.text()))).toEqual([
            '10101012 Consulta em consultório: de R$ 150,00 para R$ 200,00',
            '41301250 Mapeamento de retina: de R$ 90,00 para R$ 80,00',
            '41301323 Tonometria: sem preço próprio, passa a R$ 50,00',
        ]);
        expect(w.find('[data-test="preview-kept"]').exists()).toBe(false);

        await w.find('[data-test="copy-apply"]').trigger('click');
        await w.find('[data-test="save"]').trigger('click');

        // p4 não foi tocada: não vai no lote (e o servidor não a remove).
        expect(lastPost()[1]).toEqual({
            covenant_id: 'c2',
            items: [
                { procedure_id: 'p1', price: 200, charging: true },
                { procedure_id: 'p2', price: 80, charging: false },
                { procedure_id: 'p3', price: 50, charging: true },
            ],
        });
    });

    it('falha ao carregar a origem: avisa e não deixa aplicar', async () => {
        const w = mountPage();

        await openMenuItem(w, 'bulk-copy');
        await chooseSource(w, 'c3');

        vi.mocked(router.reload).mock.calls.at(-1)[0].onFinish(); // sem onSuccess: erro/cancelado
        await nextTick();

        expect(w.find('[data-test="copy-failed"]').text()).toBe(
            'Não foi possível carregar os preços desse convênio. Tente novamente.',
        );
        expect(w.find('[data-test="copy-apply"]').element.disabled).toBe(true);
    });

    it('origem já carregada não é buscada de novo ao reabrir', async () => {
        const w = mountPage();

        await openMenuItem(w, 'bulk-copy');
        await chooseSource(w, 'c1');
        await respondSource(w, { covenant_id: 'c1', prices: { p2: 70 } });
        await w.find('[data-test="copy-cancel"]').trigger('click');

        await openMenuItem(w, 'bulk-copy');
        await chooseSource(w, 'c1');

        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(w.find('[data-test="preview-kept"]').text()).toBe('1 procedimento(s) já têm preço e ficam como estão.');
    });
});

describe('Salvar só as linhas alteradas', () => {
    it('limpar um preço envia null só daquela linha; as intocadas não vão no lote', async () => {
        const w = mountPage();

        await typePrice(rowsOf(w)[0], '');
        await w.find('[data-test="save"]').trigger('click');

        expect(lastPost()[1]).toEqual({
            covenant_id: 'c2',
            items: [{ procedure_id: 'p1', price: null, charging: true }],
        });
    });

    it('sem alterações: Salvar desabilitado e nada é enviado', async () => {
        const w = mountPage();

        expect(w.find('[data-test="save"]').element.disabled).toBe(true);
        await w.find('[data-test="save"]').trigger('click');
        expect(router.post).not.toHaveBeenCalled();
    });

    it('marcação antiga de cobrança por guia (convênio sem TISS) vai no lote mesmo sem edição, para o servidor corrigir', async () => {
        const w = mountPage({
            selectedCovenantId: 'c1',
            prices: { p1: { price: 150, charging: true }, p2: { price: 90, charging: false } },
        });

        expect(w.find('[data-test="dirty-count"]').text()).toBe('Nenhuma alteração pendente');
        expect(w.find('[data-test="save"]').element.disabled).toBe(false);

        await w.find('[data-test="save"]').trigger('click');
        expect(lastPost()[1]).toEqual({
            covenant_id: 'c1',
            items: [{ procedure_id: 'p1', price: 150, charging: false }],
        });
    });

    it('acima do teto de linhas por salvamento: avisa e não envia', async () => {
        const w = mountPage({ limits: { max_items: 1 } });

        await typePrice(rowsOf(w)[2], '10');
        await typePrice(rowsOf(w)[3], '20');
        await w.find('[data-test="save"]').trigger('click');

        expect(router.post).not.toHaveBeenCalled();
        expect(w.find('[data-test="save-error"]').text()).toContain('São 2 alterações e o limite por salvamento é 1.');
    });
});
