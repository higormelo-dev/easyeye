<script setup>
/**
 * GAP fechado (revisão pós-Fase 4 do módulo de estoque): existia alerta
 * (Notice + StockAlertService, ver stock:check-alerts) mas nenhuma presença
 * no Dashboard — quem não abre o mural de recados nunca via nada. `alerts`
 * chega `null` do controller quando a clínica não usa o módulo OU não tem
 * nada crítico agora — o componente inteiro nem monta nesse caso (ver
 * Dashboard.vue: `v-if="section.key === 'stock' && alerts"`).
 */
const props = defineProps({
    alerts: { type: Object, required: true }, // { below_minimum_count, expiring_lots_count, list_url, products_url, expiring_url }
    t: { type: Object, default: () => ({}) },
});

/** Texto com plural (_one/_other) e :count. */
function countText(key, count) {
    const text = props.t[`${key}_${count === 1 ? 'one' : 'other'}`] ?? '';

    return text.replace(':count', String(count));
}
</script>

<template>
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>
                <i class="ti ti-building-warehouse me-2 text-primary" aria-hidden="true"></i>
                {{ t.stock_title }}
            </span>
            <!-- Lista completa: o alerta pode ser só de validade, e o filtro
                 "abaixo do mínimo" mostraria uma lista vazia. -->
            <a :href="alerts.list_url ?? alerts.products_url" class="btn btn-sm btn-outline-primary">
                {{ t.stock_see }} <i class="ti ti-arrow-right ms-1" aria-hidden="true"></i>
            </a>
        </div>

        <div class="card-body">
            <div class="row g-3">
                <div v-if="alerts.below_minimum_count > 0" class="col-md-6">
                    <a
                        :href="alerts.products_url"
                        class="d-flex align-items-center gap-3 p-2 rounded text-decoration-none text-reset border"
                    >
                        <span
                            class="avatar avatar-lg bg-warning-subtle rounded-circle d-flex align-items-center justify-content-center"
                        >
                            <i class="ti ti-alert-triangle text-warning fs-4"></i>
                        </span>
                        <div>
                            <div class="fw-semibold">
                                {{ countText('stock_below_minimum', alerts.below_minimum_count) }}
                            </div>
                            <div class="text-muted small">{{ t.stock_below_minimum_hint }}</div>
                        </div>
                    </a>
                </div>

                <div v-if="alerts.expiring_lots_count > 0" class="col-md-6">
                    <a
                        :href="alerts.expiring_url"
                        class="d-flex align-items-center gap-3 p-2 rounded text-decoration-none text-reset border"
                    >
                        <span
                            class="avatar avatar-lg bg-danger-subtle rounded-circle d-flex align-items-center justify-content-center"
                        >
                            <i class="ti ti-calendar-x text-danger fs-4"></i>
                        </span>
                        <div>
                            <div class="fw-semibold">{{ countText('stock_expiring', alerts.expiring_lots_count) }}</div>
                            <div class="text-muted small">{{ t.stock_expiring_hint }}</div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>
