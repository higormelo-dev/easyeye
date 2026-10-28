<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import { useViewMode } from '@/composables/useViewMode.js';
import { useTrans } from '@/composables/useTrans.js';
import { useCountFormat } from './useCountFormat.js';
import CountTable from './CountTable.vue';
import CountCards from './CountCards.vue';

/**
 * Contagem física de estoque em MASSA — mesmo layout de Panel/Patients/Index:
 * cabeçalho com total e alternância tabela/cards (persistida no navegador),
 * busca + categoria na mesma barra (um preserva o outro e a ordenação) e
 * tabela/cards paginados com os mesmos dados. Textos vêm de
 * lang/{locale}/stock_counts.php (prop `t`).
 *
 * App\Services\Stock\StockService::adjustToCountedQuantity() aplica cada item;
 * v1 é POR PRODUTO (agregado), não por lote — ver docblock de StockCountRequest.
 *
 * O que foi digitado fica em `counted` (estado desta página, preservado pelo
 * Inertia ao buscar/filtrar/ordenar/paginar) e TUDO que foi digitado vai no
 * envio, mesmo o que não está na página atual — o botão mostra esse total.
 * Item em branco NUNCA vai no envio (não é "contei zero", é "ainda não contei").
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    products: { type: Object, required: true }, // paginator Laravel
    categories: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) }, // { search, category_id, sort, direction }
    routes: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
});

const SEARCH_DEBOUNCE_MS = 400;
const QUANTITY_PRECISION = 1000; // 3 casas (decimal:3)

const { tx } = useTrans(() => props.t);
const { quantity, signedQuantity } = useCountFormat(() => props.t);

const pageTitle = computed(() => props.t.page_title ?? 'Contagem de estoque');

// Link "Movimentações" abre em nova aba: o título (dica) também avisa.
const movementsLinkTitle = computed(
    () => `${props.t.btn_movements ?? 'Movimentações'} (${props.t.opens_new_tab ?? 'abre em nova aba'})`,
);

// ── Alternância tabela/cards (preferência no navegador) ──────────────────────
const { view, setView } = useViewMode('stock_counts_view');

// ── Busca (debounce), categoria e ordenação — cada um preserva os demais ─────
const search = ref(props.filters?.search ?? '');
const categoryId = ref(props.filters?.category_id ?? '');

function currentParams(overrides = {}) {
    return {
        search: search.value,
        category_id: categoryId.value,
        sort: props.filters?.sort,
        direction: props.filters?.direction,
        ...overrides,
    };
}

function visit(params, options = {}) {
    router.get(props.routes.index, params, { preserveState: true, preserveScroll: true, ...options });
}

let searchTimer = null;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit(currentParams(), { replace: true }), SEARCH_DEBOUNCE_MS);
});

onBeforeUnmount(() => clearTimeout(searchTimer));

watch(categoryId, () => visit(currentParams(), { replace: true }));

function onSort({ sort, direction }) {
    visit(currentParams({ sort, direction }));
}

// ── Contagem digitada ────────────────────────────────────────────────────────
const counted = ref({}); // { [productId]: texto digitado }

function isTyped(value) {
    return value !== '' && value !== null && value !== undefined;
}

const typedIds = computed(() => Object.keys(counted.value).filter((id) => isTyped(counted.value[id])));
const touchedCount = computed(() => typedIds.value.length);

function onCount(productId, value) {
    counted.value = { ...counted.value, [productId]: value };
}

const rows = computed(() => props.products?.data ?? []);

// Diferença contado − sistema dos produtos da página (null = ainda não contado).
const deltas = computed(() =>
    Object.fromEntries(
        rows.value.map((p) => {
            const value = counted.value[p.id];
            if (!isTyped(value)) return [p.id, null];

            return [
                p.id,
                Math.round((Number(value) - Number(p.qty_on_hand)) * QUANTITY_PRECISION) / QUANTITY_PRECISION,
            ];
        }),
    ),
);

// ── Envio ────────────────────────────────────────────────────────────────────
const submitting = ref(false);
const result = ref(null); // { message, variances } | null
const errorMsg = ref('');

const resultMessage = computed(() =>
    result.value ? tx('result_applied', { count: result.value.variances?.length ?? 0 }) : '',
);

async function submit() {
    const sent = typedIds.value.map((id) => [id, counted.value[id]]);
    if (sent.length === 0 || submitting.value) return;

    submitting.value = true;
    errorMsg.value = '';
    result.value = null;
    try {
        const items = sent.map(([id, value]) => ({ entity_product_id: id, counted_qty: Number(value) }));
        const { data } = await window.axios.post(props.routes.store, { items });
        result.value = data;

        // Limpa só o que foi enviado e não mudou durante o envio; o saldo
        // novo vem do servidor (sem mexer nas props).
        const sentValues = Object.fromEntries(sent);
        counted.value = Object.fromEntries(
            Object.entries(counted.value).filter(([id, value]) => sentValues[id] !== value),
        );
        router.reload({ only: ['products'] });
    } catch (e) {
        errorMsg.value = e.response?.data?.message ?? props.t.apply_error ?? 'Não foi possível aplicar a contagem.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="page-stock-counts">
            <PageHeader
                :title="pageTitle"
                :total="products.total ?? 0"
                :total-label="t.total_label ?? 'Total:'"
                show-view-toggle
                :view="view"
                :view-table-title="t.view_table ?? 'Tabela'"
                :view-cards-title="t.view_cards ?? 'Cards'"
                @set-view="setView"
            >
                <template #actions>
                    <div class="d-flex align-items-center gap-2">
                        <!-- Nova aba: `counted` é estado desta página — navegar aqui perderia a contagem digitada. -->
                        <a
                            :href="routes.movements_index"
                            target="_blank"
                            rel="noopener noreferrer"
                            :title="movementsLinkTitle"
                            class="btn btn-outline-secondary fs-13 btn-md movements-link"
                        >
                            <i class="ti ti-transfer-in me-1" aria-hidden="true"></i>
                            {{ t.btn_movements ?? 'Movimentações' }}
                            <i class="ti ti-external-link ms-1" aria-hidden="true"></i>
                            <span class="visually-hidden">({{ t.opens_new_tab ?? 'abre em nova aba' }})</span>
                        </a>
                        <button
                            type="button"
                            class="btn btn-primary fs-13 btn-md apply-count"
                            :disabled="submitting || touchedCount === 0"
                            @click="submit"
                        >
                            <span
                                v-if="submitting"
                                class="spinner-border spinner-border-sm me-1"
                                aria-hidden="true"
                            ></span>
                            <i v-else class="ti ti-clipboard-check me-1" aria-hidden="true"></i>
                            {{ tx('btn_apply', { count: touchedCount }) }}
                        </button>
                    </div>
                </template>
            </PageHeader>

            <p class="text-muted small">{{ t.help }}</p>

            <!-- Busca + categoria na mesma barra -->
            <div class="stock-toolbar d-flex align-items-center flex-wrap gap-2 mb-3">
                <SearchInput
                    v-model="search"
                    :placeholder="t.search_placeholder ?? 'Buscar...'"
                    :clear-label="t.search_clear"
                    max-width="280px"
                    wrapper-class=""
                />
                <select
                    v-model="categoryId"
                    class="form-select form-select-sm stock-toolbar__select"
                    :aria-label="t.filter_category ?? 'Filtrar por categoria'"
                >
                    <option value="">{{ t.filter_category_all ?? 'Todas as categorias' }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <span class="text-muted small touched-summary" aria-live="polite">
                    {{ tx('touched_summary', { touched: touchedCount, total: products.total ?? 0 }) }}
                </span>
            </div>

            <!-- Fechar = estado local (sem data-bs-dismiss, que removeria o nó do Vue). -->
            <div v-if="result" class="alert alert-success alert-dismissible py-2" role="status">
                {{ resultMessage }}
                <ul v-if="result.variances?.length" class="mb-0 mt-2 small">
                    <li v-for="v in result.variances" :key="v.entity_product_id">
                        {{ v.product_name }}: {{ quantity(v.before) }} → {{ quantity(v.counted) }} (<span
                            :class="v.delta > 0 ? 'text-success' : 'text-danger'"
                            >{{ signedQuantity(v.delta) }}</span
                        >)
                    </li>
                </ul>
                <button
                    type="button"
                    class="btn-close"
                    :aria-label="t.close ?? 'Fechar'"
                    @click="result = null"
                ></button>
            </div>
            <div v-if="errorMsg" class="alert alert-danger py-2" role="alert">{{ errorMsg }}</div>

            <CountTable
                v-if="view === 'table'"
                :products="products"
                :counted="counted"
                :deltas="deltas"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @count="onCount"
            />
            <CountCards v-else :products="products" :counted="counted" :deltas="deltas" :t="t" @count="onCount" />

            <!-- Lista longa: aplicar também no fim, sem voltar ao topo -->
            <div v-if="rows.length > 0" class="d-flex justify-content-end mt-3">
                <button
                    type="button"
                    class="btn btn-primary fs-13 btn-md apply-count"
                    :disabled="submitting || touchedCount === 0"
                    @click="submit"
                >
                    <span v-if="submitting" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    {{ tx('btn_apply', { count: touchedCount }) }}
                </button>
            </div>
            <span v-if="submitting" class="visually-hidden" role="status">{{ t.applying }}</span>
        </div>
    </AppLayout>
</template>

<style scoped>
.stock-toolbar__select {
    max-width: 240px;
}
</style>
