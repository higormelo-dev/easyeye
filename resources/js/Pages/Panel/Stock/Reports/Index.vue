<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * Relatórios de estoque (GAP fechado nesta revisão) — App\Services\Stock\
 * StockReportService. Tudo lido server-side já pronto pra exibir; a única
 * interação client-side é o filtro de período (from/to), que recarrega a
 * página via Inertia (mesmo padrão de FinancialReportsController).
 */
const props = defineProps({
    breadcrumbs:             { type: Array,  default: () => [] },
    filters:                 { type: Object, required: true },
    valuedInventory:         { type: Object, required: true }, // { items, total_value }
    turnover:                { type: Array,  default: () => [] },
    consumptionByProcedure:  { type: Array,  default: () => [] },
    purchasesBySupplier:     { type: Array,  default: () => [] },
    routes:                  { type: Object, required: true },
});

const from = ref(props.filters.from);
const to   = ref(props.filters.to);

function applyFilters() {
    router.get(props.routes.index, { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true, replace: true });
}

const activeTab = ref('inventory');

function money(v) { return v === null || v === undefined ? '—' : `R$ ${Number(v).toFixed(2)}`; }

const ABC_BADGE = { A: 'badge-soft-success text-success', B: 'badge-soft-warning text-warning', C: 'badge-soft-secondary' };
</script>

<template>
    <AppLayout title="Relatórios de estoque" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">

            <PageHeader title="Relatórios de estoque" />

            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <label class="small text-muted mb-0">Período (giro/consumo/compras):</label>
                <input v-model="from" type="date" class="form-control form-control-sm" style="max-width:160px;" @change="applyFilters">
                <span class="text-muted small">até</span>
                <input v-model="to" type="date" class="form-control form-control-sm" style="max-width:160px;" @change="applyFilters">
            </div>

            <ul class="nav nav-tabs mb-3">
                <li class="nav-item"><button type="button" class="nav-link" :class="{ active: activeTab === 'inventory' }" @click="activeTab = 'inventory'">Posição valorizada / Curva ABC</button></li>
                <li class="nav-item"><button type="button" class="nav-link" :class="{ active: activeTab === 'turnover' }" @click="activeTab = 'turnover'">Giro de estoque</button></li>
                <li class="nav-item"><button type="button" class="nav-link" :class="{ active: activeTab === 'consumption' }" @click="activeTab = 'consumption'">Consumo por procedimento</button></li>
                <li class="nav-item"><button type="button" class="nav-link" :class="{ active: activeTab === 'purchases' }" @click="activeTab = 'purchases'">Compras por fornecedor</button></li>
            </ul>

            <!-- Posição valorizada + Curva ABC -->
            <div v-show="activeTab === 'inventory'">
                <div class="alert alert-light border mb-3">
                    Valor total em estoque: <strong>{{ money(valuedInventory.total_value) }}</strong>
                </div>
                <div v-if="valuedInventory.items.length === 0" class="text-muted py-4 text-center">Nenhum produto com saldo.</div>
                <div v-else class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Produto</th><th>Categoria</th><th class="text-end">Saldo</th>
                                <th class="text-end">Custo médio</th><th class="text-end">Valor total</th>
                                <th class="text-end">% acumulado</th><th>Classe</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in valuedInventory.items" :key="row.id">
                                <td>{{ row.name }} <span class="text-muted small">({{ row.code }})</span></td>
                                <td class="small">{{ row.category_name ?? '—' }}</td>
                                <td class="text-end">{{ row.qty_on_hand }} {{ row.unit }}</td>
                                <td class="text-end">{{ money(row.cost_avg) }}</td>
                                <td class="text-end fw-semibold">{{ money(row.total_value) }}</td>
                                <td class="text-end small">{{ row.cumulative_pct }}%</td>
                                <td><span v-if="row.abc_class" class="badge rounded fs-11" :class="ABC_BADGE[row.abc_class]">{{ row.abc_class }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted d-block mt-2">
                    Curva ABC: classe A = produtos que somam até 80% do valor total em estoque, B = até 95%, C = o restante — convenção padrão pra priorizar controle nos itens de maior peso financeiro.
                </small>
            </div>

            <!-- Giro -->
            <div v-show="activeTab === 'turnover'">
                <div v-if="turnover.length === 0" class="text-muted py-4 text-center">Sem movimentação de saída no período.</div>
                <div v-else class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead><tr><th>Produto</th><th class="text-end">Saída no período</th><th class="text-end">Saldo atual</th><th class="text-end">Giro (saída ÷ saldo)</th></tr></thead>
                        <tbody>
                            <tr v-for="row in turnover" :key="row.id">
                                <td>{{ row.name }} <span class="text-muted small">({{ row.code }})</span></td>
                                <td class="text-end">{{ row.qty_out }}</td>
                                <td class="text-end">{{ row.qty_on_hand }}</td>
                                <td class="text-end">{{ row.turnover_ratio ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted d-block mt-2">
                    Giro aproximado (saída no período ÷ valor do saldo atual) — não é o giro clássico por saldo médio (exigiria snapshot diário de estoque, que o sistema não guarda). Útil pra comparar produto parado vs. girando.
                </small>
            </div>

            <!-- Consumo por procedimento -->
            <div v-show="activeTab === 'consumption'">
                <div v-if="consumptionByProcedure.length === 0" class="text-muted py-4 text-center">Nenhum consumo em procedimento registrado no período.</div>
                <div v-else>
                    <div v-for="(group, index) in consumptionByProcedure" :key="index" class="card mb-2">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>{{ group.procedure_name }}</strong>
                                    <span class="text-muted small ms-2">{{ group.doctor_name }} — {{ group.executed_at }}</span>
                                </div>
                                <strong>{{ money(group.total_cost) }}</strong>
                            </div>
                            <ul class="list-unstyled small text-muted mb-0 mt-1">
                                <li v-for="(item, i) in group.items" :key="i">{{ item.product_name }}: {{ item.quantity }} × {{ money(item.unit_cost) }} = {{ money(item.total_cost) }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Compras por fornecedor -->
            <div v-show="activeTab === 'purchases'">
                <div v-if="purchasesBySupplier.length === 0" class="text-muted py-4 text-center">Nenhum recebimento de compra no período.</div>
                <div v-else class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead><tr><th>Fornecedor</th><th class="text-end">Pedidos com recebimento</th><th class="text-end">Total gasto</th></tr></thead>
                        <tbody>
                            <tr v-for="(row, i) in purchasesBySupplier" :key="i">
                                <td>{{ row.supplier_name }}</td>
                                <td class="text-end">{{ row.orders_count }}</td>
                                <td class="text-end fw-semibold">{{ money(row.total_spent) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted d-block mt-2">
                    Valorizado pelo que REALMENTE entrou no estoque (recebimentos confirmados), não pelo total pedido — pedido cancelado/parcial nunca infla este número.
                </small>
            </div>

        </div>
    </AppLayout>
</template>
