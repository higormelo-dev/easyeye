<script setup>
import { ref, computed, watch } from 'vue';
import { router, usePage, Link } from '@inertiajs/vue3';
import AppLayout        from '@/Layouts/AppLayout.vue';
import PageHeader       from '@/Components/Panel/PageHeader.vue';
import SearchInput      from '@/Components/Panel/SearchInput.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ProductFormModal from './ProductFormModal.vue';

/**
 * Catálogo de produtos/materiais de estoque (Fase 1) — tabela em vez de
 * cards (diferente de IolLenses): produto tem colunas numéricas (saldo,
 * custo, preço) que ficam ilegíveis num grid de cards, mesmo raciocínio de
 * telas financeiras (CashFlow/ProcedurePrices) desta base.
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    items:       { type: Object, required: true },
    categories:  { type: Array,  default: () => [] },
    units:       { type: Array,  default: () => [] },
    filters:     { type: Object, default: () => ({}) },
    routes:      { type: Object, required: true },
});

const page = usePage();
const flashMessage = computed(() => page.props?.flash?.message ?? null);

const search       = ref(props.filters?.search ?? '');
const status       = ref(props.filters?.status ?? 'all');
const categoryId   = ref(props.filters?.category_id ?? '');
const lowStock     = ref(!!props.filters?.low_stock);
const expiringLots = ref(!!props.filters?.expiring_lots);

function applyFilters() {
    router.get(props.routes.index, {
        search: search.value,
        status: status.value,
        category_id: categoryId.value,
        low_stock: lowStock.value ? 1 : 0,
        expiring_lots: expiringLots.value ? 1 : 0,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 400);
});
watch([status, categoryId, lowStock, expiringLots], applyFilters);

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

function onDelete(event, product) {
    event?.stopPropagation?.();
    if (!confirm(`Desativar o produto "${product.name}"?`)) return;
    router.delete(props.routes.destroy.replace('__ID__', product.id), { preserveScroll: true });
}

function money(v) {
    return v === null || v === undefined ? '—' : `R$ ${Number(v).toFixed(2)}`;
}
</script>

<template>
    <AppLayout title="Produtos" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">

            <PageHeader title="Produtos" :total="items.total">
                <template #actions>
                    <Link :href="routes.movements_index" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="ti ti-transfer-in me-1"></i>Movimentação
                    </Link>
                    <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <i class="ti ti-plus me-1"></i>Novo produto
                    </button>
                </template>
            </PageHeader>

            <div v-if="flashMessage" class="alert alert-success alert-dismissible fade show mb-3">
                <i class="ti ti-circle-check me-1"></i>{{ flashMessage }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
                <SearchInput v-model="search" placeholder="Buscar por nome, SKU ou código..." style="min-width: 260px;" />
                <select v-model="status" class="form-select form-select-sm" style="max-width: 160px;">
                    <option value="all">Todos</option>
                    <option value="active">Ativos</option>
                    <option value="inactive">Inativos</option>
                </select>
                <select v-model="categoryId" class="form-select form-select-sm" style="max-width: 220px;">
                    <option value="">Todas as categorias</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <div class="form-check ms-1">
                    <input id="low_stock_filter" v-model="lowStock" type="checkbox" class="form-check-input">
                    <label class="form-check-label small" for="low_stock_filter">Só abaixo do mínimo</label>
                </div>
                <div class="form-check">
                    <input id="expiring_lots_filter" v-model="expiringLots" type="checkbox" class="form-check-input">
                    <label class="form-check-label small" for="expiring_lots_filter">Só com lote vencendo (30d)</label>
                </div>
            </div>

            <div v-if="items.data.length === 0" class="text-center text-muted py-5">
                <i class="ti ti-package-off fs-1 d-block mb-2"></i>
                Nenhum produto cadastrado.
            </div>

            <div v-else class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nome</th>
                            <th>Categoria</th>
                            <th>Unidade</th>
                            <th class="text-end">Saldo</th>
                            <th class="text-end">Custo médio</th>
                            <th class="text-end">Preço</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in items.data" :key="p.id" role="button" @click="openEdit(p)">
                            <td class="text-muted small">{{ p.code }}</td>
                            <td>
                                {{ p.name }}
                                <span v-if="p.is_opm" class="badge badge-soft-info ms-1" title="OPM">OPM</span>
                                <span
                                    v-if="p.has_expiring_lot"
                                    class="badge badge-soft-warning text-warning ms-1"
                                    :title="`Vence: ${p.nearest_expiry}`"
                                >
                                    <i class="ti ti-alert-triangle"></i> Lote vencendo
                                </span>
                            </td>
                            <td>{{ p.category_name ?? '—' }}</td>
                            <td>{{ p.unit_label }}</td>
                            <td class="text-end">
                                <span :class="p.below_minimum ? 'text-danger fw-semibold' : ''">
                                    {{ p.qty_on_hand }}
                                </span>
                                <i v-if="p.below_minimum" class="ti ti-alert-triangle text-danger ms-1" title="Abaixo do mínimo"></i>
                            </td>
                            <td class="text-end">{{ money(p.cost_avg) }}</td>
                            <td class="text-end">{{ money(p.sale_price) }}</td>
                            <td>
                                <span
                                    class="badge rounded fs-11 fw-medium"
                                    :class="p.active ? 'badge-soft-success text-success border border-success' : 'badge-soft-secondary'"
                                >
                                    {{ p.active ? 'Ativo' : 'Inativo' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <ActionIconButton icon="ti ti-trash" title="Desativar" variant="danger" @click="onDelete($event, p)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <TablePagination :data="items" showing-suffix="produtos" />

            <ProductFormModal
                :open="formOpen"
                :item="editItem"
                :routes="routes"
                :categories="categories"
                :units="units"
                @close="formOpen = false"
                @saved="onSaved"
            />

        </div>
    </AppLayout>
</template>
