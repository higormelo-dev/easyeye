<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import { useViewMode } from '@/composables/useViewMode.js';
import { useTrans } from '@/composables/useTrans.js';
import PurchaseOrderTable from './PurchaseOrderTable.vue';
import PurchaseOrderCards from './PurchaseOrderCards.vue';
import PurchaseOrderFormModal from './PurchaseOrderFormModal.vue';
import ReceivePurchaseOrderModal from './ReceivePurchaseOrderModal.vue';

/**
 * Listagem de pedidos de compra — mesmo layout de Panel/Patients/Index:
 * cabeçalho com total, alternância tabela/cards (persistida no navegador),
 * busca + filtros de status/fornecedor que preservam a ordenação, tabela/
 * cards com as mesmas ações por status. Textos vêm de
 * lang/{locale}/stock_purchase_orders.php (prop `t`).
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    items: { type: Object, required: true },
    suppliers: { type: Array, default: () => [] },
    // Fornecedor filtrado fora de `suppliers` (inativo), já escopado pela clínica: { id, name } | null.
    selectedSupplier: { type: Object, default: null },
    // Opções do filtro de status com rótulo traduzido (PurchaseOrderStatus::label()): [{ value, label }].
    statuses: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    lotsByProduct: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) }, // { search, status, supplier_id, sort, direction }
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
// Falha de transição (enviar/cancelar/excluir) volta como erro de sessão.
const statusError = computed(() => page.props?.errors?.status ?? null);
const loadError = ref('');

// ── View toggle (preferência no navegador) ───────────────────────────────────
const { view, setView } = useViewMode('stock_purchase_orders_view');

// ── Busca (debounce), filtros e ordenação — cada um preserva os outros ──────
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
const supplierId = ref(props.filters.supplier_id ?? '');
let searchTimer = null;

function visit(params, options = {}) {
    router.get(props.routes.index, params, { preserveState: true, preserveScroll: true, ...options });
}

function currentParams(overrides = {}) {
    return {
        search: search.value,
        status: status.value,
        supplier_id: supplierId.value,
        sort: props.filters.sort,
        direction: props.filters.direction,
        ...overrides,
    };
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit(currentParams(), { replace: true }), 400);
});

watch([status, supplierId], () => visit(currentParams(), { replace: true }));

onBeforeUnmount(() => clearTimeout(searchTimer));

function onSort({ sort, direction }) {
    visit(currentParams({ sort, direction }));
}

// supplier_id fora da lista de ativos (atalho de um fornecedor inativo): a
// opção precisa aparecer no select. Com o nome quando o backend o encontrou
// na clínica (`selectedSupplier`); excluído, de outra clínica ou inexistente
// (lista vazia) fica com rótulo neutro, sem afirmar nada sobre ele.
const supplierUnlisted = computed(
    () => supplierId.value !== '' && !props.suppliers.some((s) => s.id === supplierId.value),
);

const supplierUnlistedLabel = computed(() => {
    const selected = props.selectedSupplier;
    if (selected?.id === supplierId.value && selected?.name) {
        return props.t.filter_supplier_inactive
            ? tx('filter_supplier_inactive', { name: selected.name })
            : selected.name;
    }

    return props.t.filter_supplier_unlisted ?? supplierId.value;
});

// ── Form modal (rascunho) ────────────────────────────────────────────────────
const formOpen = ref(false);
const editItem = ref(null);

function openCreate() {
    editItem.value = null;
    formOpen.value = true;
}

async function openEdit(po) {
    loadError.value = '';
    try {
        const { data } = await window.axios.get(props.routes.show.replace('__ID__', po.id));
        editItem.value = data.data;
        formOpen.value = true;
    } catch {
        loadError.value = props.t.load_error ?? 'Não foi possível abrir o pedido.';
    }
}

function onSaved() {
    formOpen.value = false;
    router.reload({ only: ['items'] });
}

// ── Recebimento ──────────────────────────────────────────────────────────────
const receiveOpen = ref(false);
const receivingPo = ref(null);

function openReceive(po) {
    receivingPo.value = po;
    receiveOpen.value = true;
}
function onReceived() {
    receiveOpen.value = false;
    router.reload({ only: ['items', 'products', 'lotsByProduct'] });
}

// ── Transições de status ─────────────────────────────────────────────────────
function onSend(po) {
    if (!confirm(tx('confirm_send', { code: po.code }))) return;
    router.post(props.routes.send.replace('__ID__', po.id), {}, { preserveScroll: true });
}

function onCancel(po) {
    if (!confirm(tx('confirm_cancel', { code: po.code }))) return;
    router.post(props.routes.cancel.replace('__ID__', po.id), {}, { preserveScroll: true });
}

function onDelete(po) {
    if (!confirm(tx('confirm_delete', { code: po.code }))) return;
    router.delete(props.routes.destroy.replace('__ID__', po.id), { preserveScroll: true });
}

const pageTitle = computed(() => props.t.page_title ?? 'Pedidos de compra');
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="page-stock-purchase-orders">
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
                        <Link :href="routes.suppliers_index" class="btn btn-outline-secondary fs-13 btn-md">
                            <i class="ti ti-truck-delivery me-1" aria-hidden="true"></i>
                            {{ t.btn_suppliers ?? 'Fornecedores' }}
                        </Link>
                        <button type="button" class="btn btn-primary fs-13 btn-md" @click="openCreate">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i> {{ t.btn_new ?? 'Novo pedido' }}
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

            <div
                v-if="statusError || loadError"
                class="alert alert-danger d-flex align-items-center gap-2 mb-3"
                role="alert"
            >
                <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                <span>{{ statusError ?? loadError }}</span>
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
                    <option value="all">{{ t.filter_status_all ?? 'Todos os status' }}</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <select
                    v-model="supplierId"
                    class="form-select form-select-sm w-auto stock-toolbar-select"
                    :aria-label="t.filter_supplier_label ?? 'Filtrar por fornecedor'"
                >
                    <option value="">{{ t.filter_supplier_all ?? 'Todos os fornecedores' }}</option>
                    <option v-if="supplierUnlisted" :value="supplierId">{{ supplierUnlistedLabel }}</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </div>

            <PurchaseOrderTable
                v-if="view === 'table'"
                :items="items"
                :filters="filters"
                :t="t"
                :pdf-url-template="routes.pdf"
                @sort="onSort"
                @edit="openEdit"
                @send="onSend"
                @receive="openReceive"
                @cancel="onCancel"
                @delete="onDelete"
            />
            <PurchaseOrderCards
                v-else
                :items="items"
                :t="t"
                :pdf-url-template="routes.pdf"
                @edit="openEdit"
                @send="onSend"
                @receive="openReceive"
                @cancel="onCancel"
                @delete="onDelete"
            />
        </div>

        <PurchaseOrderFormModal
            :open="formOpen"
            :item="editItem"
            :routes="routes"
            :suppliers="suppliers"
            :products="products"
            @close="formOpen = false"
            @saved="onSaved"
        />

        <ReceivePurchaseOrderModal
            :open="receiveOpen"
            :purchase-order="receivingPo"
            :routes="routes"
            :lots-by-product="lotsByProduct"
            @close="receiveOpen = false"
            @saved="onReceived"
        />
    </AppLayout>
</template>

<style scoped>
/* Nome de fornecedor longo não estoura a linha no celular. */
.stock-toolbar-select {
    max-width: 260px;
}
</style>
