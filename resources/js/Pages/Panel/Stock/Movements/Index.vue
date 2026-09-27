<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, usePage, Link } from '@inertiajs/vue3';
import AppLayout         from '@/Layouts/AppLayout.vue';
import PageHeader        from '@/Components/Panel/PageHeader.vue';
import SearchInput       from '@/Components/Panel/SearchInput.vue';
import { useViewMode }   from '@/composables/useViewMode.js';
import MovementTable     from './MovementTable.vue';
import MovementCards     from './MovementCards.vue';
import MovementFormModal from './MovementFormModal.vue';

/**
 * Extrato de movimentação de estoque — mesmo layout de Panel/Patients/Index:
 * cabeçalho com total, alternância tabela/cards (persistida no navegador),
 * busca + filtros de produto/tipo na mesma barra (um preserva o outro e a
 * ordenação) e tabela/cards com os mesmos dados. Textos vêm de
 * lang/{locale}/stock_movements.php (prop `t`).
 *
 * Só leitura + lançamento manual (ver doc de StockMovementsController). Sem
 * editar/excluir linha: ledger imutável, uma correção lança um ajuste novo
 * (Nova movimentação).
 */
const props = defineProps({
    breadcrumbs:   { type: Array,  default: () => [] },
    items:         { type: Object, required: true },
    products:      { type: Array,  default: () => [] },
    lotsByProduct: { type: Object, default: () => ({}) },
    movementTypes: { type: Array,  default: () => [] },   // tipos manuais (form)
    filterTypes:   { type: Array,  default: () => [] },   // todos os tipos (filtro)
    filteredProduct: { type: Object, default: null },     // { id, name } do filtro, mesmo inativo
    filters:       { type: Object, default: () => ({}) }, // { search, entity_product_id, type, sort, direction }
    routes:        { type: Object, required: true },
    t:             { type: Object, default: () => ({}) },
});

const SEARCH_DEBOUNCE_MS = 400;

const page = usePage();
const flashMessage = computed(() => page.props?.flash?.message ?? null);

// Fechar é estado local (data-bs-dismiss removeria o nó que o Vue controla).
// Cada resposta traz um objeto `flash` novo: um segundo lançamento com a MESMA
// mensagem volta a mostrar o alerta.
const flashDismissed = ref(false);
watch(() => page.props?.flash, () => { flashDismissed.value = false; });

const pageTitle = computed(() => props.t.page_title ?? 'Movimentação de estoque');

// ── Alternância tabela/cards (preferência no navegador) ──────────────────────
const { view, setView } = useViewMode('stock_movements_view');

// ── Busca (debounce), filtros e ordenação — cada um preserva os demais ───────
const search        = ref(props.filters?.search ?? '');
const productFilter = ref(props.filters?.entity_product_id ?? '');
const typeFilter    = ref(props.filters?.type ?? '');

// O extrato também tem entradas por compra e consumos de procedimento: o
// filtro lista todos os tipos (o form continua só com os manuais).
const typeOptions = computed(() => (props.filterTypes.length ? props.filterTypes : props.movementTypes));

// Produto filtrado pode estar inativo (fora de `products`, ex.: atalho vindo de
// Produtos): mantém uma opção com o nome vindo do backend (`filteredProduct`,
// escopado pela clínica) para o select não ficar em branco. Até a resposta
// chegar (atalho da linha), usa o nome da própria linha.
const productOptions = computed(() => {
    const id = productFilter.value;
    if (!id || props.products.some((p) => p.id === id)) return props.products;

    const row  = (props.items?.data ?? []).find((m) => m.entity_product_id === id);
    const name = (props.filteredProduct?.id === id ? props.filteredProduct.name : null) ?? row?.product_name ?? '—';

    return [...props.products, { id, name }];
});

function currentParams(overrides = {}) {
    return {
        search:            search.value,
        entity_product_id: productFilter.value,
        type:              typeFilter.value,
        sort:              props.filters?.sort,
        direction:         props.filters?.direction,
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

watch([productFilter, typeFilter], () => visit(currentParams(), { replace: true }));

function onSort({ sort, direction }) {
    visit(currentParams({ sort, direction }));
}

function onFilterProduct(productId) {
    productFilter.value = productId ?? '';
}

// ── Lançamento manual ────────────────────────────────────────────────────────
// store() volta para o extrato com a mesma busca/filtros/ordem/página
// (RedirectsToListing): a resposta já traz lista, saldos e o flash.
const formOpen = ref(false);

function openForm() {
    formOpen.value = true;
}

function onSaved() {
    formOpen.value = false;
}
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="page-stock-movements">

            <PageHeader
                :title="pageTitle"
                :total="items.total ?? 0"
                :total-label="t.total_label ?? 'Total:'"
                show-view-toggle
                :view="view"
                :view-table-title="t.view_table ?? 'Tabela'"
                :view-cards-title="t.view_cards ?? 'Cards'"
                @set-view="setView"
            >
                <template #actions>
                    <div class="d-flex align-items-center gap-2">
                        <Link :href="routes.products_index" class="btn btn-outline-secondary fs-13 btn-md">
                            <i class="ti ti-package me-1" aria-hidden="true"></i> {{ t.btn_products ?? 'Produtos' }}
                        </Link>
                        <button type="button" class="btn btn-primary fs-13 btn-md new-movement" @click="openForm">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i> {{ t.btn_new ?? 'Nova movimentação' }}
                        </button>
                    </div>
                </template>
            </PageHeader>

            <div v-if="flashMessage && !flashDismissed" class="alert alert-success alert-dismissible mb-3" role="status">
                <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ flashMessage }}
                <button
                    type="button"
                    class="btn-close"
                    :aria-label="t.close ?? 'Fechar'"
                    @click="flashDismissed = true"
                ></button>
            </div>

            <!-- Busca + filtros na mesma barra -->
            <div class="stock-toolbar d-flex align-items-center flex-wrap gap-2 mb-3">
                <SearchInput
                    v-model="search"
                    :placeholder="t.search_placeholder ?? 'Buscar...'"
                    :clear-label="t.search_clear"
                    max-width="280px"
                    wrapper-class=""
                />
                <select
                    v-model="productFilter"
                    class="form-select form-select-sm stock-toolbar__select"
                    :aria-label="t.filter_product ?? 'Filtrar por produto'"
                >
                    <option value="">{{ t.filter_product_all ?? 'Todos os produtos' }}</option>
                    <option v-for="p in productOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <select
                    v-model="typeFilter"
                    class="form-select form-select-sm stock-toolbar__select"
                    :aria-label="t.filter_type ?? 'Filtrar por tipo'"
                >
                    <option value="">{{ t.filter_type_all ?? 'Todos os tipos' }}</option>
                    <option v-for="type in typeOptions" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
            </div>

            <MovementTable
                v-if="view === 'table'"
                :items="items"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @filter-product="onFilterProduct"
            />
            <MovementCards
                v-else
                :items="items"
                :filters="filters"
                :t="t"
                @filter-product="onFilterProduct"
            />

            <MovementFormModal
                :open="formOpen"
                :routes="routes"
                :products="products"
                :lots-by-product="lotsByProduct"
                :movement-types="movementTypes"
                @close="formOpen = false"
                @saved="onSaved"
            />

        </div>
    </AppLayout>
</template>

<style scoped>
.stock-toolbar__select {
    max-width: 240px;
}
</style>
