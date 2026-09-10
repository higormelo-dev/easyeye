<script setup>
import { ref, computed, watch } from 'vue';
import { router, usePage, Link } from '@inertiajs/vue3';
import AppLayout       from '@/Layouts/AppLayout.vue';
import PageHeader      from '@/Components/Panel/PageHeader.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import MovementFormModal from './MovementFormModal.vue';

/**
 * Extrato de movimentação de estoque — só leitura + lançamento manual (ver
 * doc de StockMovementsController). Sem editar/excluir linha: ledger
 * imutável, uma correção lança um ajuste novo (Nova movimentação).
 */
const props = defineProps({
    breadcrumbs:    { type: Array,  default: () => [] },
    items:          { type: Object, required: true },
    products:       { type: Array,  default: () => [] },
    lotsByProduct:  { type: Object, default: () => ({}) },
    movementTypes:  { type: Array,  default: () => [] },
    filters:        { type: Object, default: () => ({}) },
    routes:         { type: Object, required: true },
});

const page = usePage();
const flashMessage = computed(() => page.props?.flash?.message ?? null);

const productFilter = ref(props.filters?.entity_product_id ?? '');
const typeFilter     = ref(props.filters?.type ?? '');

function applyFilters() {
    router.get(props.routes.index, {
        entity_product_id: productFilter.value,
        type: typeFilter.value,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

watch([productFilter, typeFilter], applyFilters);

const formOpen = ref(false);

function onSaved() {
    formOpen.value = false;
    router.reload({ only: ['items', 'products', 'lotsByProduct'] });
}

function directionIcon(direction) {
    return direction === 1 ? 'ti ti-arrow-up text-success' : 'ti ti-arrow-down text-danger';
}
</script>

<template>
    <AppLayout title="Movimentação de estoque" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">

            <PageHeader title="Movimentação de estoque" :total="items.total">
                <template #actions>
                    <Link :href="routes.products_index" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="ti ti-package me-1"></i>Produtos
                    </Link>
                    <button type="button" class="btn btn-primary btn-sm" @click="formOpen = true">
                        <i class="ti ti-plus me-1"></i>Nova movimentação
                    </button>
                </template>
            </PageHeader>

            <div v-if="flashMessage" class="alert alert-success alert-dismissible fade show mb-3">
                <i class="ti ti-circle-check me-1"></i>{{ flashMessage }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
                <select v-model="productFilter" class="form-select form-select-sm" style="max-width: 280px;">
                    <option value="">Todos os produtos</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <select v-model="typeFilter" class="form-select form-select-sm" style="max-width: 220px;">
                    <option value="">Todos os tipos</option>
                    <option v-for="t in movementTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
            </div>

            <div v-if="items.data.length === 0" class="text-center text-muted py-5">
                <i class="ti ti-transfer-in fs-1 d-block mb-2"></i>
                Nenhuma movimentação registrada.
            </div>

            <div v-else class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Produto</th>
                            <th>Lote</th>
                            <th>Tipo</th>
                            <th class="text-end">Quantidade</th>
                            <th class="text-end">Custo unit.</th>
                            <th class="text-end">Saldo após</th>
                            <th>Observação</th>
                            <th>Por</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in items.data" :key="m.id">
                            <td class="text-muted small text-nowrap">{{ m.occurred_at }}</td>
                            <td>{{ m.product_name }} <span class="text-muted small">({{ m.product_code }})</span></td>
                            <td class="small text-muted">{{ m.lot_number ?? '—' }}</td>
                            <td>
                                <i :class="directionIcon(m.direction)" class="me-1"></i>{{ m.type_label }}
                            </td>
                            <td class="text-end">{{ m.quantity }}</td>
                            <td class="text-end">{{ m.unit_cost !== null ? `R$ ${Number(m.unit_cost).toFixed(2)}` : '—' }}</td>
                            <td class="text-end fw-semibold">{{ m.balance_after }}</td>
                            <td class="small text-muted">{{ m.note ?? '—' }}</td>
                            <td class="small text-muted text-nowrap">{{ m.created_by_name ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <TablePagination :data="items" showing-suffix="movimentações" />

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
