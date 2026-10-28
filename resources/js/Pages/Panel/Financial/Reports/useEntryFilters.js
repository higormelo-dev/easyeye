import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Busca, filtros e ordenação da lista de lançamentos do relatório de fluxo de
 * caixa (padrão Stock/Movements): aplicação automática — selects na hora,
 * busca com debounce — sem botão "Filtrar".
 *
 * Cada mudança é uma recarga PARCIAL (só `entries` e `filters`): os
 * agregados do período (KPIs, por dia, por categoria) não mudam com a lista
 * e não são recalculados. O parâmetro de página não vai junto, então toda
 * mudança volta à 1ª página. O estado aplicado é o de props.filters (já
 * normalizado no servidor: valor inválido volta vazio).
 */
export const SEARCH_DEBOUNCE_MS = 400;

export const LIST_PROPS = ['entries', 'filters'];

/** Tira vazios da query (URL limpa; o servidor usa os padrões). */
export function withoutEmpty(params) {
    return Object.fromEntries(
        Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined),
    );
}

/**
 * @param {object} props props da página (filters, routes)
 */
export function useEntryFilters(props) {
    const applied = () => props.filters ?? {};

    const search = ref(applied().search ?? '');
    const type = ref(applied().type ?? '');
    const status = ref(applied().status ?? '');
    const category = ref(applied().category_id ?? '');
    const sort = ref(applied().sort ?? '');
    const direction = ref(applied().direction ?? '');
    const loading = ref(false);
    const error = ref(false);

    /** Parâmetros da lista na barra (sem período e sem página). */
    function params() {
        return withoutEmpty({
            search: String(search.value ?? '').trim(),
            type: type.value,
            status: status.value,
            category_id: category.value,
            sort: sort.value,
            direction: direction.value,
        });
    }

    /** Mesma forma de params(), a partir do que o servidor aplicou. */
    function appliedKey() {
        const f = applied();

        return JSON.stringify(
            withoutEmpty({
                search: String(f.search ?? '').trim(),
                type: f.type ?? '',
                status: f.status ?? '',
                category_id: f.category_id ?? '',
                sort: f.sort ?? '',
                direction: f.direction ?? '',
            }),
        );
    }

    // Última combinação pedida: evita visita repetida quando vários watchers
    // disparam pela mesma mudança (ex.: "Limpar filtros").
    let lastRequested = appliedKey();
    let timer = null;

    function cancelPending() {
        clearTimeout(timer);
        timer = null;
    }

    function apply() {
        cancelPending();

        const query = params();
        const key = JSON.stringify(query);
        if (key === lastRequested) return;

        lastRequested = key;
        error.value = false;

        router.get(
            props.routes?.index ?? '',
            { from: applied().from, to: applied().to, ...query },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: LIST_PROPS,
                onStart: () => {
                    loading.value = true;
                },
                onFinish: () => {
                    loading.value = false;
                },
                // Falhou: avisa na lista e deixa repetir a mesma combinação.
                onHttpException: () => {
                    error.value = true;
                    lastRequested = null;
                    return false;
                },
                onNetworkError: () => {
                    error.value = true;
                    lastRequested = null;
                    return false;
                },
            },
        );
    }

    watch(search, () => {
        cancelPending();
        timer = setTimeout(apply, SEARCH_DEBOUNCE_MS);
    });

    watch([type, status, category], apply);

    // Resposta do servidor (filtro, ordem, página ou período): a barra mostra
    // o que foi aplicado. A busca digitada não é sobrescrita (o usuário pode
    // estar no meio da digitação).
    watch(
        () => [applied().type, applied().status, applied().category_id, applied().sort, applied().direction],
        ([newType, newStatus, newCategory, newSort, newDirection]) => {
            type.value = newType ?? '';
            status.value = newStatus ?? '';
            category.value = newCategory ?? '';
            sort.value = newSort ?? '';
            direction.value = newDirection ?? '';
            lastRequested = appliedKey();
        },
    );

    /** `sort` do SortableTh. */
    function sortBy({ sort: newSort, direction: newDirection }) {
        sort.value = newSort;
        direction.value = newDirection;
        apply();
    }

    function clear() {
        search.value = '';
        type.value = '';
        status.value = '';
        category.value = '';
        apply();
    }

    const hasFilters = computed(() => {
        const f = applied();

        return Boolean(String(f.search ?? '').trim() || f.type || f.status || f.category_id);
    });

    onBeforeUnmount(cancelPending);

    return { search, type, status, category, loading, error, hasFilters, params, sortBy, clear, cancelPending };
}
