import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import ProcedurePricesIndex from '@/Pages/Panel/Financial/ProcedurePrices/Index.vue';

/**
 * Tabela de Preços: efeito do "Cobrar do convênio (guia TISS)" explicado e
 * restrito a convênio com operadora TISS, campos com nome acessível, erros
 * ligados à linha e troca de convênio que não descarta edições sem confirmação.
 */
const inertia = vi.hoisted(() => ({ pageProps: null }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.pageProps = reactive({ locale: 'pt_BR', flash: {}, errors: {} });

    return {
        usePage: () => ({ props: inertia.pageProps }),
        router: { get: vi.fn(), post: vi.fn(), on: vi.fn(() => vi.fn()) },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: { props: ['title', 'subtitle', 'total', 'totalLabel'], template: '<div><slot name="actions" /></div>' },
}));
vi.mock('@/Components/Panel/SearchSelect.vue', () => ({
    default: {
        props: ['modelValue', 'options', 'clearable', 'placeholder'],
        emits: ['update:modelValue'],
        template: `<select class="covenant-select" :value="modelValue" @change="$emit('update:modelValue', $event.target.value)">
            <option v-for="o in options" :key="o.id" :value="o.id">{{ o.name }}</option>
        </select>`,
    },
}));
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open', 'size'],
        emits: ['close'],
        template: '<div v-if="open" class="modal-stub"><slot name="header" /><slot /><slot name="footer" /></div>',
    },
}));

const t = {
    title: 'Tabela de Preços',
    subtitle: 'Defina',
    breadcrumb_financial: 'Financeiro',
    priced_counter: ':priced de :total com preço',
    covenant: 'Convênio',
    covenant_placeholder: 'Selecione',
    covenant_tiss: 'Com operadora TISS',
    covenant_cash: 'Recebido no caixa',
    code: 'Código',
    procedure: 'Procedimento',
    price: 'Preço',
    price_aria: 'Preço de :procedure',
    empty_hint: 'Deixe em branco',
    inherited_price: 'Padrão do sistema: :price',
    row_changed: 'alterado',
    search_placeholder: 'Buscar',
    search_clear: 'Limpar busca',
    filter_label: 'Filtrar',
    filter_all: 'Todos',
    filter_priced: 'Com preço',
    filter_unpriced: 'Sem preço',
    no_results: 'Nada encontrado.',
    clear_filters: 'Limpar busca e filtro',
    charging: 'Cobrar do convênio (guia TISS)',
    charging_aria: 'Cobrar :procedure do convênio por guia TISS',
    charging_help: 'Marcado: faturado ao convênio por guia; o caixa da chegada não pré-preenche.',
    charging_help_cash: 'Sem operadora TISS: recebido no caixa.',
    charging_disabled_hint: 'Informe um preço para definir a cobrança.',
    charging_cash_hint: 'Recebido no caixa.',
    charging_legacy: ':count marcado(s) para guia.',
    savebar_label: 'Salvar tabela',
    save: 'Salvar preços',
    saving: 'Salvando...',
    saved: 'Preços atualizados.',
    save_error: 'Não foi possível salvar os preços.',
    rows_with_errors: ':count linha(s) com erro.',
    unsaved: ':count alteração(ões) não salva(s)',
    no_changes: 'Nenhuma alteração pendente',
    loading: 'Carregando',
    leave_confirm: 'Sair com :count alteração(ões)?',
    no_covenants: 'Cadastre um convênio.',
    no_covenants_action: 'Cadastrar convênio',
    no_covenants_ask: 'Peça a um administrador.',
    no_procedures: 'Nenhum procedimento.',
    no_procedures_hint: 'Catálogo do sistema.',
    discard_title: 'Descartar alterações?',
    discard_body: 'Você tem :count alteração(ões) não salva(s) em :covenant.',
    discard_confirm: 'Descartar e trocar',
    discard_cancel: 'Continuar editando',
};

// Particular: sem operadora TISS (recebido no caixa). Unimed: com operadora TISS.
const covenants = [
    { id: 'c1', name: 'Particular', tiss: false },
    { id: 'c2', name: 'Unimed', tiss: true },
];
const procedures = [
    { id: 'p1', code: '10101012', name: 'Consulta' },
    { id: 'p2', code: '41301250', name: 'Mapeamento de retina' },
];

