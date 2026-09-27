import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import ProcedurePricesIndex from '@/Pages/Panel/Financial/ProcedurePrices/Index.vue';

/**
 * Tabela de Preços — fase 3: busca local, filtros com aria-pressed, contador
 * "X de Y com preço", preço padrão herdado, barra fixa de alterações,
 * confirmação ao sair (router 'before' + beforeunload, removidos no unmount),
 * aviso de marcação antiga em convênio sem TISS e estados vazios.
 */
const inertia = vi.hoisted(() => ({ pageProps: null, handlers: {}, off: [] }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.pageProps = reactive({ locale: 'pt_BR', flash: {}, errors: {}, aiAssistant: { enabled: false } });

    return {
        usePage: () => ({ props: inertia.pageProps }),
        router: {
            get:  vi.fn(),
            post: vi.fn(),
            on:   vi.fn((type, callback) => {
                inertia.handlers[type] = callback;
                const off = vi.fn(() => { delete inertia.handlers[type]; });
                inertia.off.push(off);

                return off;
            }),
        },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: { props: ['title', 'subtitle'], template: '<div class="page-header"><slot name="actions" /></div>' },
}));
vi.mock('@/Components/Panel/SearchSelect.vue', () => ({
    default: {
        props: ['modelValue', 'options'],
        emits: ['update:modelValue'],
        template: `<select class="covenant-select" :value="modelValue" @change="$emit('update:modelValue', $event.target.value)">
            <option v-for="o in options" :key="o.id" :value="o.id">{{ o.name }}</option>
        </select>`,
    },
}));
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: { props: ['open'], template: '<div v-if="open" class="modal-stub"><slot /><slot name="footer" /></div>' },
}));

const t = {
    title: 'Tabela de Preços', priced_counter: ':priced de :total com preço', covenant: 'Convênio',
    covenant_tiss: 'Com operadora TISS', covenant_cash: 'Recebido no caixa', code: 'Código', procedure: 'Procedimento',
    price: 'Preço', price_aria: 'Preço de :procedure', inherited_price: 'Padrão do sistema: :price', row_changed: 'alterado',
    search_placeholder: 'Buscar por código ou nome', search_clear: 'Limpar busca', filter_label: 'Filtrar procedimentos',
    filter_all: 'Todos', filter_priced: 'Com preço', filter_unpriced: 'Sem preço',
    no_results: 'Nenhum procedimento encontrado.', clear_filters: 'Limpar busca e filtro',
    charging: 'Cobrar do convênio (guia TISS)', charging_aria: 'Cobrar :procedure', charging_help: 'Ajuda TISS.',
    charging_help_cash: 'Recebido no caixa, sem guia.', charging_disabled_hint: 'Informe um preço.', charging_cash_hint: 'Recebido no caixa.',
    charging_legacy: ':count procedimento(s) ainda marcados para guia. Salve para corrigir.',
    savebar_label: 'Salvar tabela de preços', save: 'Salvar preços', saving: 'Salvando...',
    unsaved: ':count alteração(ões) não salva(s)', no_changes: 'Nenhuma alteração pendente',
    leave_confirm: 'Você tem :count alteração(ões) não salva(s). Sair?',
    no_covenants: 'Cadastre um convênio antes de definir preços.', no_covenants_action: 'Cadastrar convênio',
    no_covenants_ask: 'Peça a um administrador.', no_procedures: 'Nenhum procedimento ativo.', no_procedures_hint: 'Catálogo do sistema.',
    discard_body: ':count em :covenant', discard_confirm: 'Descartar e trocar', discard_cancel: 'Continuar editando',
};

const covenants  = [{ id: 'c1', name: 'Particular', tiss: false }, { id: 'c2', name: 'Unimed', tiss: true }];
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
        props: {
            covenants,
            procedures,
            selectedCovenantId: 'c2',
            prices: { p1: { price: 150, charging: true } },
            inheritedPrices: { p3: 42.5 },
            links: { covenants: '/panel/setting/covenants' },
            t,
            ...overrides,
        },
    });

    return wrapper;
}

