<script setup>
import { computed } from 'vue';

const props = defineProps({
    financialKpis: { type: Object, required: true },
    t:             { type: Object, required: true },
});

const fk = computed(() => props.financialKpis);

const churnVariant = computed(() =>
    fk.value.churnRate > 5 ? 'churn-high' : 'churn',
);

const riskVariant = computed(() =>
    fk.value.revenueAtRisk > 0 ? 'risk-high' : 'risk',
);

function brl(value) {
    return Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}
</script>

<template>
    <div class="row g-3 mb-3">

        <!-- ARR -->
        <div class="col-6 col-md-3">
            <div class="card stat-card stat-card--arr h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="stat-icon stat-icon--arr">
                        <i class="ti ti-chart-line"></i>
                    </div>
                    <div>
                        <div class="stat-value">R$ {{ brl(fk.arr) }}</div>
                        <div class="stat-label">{{ t.kpi_arr }}</div>
                        <div class="d-flex gap-1 mt-1">
                            <span class="period-badge">
                                MRR <span class="period-value">R$ {{ brl(fk.mrr) }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ARPU -->
        <div class="col-6 col-md-3">
            <div class="card stat-card stat-card--arpu h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="stat-icon stat-icon--arpu">
                        <i class="ti ti-user-dollar"></i>
                    </div>
                    <div>
                        <div class="stat-value">R$ {{ brl(fk.arpu) }}</div>
                        <div class="stat-label">{{ t.kpi_arpu }}</div>
                        <div class="d-flex gap-1 mt-1">
                            <span class="period-badge">
                                <span class="period-value">{{ fk.activeCount }}</span> {{ t.active_subs }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Churn Rate -->
        <div class="col-6 col-md-3">
            <div :class="['card stat-card h-100', `stat-card--${churnVariant}`]">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div :class="['stat-icon', `stat-icon--${churnVariant}`]">
                        <i class="ti ti-trending-down"></i>
                    </div>
                    <div>
                        <div class="stat-value">
                            {{ Number(fk.churnRate).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) }}%
                        </div>
                        <div class="stat-label">{{ t.kpi_churn_rate }}</div>
                        <div class="d-flex gap-1 mt-1">
                            <span class="period-badge">
                                <span class="period-value">{{ fk.cancelledThisMonth }}</span> {{ t.cancelled_this_month }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Receita em Risco -->
        <div class="col-6 col-md-3">
            <div :class="['card stat-card h-100', `stat-card--${riskVariant}`]">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div :class="['stat-icon', `stat-icon--${riskVariant}`]">
                        <i class="ti ti-alert-circle"></i>
                    </div>
                    <div>
                        <div class="stat-value">R$ {{ brl(fk.revenueAtRisk) }}</div>
                        <div class="stat-label">{{ t.kpi_revenue_at_risk }}</div>
                        <span v-if="fk.revenueAtRisk > 0"
                              class="badge badge-soft-danger rounded text-danger border border-danger fs-11 fw-medium mt-1">
                            <i class="ti ti-alert-triangle me-1"></i>{{ t.past_due ?? 'Em atraso' }}
                        </span>
                        <span v-else
                              class="badge badge-soft-success rounded text-success border border-success fs-11 fw-medium mt-1">
                            <i class="ti ti-circle-check me-1"></i>{{ t.all_clear }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