let wrapper;

function mountPage(overrides = {}) {
    wrapper = mount(ProcedurePricesIndex, {
        attachTo: document.body,
        props: {
            breadcrumbs: [],
            covenants,
            procedures,
            selectedCovenantId: 'c2',
            prices: { p1: { price: 150, charging: false } },
            t,
            ...overrides,
        },
    });
    return wrapper;
}

const rowsOf = (w) => w.findAll('[data-test="price-row"]');
const priceInput = (row) => row.find('[data-test="price-input"]');

/** Digita no MoneyInput como o usuário (foco → texto → saída do campo). */
async function typePrice(row, value) {
    const input = priceInput(row);
    await input.trigger('focus');
    await input.setValue(value);
    await input.trigger('blur');
}

beforeEach(() => {
    vi.mocked(router.get).mockClear();
    vi.mocked(router.post).mockClear();
});
afterEach(() => {
    wrapper?.unmount();
});

describe('Financial/ProcedurePrices/Index', () => {
    it('explica o efeito de "Cobrar do convênio (guia TISS)" e liga o texto ao checkbox', () => {
        const w = mountPage();

        expect(w.find('thead').text()).toContain('Cobrar do convênio (guia TISS)');
        expect(w.find('thead').text()).not.toContain('Faturável');
        expect(w.find('[data-test="charging-help"]').text()).toContain('o caixa da chegada não pré-preenche');
        expect(w.find('[data-test="covenant-kind"]').text()).toBe('Com operadora TISS');

        const checkbox = rowsOf(w)[0].find('input[type="checkbox"]');
        expect(checkbox.attributes('aria-label')).toBe('Cobrar 10101012 Consulta do convênio por guia TISS');
        expect(checkbox.attributes('aria-describedby')).toBe('pp-charging-help');
    });

    it('convênio com operadora TISS: linha nova nasce marcada; checkbox só fica ativo com preço', () => {
        const w = mountPage();
        const [priced, empty] = rowsOf(w);

        expect(priced.find('input[type="checkbox"]').element.checked).toBe(false);
        expect(priced.find('input[type="checkbox"]').element.disabled).toBe(false);

        expect(empty.find('input[type="checkbox"]').element.checked).toBe(true);
        expect(empty.find('input[type="checkbox"]').element.disabled).toBe(true);
        expect(empty.find('input[type="checkbox"]').attributes('title')).toBe(
            'Informe um preço para definir a cobrança.',
        );
    });

    it('convênio sem operadora TISS (Particular): cobrança desligada e desabilitada com a ajuda "recebido no caixa"', async () => {
        const w = mountPage({ selectedCovenantId: 'c1', prices: { p1: { price: 150, charging: false } } });

        expect(w.find('[data-test="covenant-kind"]').text()).toBe('Recebido no caixa');
        expect(w.find('[data-test="charging-help"]').text()).toContain('Sem operadora TISS: recebido no caixa.');

        for (const row of rowsOf(w)) {
            const checkbox = row.find('input[type="checkbox"]');
            expect(checkbox.element.checked).toBe(false);
            expect(checkbox.element.disabled).toBe(true);
            expect(checkbox.attributes('title')).toBe('Recebido no caixa.');
        }

        // Mesmo com preço digitado, continua desligado.
        await typePrice(rowsOf(w)[1], '80');
        expect(rowsOf(w)[1].find('input[type="checkbox"]').element.disabled).toBe(true);
        expect(rowsOf(w)[1].find('input[type="checkbox"]').element.checked).toBe(false);
    });

    it('preço com MoneyInput alinhado à direita, nome acessível por linha e símbolo da moeda do locale', () => {
        const w = mountPage();
        const row = rowsOf(w)[1];

        expect(priceInput(row).attributes('aria-label')).toBe('Preço de 41301250 Mapeamento de retina');
        expect(priceInput(row).classes()).toContain('text-end');
        expect(row.find('.input-group-text').text()).toBe('R$');
        expect(priceInput(rowsOf(w)[0]).element.value).toBe('150,00');
        expect(w.find('thead').text()).not.toContain('(R$)');
    });

    it('mostra quantos procedimentos têm preço e as alterações não salvas', async () => {
        const w = mountPage();
        expect(w.find('[data-test="priced-counter"]').text()).toBe('1 de 2 com preço');
        expect(w.find('[data-test="dirty-count"]').text()).toBe('Nenhuma alteração pendente');

        await typePrice(rowsOf(w)[1], '80');

        expect(w.find('[data-test="priced-counter"]').text()).toBe('2 de 2 com preço');
        expect(w.find('[data-test="dirty-count"]').text()).toContain('1 alteração(ões) não salva(s)');
        expect(rowsOf(w)[1].classes()).toContain('pp-row-dirty');
        expect(rowsOf(w)[1].find('.visually-hidden').text()).toContain('alterado');
    });

    it('troca de convênio sem edições recarrega só preços, herdados e convênio (parcial) com o convênio na URL', async () => {
        const w = mountPage({ selectedCovenantId: 'c1' });

        await w.find('.covenant-select').setValue('c2');

        expect(router.get).toHaveBeenCalledWith(
            '/_routes/panel.financial.procedure-prices.index',
            { covenant_id: 'c2' },
            expect.objectContaining({ only: ['prices', 'inheritedPrices', 'selectedCovenantId'], preserveState: true }),
        );
    });

    it('troca de convênio com edições pendentes pede confirmação com resumo antes de descartar', async () => {
        const w = mountPage({ selectedCovenantId: 'c1' });

        await typePrice(rowsOf(w)[1], '80');
        await w.find('.covenant-select').setValue('c2');

        expect(router.get).not.toHaveBeenCalled();
        expect(w.find('[data-test="discard-body"]').text()).toBe(
            'Você tem 1 alteração(ões) não salva(s) em Particular.',
        );

        // Foco inicial na ação segura: um Enter reflexo não descarta as edições.
        await nextTick();
        expect(document.activeElement).toBe(w.find('[data-test="discard-cancel"]').element);

        await w.find('[data-test="discard-cancel"]').trigger('click');
        expect(w.find('.modal-stub').exists()).toBe(false);
        expect(router.get).not.toHaveBeenCalled();
        expect(priceInput(rowsOf(w)[1]).element.value).toBe('80,00');

        await w.find('.covenant-select').setValue('c2');
        await w.find('[data-test="discard-confirm"]').trigger('click');
        expect(router.get).toHaveBeenCalledWith(expect.any(String), { covenant_id: 'c2' }, expect.any(Object));
    });

    // Fase 4: o Salvar envia só as linhas alteradas; o erro items.N é a posição no lote.
    it('salva só as linhas alteradas e liga os erros do servidor à linha da grade', async () => {
        const w = mountPage();

        await typePrice(rowsOf(w)[1], '80');
        await w.find('[data-test="save"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/_routes/panel.financial.procedure-prices.store',
            {
                covenant_id: 'c2',
                items: [{ procedure_id: 'p2', price: 80, charging: true }],
            },
            expect.objectContaining({ preserveScroll: true }),
        );

        const options = vi.mocked(router.post).mock.calls[0][2];
        options.onError({ 'items.0.price': 'O campo preço deve ser pelo menos 0.' });
        options.onFinish();
        await nextTick();

        const row = rowsOf(w)[1];
        expect(row.classes()).toContain('pp-row-invalid');
        expect(priceInput(row).classes()).toContain('is-invalid');
        expect(priceInput(row).attributes('aria-invalid')).toBe('true');
        expect(row.find('[data-test="row-error"]').text()).toBe('O campo preço deve ser pelo menos 0.');
        expect(priceInput(row).attributes('aria-describedby')).toBe(
            row.find('[data-test="row-error"]').attributes('id'),
        );
        expect(w.find('[data-test="save-error"]').text()).toBe(
            'Não foi possível salvar os preços. 1 linha(s) com erro.',
        );
        expect(document.activeElement).toBe(priceInput(row).element);
    });

    it('convênio sem operadora TISS envia charging=false nas linhas enviadas', async () => {
        const w = mountPage({ selectedCovenantId: 'c1' });

        await typePrice(rowsOf(w)[1], '80');
        await w.find('[data-test="save"]').trigger('click');

        // p1 não mudou (e já está sem cobrança no banco): não vai no lote.
        expect(router.post).toHaveBeenCalledWith(
            expect.any(String),
            {
                covenant_id: 'c1',
                items: [{ procedure_id: 'p2', price: 80, charging: false }],
            },
            expect.any(Object),
        );
    });

    // Inertia 3 (preserveEqualProps) mantém a MESMA referência de 'prices' quando o
    // convênio novo tem os mesmos preços (ex.: os dois sem preço) — o watch de
    // 'prices' não dispara. Antes a grade de A ficava na tela e era salva em B.
    it('"Descartar e trocar" reconstrói a grade mesmo quando os preços do novo convênio são iguais (mesma referência)', async () => {
        const emptyPrices = {};
        const w = mountPage({ selectedCovenantId: 'c1', prices: emptyPrices });

        await typePrice(rowsOf(w)[1], '80');
        await w.find('.covenant-select').setValue('c2');
        await w.find('[data-test="discard-confirm"]').trigger('click');

        const options = vi.mocked(router.get).mock.calls[0][2];
        options.onSuccess({ props: { selectedCovenantId: 'c2', prices: emptyPrices } });
        await w.setProps({ prices: emptyPrices, selectedCovenantId: 'c2' });
        options.onFinish();
        await nextTick();

        expect(priceInput(rowsOf(w)[1]).element.value).toBe('');
        expect(w.find('[data-test="dirty-count"]').text()).toBe('Nenhuma alteração pendente');

        // Nada pendente na grade nova: Salvar desabilitado (a edição de A não vaza para B).
        expect(w.find('[data-test="save"]').element.disabled).toBe(true);

        await typePrice(rowsOf(w)[0], '45');
        await w.find('[data-test="save"]').trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            expect.any(String),
            {
                covenant_id: 'c2',
                items: [{ procedure_id: 'p1', price: 45, charging: true }],
            },
            expect.any(Object),
        );
    });

    // Antes o convênio era trocado ANTES do GET terminar: se a troca falhasse, a grade
    // de A era salva como B (e as linhas vazias apagavam os preços que B já tinha).
    it('se a troca de convênio não se concretiza, o Salvar continua no convênio da grade e o seletor volta', async () => {
        const w = mountPage();

        await typePrice(rowsOf(w)[1], '80');
        await w.find('.covenant-select').setValue('c1');
        await w.find('[data-test="discard-confirm"]').trigger('click');

        const options = vi.mocked(router.get).mock.calls[0][2];
        options.onFinish(); // falhou/cancelou: sem onSuccess
        await nextTick();

        expect(w.find('.covenant-select').element.value).toBe('c2');

        await w.find('[data-test="save"]').trigger('click');
        expect(router.post).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({
                covenant_id: 'c2',
                items: [{ procedure_id: 'p2', price: 80, charging: true }],
            }),
            expect.any(Object),
        );
    });

    // O seletor guarda o item escolhido internamente: ao cancelar, mostrava "Unimed"
    // enquanto a grade e o Salvar continuavam em "Particular".
    it('"Continuar editando" volta o seletor para o convênio da grade', async () => {
        const w = mountPage({ selectedCovenantId: 'c1' });

        await typePrice(rowsOf(w)[1], '80');
        await w.find('.covenant-select').setValue('c2');
        await w.find('[data-test="discard-cancel"]').trigger('click');
        await nextTick();

        expect(w.find('.covenant-select').element.value).toBe('c1');
        expect(priceInput(rowsOf(w)[1]).element.value).toBe('80,00');
    });

    it('recarregar os preços (novo convênio / após salvar) limpa erros e marca a grade como salva', async () => {
        const w = mountPage();

        await typePrice(rowsOf(w)[1], '80');
        await w.setProps({ prices: { p1: { price: 150, charging: false }, p2: { price: 80, charging: true } } });

        expect(w.find('[data-test="dirty-count"]').text()).toBe('Nenhuma alteração pendente');
        expect(w.find('[data-test="priced-counter"]').text()).toBe('2 de 2 com preço');
    });
});
