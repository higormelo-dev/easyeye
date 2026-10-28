import { computed, ref, watch } from 'vue';
import { hasAction, pageRows } from './billingHelpers.js';

/**
 * Seleção da aba Guias para "Registrar recebimento" em lote (id → linha).
 *
 * - Só entram guias com 'pay' em allowed_actions (a regra é do servidor).
 * - Atravessa páginas, busca e ordem (o total soma as de outras páginas).
 * - Quando a página volta do servidor, as marcadas dela são atualizadas e
 *   saem se deixaram de ser pagáveis (ex.: outro usuário registrou antes).
 * - Período/convênio/status/lote novos descartam as marcadas que não estão
 *   mais na lista — o mesmo critério da seleção da aba "A faturar".
 *
 * @param {() => object|Array} page      paginator da aba Guias (ou array)
 * @param {() => string}       scopeKey  filtros que mudam quem aparece na lista
 */
export function useClaimSelection(page, scopeKey) {
    const selected = ref({});

    const ids = computed(() => Object.keys(selected.value));
    const rows = computed(() => Object.values(selected.value));
    const count = computed(() => ids.value.length);
    /** Soma do "a receber" (valor − glosa) das marcadas — o padrão do recebimento. */
    const total = computed(() => rows.value.reduce((sum, row) => sum + Number(row.receivable_amount ?? 0), 0));

    function isSelectable(row) {
        return hasAction(row, 'pay');
    }

    function toggle(row) {
        if (!row || !isSelectable(row)) return;

        const next = { ...selected.value };

        if (next[row.id]) delete next[row.id];
        else next[row.id] = row;

        selected.value = next;
    }

    /** Cabeçalho: marca/desmarca as pagáveis da página atual (as de outras páginas ficam). */
    function togglePage() {
        const payable = pageRows(page()).filter(isSelectable);
        const all = payable.length > 0 && payable.every((row) => selected.value[row.id]);
        const next = { ...selected.value };

        payable.forEach((row) => {
            if (all) delete next[row.id];
            else next[row.id] = row;
        });

        selected.value = next;
    }

    function clear() {
        selected.value = {};
    }

    let lastScope = scopeKey();

    watch(page, (current) => {
        const visible = new Map(pageRows(current).map((row) => [row.id, row]));
        const scope = scopeKey();
        let next = { ...selected.value };

        if (scope !== lastScope) {
            lastScope = scope;
            next = Object.fromEntries(Object.entries(next).filter(([id]) => visible.has(id)));
        }

        visible.forEach((row, id) => {
            if (!next[id]) return;

            if (isSelectable(row)) next[id] = row;
            else delete next[id];
        });

        selected.value = next;
    });

    return { ids, rows, count, total, isSelectable, toggle, togglePage, clear };
}
