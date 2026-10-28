<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import { useViewMode } from '@/composables/useViewMode.js';
import { useTrans } from '@/composables/useTrans.js';
import ProductTable from './ProductTable.vue';
import ProductCards from './ProductCards.vue';
import ProductFormModal from './ProductFormModal.vue';

/**
 * Catálogo de produtos/materiais de estoque — mesmo layout de
 * Panel/Patients/Index: cabeçalho com total, alternância tabela/cards
 * (persistida no navegador), movimentação/importar/novo, busca + filtros do
 * estoque (status, categoria, abaixo do mínimo, lote vencendo) que preservam
 * a ordenação, e tabela/cards com as mesmas ações. Os cards usam o MESMO
 * paginator da tabela, com saldo/custo/preço em linhas rotuladas.
 * Textos vêm de lang/{locale}/stock_products.php (prop `t`).
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    items: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    units: { type: Array, default: () => [] },
    // { search, status, category_id, low_stock, expiring_lots, sort, direction } — normalizados no backend
    filters: { type: Object, default: () => ({}) },
    routes: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
});

const { tx } = useTrans(() => props.t);
const { view, setView } = useViewMode('stock_products_view');

const page = usePage();
// Backend flasheia `message` (compartilhado por HandleInertiaRequests) — o
// toast do AppLayout não o escuta, então o alerta é local.
const flashMessage = computed(() => page.props?.flash?.message ?? null);

// Fechar o alerta é estado local: `data-bs-dismiss` faria o Bootstrap remover
// do DOM um nó que o Vue controla. Cada flash novo volta a exibi-lo — mesmo
// com o texto repetido (ex.: duas edições seguidas trazem outro objeto flash).
const flashDismissed = ref(false);
watch([() => page.props?.flash, flashMessage], () => {
    flashDismissed.value = false;
});

const pageTitle = computed(() => props.t.page_title ?? 'Produtos');

// ── Busca (debounce) + filtros + ordenação — um preserva os outros ──────────
const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? 'all');
const categoryId = ref(props.filters?.category_id ?? '');
const lowStock = ref(!!props.filters?.low_stock);
const expiringLots = ref(!!props.filters?.expiring_lots);

function currentParams(overrides = {}) {
    return {
        search: search.value,
        status: status.value,
        category_id: categoryId.value,
        low_stock: lowStock.value ? 1 : 0,
        expiring_lots: expiringLots.value ? 1 : 0,
        sort: props.filters?.sort,
        direction: props.filters?.direction,
        ...overrides,
    };
}

function visit(params, options = {}) {
    router.get(props.routes.index, params, { preserveState: true, preserveScroll: true, ...options });
}

let searchTimer = null;

function applyFilters() {
    clearTimeout(searchTimer);
    visit(currentParams(), { replace: true });
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 400);
});
watch([status, categoryId, lowStock, expiringLots], applyFilters);

onBeforeUnmount(() => clearTimeout(searchTimer));

function onSort({ sort, direction }) {
    clearTimeout(searchTimer);
    visit(currentParams({ sort, direction }));
}

// ── Modal criar/editar ──────────────────────────────────────────────────────
const formOpen = ref(false);
const editItem = ref(null);

function openCreate() {
    editItem.value = null;
    formOpen.value = true;
}

function openEdit(product) {
    editItem.value = product;
    formOpen.value = true;
}

function onSaved() {
    formOpen.value = false;
    router.reload({ only: ['items'] });
}

// ── Ações da linha/card ─────────────────────────────────────────────────────
const actionError = ref(null);

// Ativar/desativar reaproveita o update do produto só com os campos
// obrigatórios + active (EntityProductRequest valida só o que vier; os demais
// campos ficam como estão). Nome/unidade vêm do registro ATUAL (routes.show,
// que re-checa posse), não da linha carregada — assim o clique não desfaz uma
// edição feita por outra pessoa depois que a página abriu. O status alvo é o
// inverso do que o usuário viu na linha.
async function onToggleActive(product) {
    actionError.value = null;
    let current;
    try {
        const { data } = await window.axios.get(props.routes.show.replace('__ID__', product.id));
        current = data?.data;
    } catch {
        current = null;
    }
    if (!current) {
        actionError.value = props.t.toggle_error ?? 'Não foi possível carregar os dados atuais do produto.';
        return;
    }
    router.put(
        props.routes.update.replace('__ID__', product.id),
        { name: current.name, unit: current.unit, active: !product.active },
        { preserveScroll: true },
    );
}

function onDelete(product) {
    if (!confirm(tx('confirm_delete', { name: product.name }))) return;
    router.delete(props.routes.destroy.replace('__ID__', product.id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="page-stock-products">
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
                    <div class="d-flex align-items-center flex-wrap justify-content-end gap-2">
                        <Link
                            :href="routes.movements_index"
                            class="btn btn-outline-secondary fs-13 btn-md"
                            :title="t.btn_movements ?? 'Movimentação'"
                            :aria-label="t.btn_movements ?? 'Movimentação'"
                        >
                            <i class="ti ti-transfer-in" aria-hidden="true"></i>
                            <span class="d-none d-md-inline ms-1">{{ t.btn_movements ?? 'Movimentação' }}</span>
                        </Link>
                        <!-- Importação em massa via CSV — ver ProductImportsController. -->
                        <Link
                            :href="routes.import_index"
                            class="btn btn-outline-secondary fs-13 btn-md"
                            :title="t.btn_import ?? 'Importar'"
                            :aria-label="t.btn_import ?? 'Importar'"
                        >
                            <i class="ti ti-upload" aria-hidden="true"></i>
                            <span class="d-none d-md-inline ms-1">{{ t.btn_import ?? 'Importar' }}</span>
                        </Link>
                        <button type="button" class="btn btn-primary fs-13 btn-md" @click="openCreate">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.btn_new ?? 'Novo produto' }}
                        </button>
                    </div>
                </template>
            </PageHeader>

            <div
                v-if="flashMessage && !flashDismissed"
                class="alert alert-success alert-dismissible mb-3"
                role="status"
            >
                <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ flashMessage }}
                <button
                    type="button"
                    class="btn-close"
                    :aria-label="t.close ?? 'Fechar'"
                    @click="flashDismissed = true"
                ></button>
            </div>

            <div v-if="actionError" class="alert alert-danger alert-dismissible mb-3" role="alert">
                <i class="ti ti-alert-circle me-1" aria-hidden="true"></i>{{ actionError }}
                <button
                    type="button"
                    class="btn-close"
                    :aria-label="t.close ?? 'Fechar'"
                    @click="actionError = null"
                ></button>
            </div>

            <!-- Busca + filtros do estoque (mesma linha) -->
            <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
                <SearchInput
                    v-model="search"
                    wrapper-class=""
                    :placeholder="t.search_placeholder ?? 'Buscar...'"
                    :clear-label="t.search_clear ?? 'Limpar busca'"
                    max-width="280px"
                />
                <select
                    v-model="status"
                    class="form-select form-select-sm w-auto"
                    :aria-label="t.filter_status_label ?? 'Status'"
                >
                    <option value="all">{{ t.filter_status_all ?? 'Todos' }}</option>
                    <option value="active">{{ t.filter_status_active ?? 'Ativos' }}</option>
                    <option value="inactive">{{ t.filter_status_inactive ?? 'Inativos' }}</option>
                </select>
                <select
                    v-model="categoryId"
                    class="form-select form-select-sm w-auto stock-toolbar-select"
                    :aria-label="t.filter_category_label ?? 'Categoria'"
                >
                    <option value="">{{ t.category_all ?? 'Todas as categorias' }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <div class="form-check mb-0 ms-1">
                    <input id="low_stock_filter" v-model="lowStock" type="checkbox" class="form-check-input" />
                    <label class="form-check-label small" for="low_stock_filter">{{
                        t.filter_low_stock ?? 'Só abaixo do mínimo'
                    }}</label>
                </div>
                <div class="form-check mb-0">
                    <input id="expiring_lots_filter" v-model="expiringLots" type="checkbox" class="form-check-input" />
                    <label class="form-check-label small" for="expiring_lots_filter">{{
                        t.filter_expiring_lots ?? 'Só com lote vencendo (30d)'
                    }}</label>
                </div>
            </div>

            <ProductTable
                v-if="view === 'table'"
                :items="items"
                :filters="filters"
                :t="t"
                :movements-index-url="routes.movements_index"
                @sort="onSort"
                @edit="openEdit"
                @toggle-active="onToggleActive"
                @delete="onDelete"
            />
            <ProductCards
                v-else
                :items="items"
                :t="t"
                :movements-index-url="routes.movements_index"
                @edit="openEdit"
                @toggle-active="onToggleActive"
                @delete="onDelete"
            />
        </div>

        <ProductFormModal
            :open="formOpen"
            :item="editItem"
            :routes="routes"
            :categories="categories"
            :units="units"
            @close="formOpen = false"
            @saved="onSaved"
        />
    </AppLayout>
</template>

<style scoped>
.stock-toolbar-select {
    max-width: 220px;
}
</style>
