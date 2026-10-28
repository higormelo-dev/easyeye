<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import { useViewMode } from '@/composables/useViewMode.js';
import { useTrans } from '@/composables/useTrans.js';
import SupplierTable from './SupplierTable.vue';
import SupplierCards from './SupplierCards.vue';
import SupplierFormModal from './SupplierFormModal.vue';

/**
 * Listagem de fornecedores — mesmo layout de Panel/Patients/Index: cabeçalho
 * com total, alternância tabela/cards (persistida no navegador), busca +
 * filtro de status que preservam a ordenação, tabela/cards com as mesmas
 * ações. Textos vêm de lang/{locale}/stock_suppliers.php (prop `t`).
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    items: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) }, // { search, status, sort, direction }
    routes: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
});

const { tx } = useTrans(() => props.t);

const page = usePage();
const flashMessage = computed(() => page.props?.flash?.message ?? null);

// Fechar o aviso é estado local (nunca data-bs-dismiss: o Bootstrap removeria
// o nó que o Vue controla). Nova resposta com flash — mesmo texto repetido
// numa segunda ação — mostra o aviso de novo.
const flashDismissed = ref(false);
watch([flashMessage, () => page.props?.flash], () => {
    flashDismissed.value = false;
});

// ── View toggle (preferência no navegador) ───────────────────────────────────
const { view, setView } = useViewMode('stock_suppliers_view');

// ── Busca (debounce), status e ordenação — cada um preserva os outros ───────
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
let searchTimer = null;

function visit(params, options = {}) {
    router.get(props.routes.index, params, { preserveState: true, preserveScroll: true, ...options });
}

function currentParams(overrides = {}) {
    return {
        search: search.value,
        status: status.value,
        sort: props.filters.sort,
        direction: props.filters.direction,
        ...overrides,
    };
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit(currentParams(), { replace: true }), 400);
});

watch(status, () => visit(currentParams(), { replace: true }));

onBeforeUnmount(() => clearTimeout(searchTimer));

function onSort({ sort, direction }) {
    visit(currentParams({ sort, direction }));
}

// ── CRUD modal ───────────────────────────────────────────────────────────────
const formOpen = ref(false);
const editItem = ref(null);

function openCreate() {
    editItem.value = null;
    formOpen.value = true;
}
function openEdit(supplier) {
    editItem.value = supplier;
    formOpen.value = true;
}
function onSaved() {
    formOpen.value = false;
    router.reload({ only: ['items'] });
}

function onDelete(supplier) {
    if (!confirm(tx('confirm_delete', { name: supplier.name }))) return;
    router.delete(props.routes.destroy.replace('__ID__', supplier.id), { preserveScroll: true });
}

const pageTitle = computed(() => props.t.page_title ?? 'Fornecedores');
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="page-stock-suppliers">
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
                        <Link :href="routes.purchase_orders_index" class="btn btn-outline-secondary fs-13 btn-md">
                            <i class="ti ti-shopping-cart me-1" aria-hidden="true"></i>
                            {{ t.btn_purchase_orders ?? 'Pedidos de compra' }}
                        </Link>
                        <button type="button" class="btn btn-primary fs-13 btn-md" @click="openCreate">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i> {{ t.btn_new ?? 'Novo fornecedor' }}
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

            <!-- Busca + filtros na mesma linha -->
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
                    :aria-label="t.filter_status_label ?? 'Filtrar por status'"
                >
                    <option value="all">{{ t.filter_status_all ?? 'Todos' }}</option>
                    <option value="active">{{ t.filter_status_active ?? 'Ativos' }}</option>
                    <option value="inactive">{{ t.filter_status_inactive ?? 'Inativos' }}</option>
                </select>
            </div>

            <SupplierTable
                v-if="view === 'table'"
                :items="items"
                :filters="filters"
                :t="t"
                :purchase-orders-url="routes.purchase_orders_index"
                @sort="onSort"
                @edit="openEdit"
                @delete="onDelete"
            />
            <SupplierCards
                v-else
                :items="items"
                :t="t"
                :purchase-orders-url="routes.purchase_orders_index"
                @edit="openEdit"
                @delete="onDelete"
            />
        </div>

        <SupplierFormModal
            :open="formOpen"
            :item="editItem"
            :routes="routes"
            @close="formOpen = false"
            @saved="onSaved"
        />
    </AppLayout>
</template>