const rowsOf = (w) => w.findAll('[data-test="price-row"]');
const codesOf = (w) => rowsOf(w).map((row) => row.find('code').text());
const chip = (w, key) => w.find(`[data-chip="${key}"]`);
const priceInput = (row) => row.find('[data-test="price-input"]');

async function typePrice(row, value) {
    const input = priceInput(row);
    await input.trigger('focus');
    await input.setValue(value);
    await input.trigger('blur');
}

function fireBefore(visit = { method: 'get', url: new URL('http://localhost/panel/patients') }) {
    return inertia.handlers.before?.({ detail: { visit } });
}

beforeEach(() => {
    vi.mocked(router.get).mockReset();
    vi.mocked(router.post).mockReset();
    vi.mocked(router.on).mockClear();
    inertia.off.length = 0;
    inertia.pageProps.aiAssistant = { enabled: false };
    window.confirm = vi.fn(() => false);
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('busca, filtros e contador', () => {
    it('contador "X de Y com preço" conta o preço próprio e o padrão herdado', () => {
        const w = mountPage();

        // p1 (próprio) + p3 (herdado do padrão do sistema).
        expect(w.find('[data-test="priced-counter"]').text()).toBe('2 de 4 com preço');
    });

    it('filtros Todos / Com preço / Sem preço são botões com aria-pressed e contagem', async () => {
        const w = mountPage();

        expect(w.find('[data-test="price-filter"]').attributes('role')).toBe('group');
        expect(w.find('[data-test="price-filter"]').attributes('aria-label')).toBe('Filtrar procedimentos');
        expect(chip(w, 'all').attributes('aria-pressed')).toBe('true');
        expect(chip(w, 'all').text()).toBe('Todos 4');
        expect(chip(w, 'priced').text()).toBe('Com preço 2');
        expect(chip(w, 'unpriced').text()).toBe('Sem preço 2');

        await chip(w, 'unpriced').trigger('click');

        expect(chip(w, 'unpriced').attributes('aria-pressed')).toBe('true');
        expect(chip(w, 'all').attributes('aria-pressed')).toBe('false');
        expect(codesOf(w)).toEqual(['41301250', '41401271']);

        await chip(w, 'priced').trigger('click');
        expect(codesOf(w)).toEqual(['10101012', '41301323']);
    });

    it('linha editada não some do filtro enquanto não é salva', async () => {
        const w = mountPage();

        await chip(w, 'unpriced').trigger('click');
        await typePrice(rowsOf(w)[0], '80'); // p2 passa a ter preço

        expect(codesOf(w)).toEqual(['41301250', '41401271']);
        expect(rowsOf(w)[0].classes()).toContain('pp-row-dirty');
        expect(chip(w, 'unpriced').text()).toBe('Sem preço 1');
    });

    it('busca local por código ou nome, sem diferenciar acento e maiúsculas', async () => {
        const w = mountPage();
        const search = w.find('[data-test="prices-toolbar"] input[type="text"]');

        expect(search.attributes('aria-label')).toBe('Buscar por código ou nome');

        await search.setValue('ULTRASSONICA');
        expect(codesOf(w)).toEqual(['41401271']);

        await search.setValue('4130');
        expect(codesOf(w)).toEqual(['41301250', '41301323']);

        expect(router.get).not.toHaveBeenCalled(); // busca não vai ao servidor
    });

    it('sem resultado mostra o aviso e "Limpar busca e filtro" volta a lista inteira', async () => {
        const w = mountPage();

        await w.find('[data-test="prices-toolbar"] input[type="text"]').setValue('nada disso');
        await chip(w, 'priced').trigger('click');

        expect(rowsOf(w)).toHaveLength(0);
        expect(w.find('[data-test="no-results"]').text()).toContain('Nenhum procedimento encontrado.');

        await w.find('[data-test="clear-filters"]').trigger('click');

        expect(rowsOf(w)).toHaveLength(4);
        expect(chip(w, 'all').attributes('aria-pressed')).toBe('true');
    });

    it('linha com erro de validação continua visível mesmo fora da busca', async () => {
        const w = mountPage();

        // Fase 4: só a linha alterada (p4) vai no lote — o erro items.0 é dela.
        await typePrice(rowsOf(w)[3], '70');
        await w.find('[data-test="prices-toolbar"] input[type="text"]').setValue('consulta');
        await w.find('[data-test="save"]').trigger('click');
        expect(vi.mocked(router.post).mock.calls[0][1].items).toEqual([{ procedure_id: 'p4', price: 70, charging: true }]);
        vi.mocked(router.post).mock.calls[0][2].onError({ 'items.0.price': 'Preço inválido.' });
        await nextTick();

        expect(codesOf(w)).toEqual(['10101012', '41401271']);
        expect(rowsOf(w)[1].find('[data-test="row-error"]').text()).toBe('Preço inválido.');
    });
});

describe('preço padrão herdado', () => {
    it('aparece como placeholder formatado e como texto ligado ao campo', () => {
        const w = mountPage();
        const row = rowsOf(w)[2]; // p3 (Tonometria), sem preço próprio
        const inherited = row.find('[data-test="inherited-price"]');

        expect(priceInput(row).attributes('placeholder')).toBe('42,50');
        expect(priceInput(row).element.value).toBe('');
        expect(inherited.text().replace(/\u00a0/g, ' ')).toBe('Padrão do sistema: R$ 42,50');
        expect(priceInput(row).attributes('aria-describedby')).toBe(inherited.attributes('id'));

        // Sem herança: placeholder padrão do MoneyInput.
        expect(priceInput(rowsOf(w)[1]).attributes('placeholder')).toBe('0,00');
    });

    it('digitar um preço próprio esconde o texto do padrão', async () => {
        const w = mountPage();

        await typePrice(rowsOf(w)[2], '50');

        expect(rowsOf(w)[2].find('[data-test="inherited-price"]').exists()).toBe(false);
        expect(priceInput(rowsOf(w)[2]).attributes('aria-describedby')).toBeUndefined();
    });
});

describe('barra de salvar e confirmação ao sair', () => {
    it('barra fixa é uma região nomeada com o total de alterações (aria-live)', async () => {
        const w = mountPage();
        const bar = w.find('[data-test="savebar"]');

        expect(bar.attributes('role')).toBe('region');
        expect(bar.attributes('aria-label')).toBe('Salvar tabela de preços');
        expect(bar.find('[data-test="dirty-count"]').attributes('aria-live')).toBe('polite');
        expect(bar.classes()).not.toContain('pp-savebar--dirty');

        await typePrice(rowsOf(w)[1], '80');
        await typePrice(rowsOf(w)[3], '90');

        expect(bar.find('[data-test="dirty-count"]').text()).toBe('2 alteração(ões) não salva(s)');
        expect(bar.classes()).toContain('pp-savebar--dirty');
    });

    it('deixa espaço para o botão do Assistente de IA quando ele está ativo', () => {
        inertia.pageProps.aiAssistant = { enabled: true };

        expect(mountPage().find('[data-test="savebar"]').classes()).toContain('pp-savebar--fab');
    });

    it('sair pelo Inertia com alterações pede confirmação; cancelar mantém a página', async () => {
        const w = mountPage();

        expect(router.on).toHaveBeenCalledWith('before', expect.any(Function));
        expect(fireBefore()).toBeUndefined(); // sem alterações: segue sem perguntar
        expect(window.confirm).not.toHaveBeenCalled();

        await typePrice(rowsOf(w)[1], '80');

        expect(fireBefore()).toBe(false);
        expect(window.confirm).toHaveBeenCalledWith('Você tem 1 alteração(ões) não salva(s). Sair?');

        window.confirm = vi.fn(() => true);
        expect(fireBefore()).toBeUndefined();
    });

    it('prefetch e as visitas da própria tela (salvar, trocar convênio) não perguntam', async () => {
        const w = mountPage();
        await typePrice(rowsOf(w)[1], '80');

        expect(fireBefore({ method: 'get', prefetch: true })).toBeUndefined();

        let duringSave;
        vi.mocked(router.post).mockImplementation(() => { duringSave = fireBefore({ method: 'post' }); });
        await w.find('[data-test="save"]').trigger('click');
        expect(duringSave).toBeUndefined();

        let duringSwitch;
        vi.mocked(router.get).mockImplementation(() => { duringSwitch = fireBefore({ method: 'get', only: ['prices'] }); });
        await w.find('.covenant-select').setValue('c1');
        await w.find('[data-test="discard-confirm"]').trigger('click');
        expect(duringSwitch).toBeUndefined();

        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('recarregar/fechar a aba com alterações aciona o aviso do navegador (beforeunload)', async () => {
        const w = mountPage();
        const clean = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(clean);
        expect(clean.defaultPrevented).toBe(false);

        await typePrice(rowsOf(w)[1], '80');

        const dirty = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(dirty);
        expect(dirty.defaultPrevented).toBe(true);
    });

    it('ao desmontar remove o listener do Inertia e o beforeunload', async () => {
        const removeSpy = vi.spyOn(window, 'removeEventListener');
        const w = mountPage();
        await typePrice(rowsOf(w)[1], '80');

        w.unmount();
        wrapper = null;

        expect(inertia.off.at(-1)).toHaveBeenCalled();
        expect(inertia.handlers.before).toBeUndefined();
        expect(removeSpy).toHaveBeenCalledWith('beforeunload', expect.any(Function));

        const after = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(after);
        expect(after.defaultPrevented).toBe(false);
        removeSpy.mockRestore();
    });
});

describe('convênio sem operadora TISS e estados vazios', () => {
    it('avisa quando o banco ainda marca cobrança por guia num convênio sem TISS (salvar corrige)', () => {
        const w = mountPage({
            selectedCovenantId: 'c1',
            prices: { p1: { price: 150, charging: true }, p2: { price: 90, charging: true }, p3: { price: 10, charging: false } },
        });

        expect(w.find('[data-test="charging-legacy"]').text()).toBe('2 procedimento(s) ainda marcados para guia. Salve para corrigir.');
        // A grade já mostra desligado (é o que será salvo) — sem contar como alteração.
        expect(rowsOf(w)[0].find('input[type="checkbox"]').element.checked).toBe(false);
        expect(w.find('[data-test="dirty-count"]').text()).toBe('Nenhuma alteração pendente');
    });

    it('convênio com TISS não mostra o aviso de marcação antiga', () => {
        expect(mountPage().find('[data-test="charging-legacy"]').exists()).toBe(false);
    });

    it('sem convênio: estado vazio com link para cadastrar (quem tem permissão)', () => {
        const w = mountPage({ covenants: [], selectedCovenantId: '', prices: {} });

        expect(w.find('[data-test="empty-covenants"]').text()).toContain('Cadastre um convênio antes de definir preços.');
        expect(w.find('[data-test="link-covenants"]').attributes('href')).toBe('/panel/setting/covenants');
        expect(w.find('[data-test="savebar"]').exists()).toBe(false);
        expect(w.find('[data-test="priced-counter"]').exists()).toBe(false);
    });

    it('sem permissão para cadastrar convênio: orienta a pedir a um administrador', () => {
        const w = mountPage({ covenants: [], selectedCovenantId: '', prices: {}, links: { covenants: null } });

        expect(w.find('[data-test="link-covenants"]').exists()).toBe(false);
        expect(w.find('[data-test="empty-covenants"]').text()).toContain('Peça a um administrador.');
    });

    it('sem procedimentos: estado vazio explicado, sem filtros nem barra de salvar', () => {
        const w = mountPage({ procedures: [] });

        expect(w.find('[data-test="empty-procedures"]').text()).toContain('Nenhum procedimento ativo.');
        expect(w.find('[data-test="empty-procedures"]').text()).toContain('Catálogo do sistema.');
        expect(w.find('[data-test="prices-toolbar"]').exists()).toBe(false);
        expect(w.find('[data-test="savebar"]').exists()).toBe(false);
    });
});
