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
    <section class="card db-card stock-alerts" :aria-label="t.stock_title">
        <div class="db-card-header">
            <h3 class="db-card-title">
                <i class="ti ti-building-warehouse" aria-hidden="true"></i>
                {{ t.stock_title }}
            </h3>
            <!-- Lista completa: o alerta pode ser só de validade, e o filtro
                 "abaixo do mínimo" mostraria uma lista vazia. -->
            <a :href="alerts.list_url ?? alerts.products_url" class="db-link">
                {{ t.stock_see }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <ul class="db-list">
            <li v-if="alerts.below_minimum_count > 0" class="db-list__item">
                <span class="stock-alerts__icon stock-alerts__icon--warning" aria-hidden="true">
                    <i class="ti ti-alert-triangle"></i>
                </span>
                <div class="db-list__main">
                    <a :href="alerts.products_url" class="db-list__title">
                        {{ countText('stock_below_minimum', alerts.below_minimum_count) }}
                    </a>
                    <span class="db-list__sub">{{ t.stock_below_minimum_hint }}</span>
                </div>
            </li>

            <li v-if="alerts.expiring_lots_count > 0" class="db-list__item">
                <span class="stock-alerts__icon stock-alerts__icon--danger" aria-hidden="true">
                    <i class="ti ti-calendar-x"></i>
                </span>
                <div class="db-list__main">
                    <a :href="alerts.expiring_url" class="db-list__title">
                        {{ countText('stock_expiring', alerts.expiring_lots_count) }}
                    </a>
                    <span class="db-list__sub">{{ t.stock_expiring_hint }}</span>
                </div>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.stock-alerts__icon {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    font-size: 1.1rem;
}

.stock-alerts__icon--warning {
    background: rgba(var(--warning-rgb), 0.16);
    color: #b45309;
}

.stock-alerts__icon--danger {
    background: rgba(var(--danger-rgb), 0.12);
    color: #b91c1c;
}

[data-bs-theme='dark'] .stock-alerts__icon--warning {
    color: #fcd34d;
}

[data-bs-theme='dark'] .stock-alerts__icon--danger {
    color: #fca5a5;
}
</style>
