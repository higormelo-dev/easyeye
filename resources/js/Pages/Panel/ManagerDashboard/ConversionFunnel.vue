<script setup>
import { computed } from 'vue';

const props = defineProps({
    conversionFunnel: { type: Object, required: true },
    t:                { type: Object, required: true },
});

const cf = computed(() => props.conversionFunnel);

function rateClass(rate, thresholds) {
    if (rate >= thresholds[0]) return 'conv-rate-badge--good';
    if (rate >= thresholds[1]) return 'conv-rate-badge--mid';
    return 'conv-rate-badge--low';
}

function trialRateClass(rate) {
    if (rate >= 30) return 'text-success';
    if (rate >= 15) return 'text-warning';
    return 'text-danger';
}

const trialBarWidth = computed(() => {
    if (!cf.value.totalLeads) return 4;
    return Math.max(4, Math.min(100, Math.round(cf.value.totalTrials / cf.value.totalLeads * 100)));
});

const activeBarWidth = computed(() => {
    if (!cf.value.totalTrials) return 4;
    return Math.max(4, Math.min(100, Math.round(cf.value.totalActive / cf.value.totalTrials * 100)));
});
</script>

<template>
    <div class="card mgr-chart-card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="ti ti-filter me-2"></i>{{ t.conversion_funnel_title }}</span>
            <span class="badge badge-soft-primary fw-medium border py-1 px-2 border-primary fs-13">
                {{ t.last_90d }}
            </span>
        </div>
        <div class="card-body">

            <!-- Etapas do funil -->
            <div class="conv-funnel mb-4">

                <!-- Leads -->
                <div class="conv-step">
                    <div class="conv-step-header">
                        <div class="conv-step-icon conv-step-icon--leads">
                            <i class="ti ti-target-arrow"></i>
                        </div>
                        <div>
                            <div class="conv-step-value">{{ cf.totalLeads?.toLocaleString('pt-BR') }}</div>
                            <div class="conv-step-label">{{ t.funnel_leads }}</div>
                        </div>
                        <div class="ms-auto text-end">
                            <div class="conv-rate-badge conv-rate-badge--neutral">100%</div>
                            <div class="conv-rate-label">{{ t.funnel_base }}</div>
                        </div>
                    </div>
                    <div class="conv-bar-wrapper">
                        <div class="conv-bar conv-bar--leads" style="width:100%;"></div>
                    </div>
                </div>

                <!-- Seta Lead → Trial -->
                <div class="conv-arrow">
                    <i class="ti ti-arrow-down"></i>
                    <span class="conv-arrow-rate">{{ cf.leadToTrialRate }}% {{ t.funnel_converted }}</span>
                </div>

                <!-- Trials -->
                <div class="conv-step">
                    <div class="conv-step-header">
                        <div class="conv-step-icon conv-step-icon--trials">
                            <i class="ti ti-clock-hour-4"></i>
                        </div>
                        <div>
                            <div class="conv-step-value">{{ cf.totalTrials?.toLocaleString('pt-BR') }}</div>
                            <div class="conv-step-label">{{ t.funnel_trials }}</div>
                        </div>
                        <div class="ms-auto text-end">
                            <div :class="['conv-rate-badge', rateClass(cf.leadToTrialRate, [30, 10])]">
                                {{ cf.leadToTrialRate }}%
                            </div>
                            <div class="conv-rate-label">{{ t.funnel_from_leads }}</div>
                        </div>
                    </div>
                    <div class="conv-bar-wrapper">
                        <div class="conv-bar conv-bar--trials" :style="`width:${trialBarWidth}%;`"></div>
                    </div>
                </div>

                <!-- Seta Trial → Pago -->
                <div class="conv-arrow">
                    <i class="ti ti-arrow-down"></i>
                    <span class="conv-arrow-rate">{{ cf.trialToActiveRate }}% {{ t.funnel_converted }}</span>
                </div>

                <!-- Ativos -->
                <div class="conv-step">
                    <div class="conv-step-header">
                        <div class="conv-step-icon conv-step-icon--active">
                            <i class="ti ti-circle-check"></i>
                        </div>
                        <div>
                            <div class="conv-step-value">{{ cf.totalActive?.toLocaleString('pt-BR') }}</div>
                            <div class="conv-step-label">{{ t.funnel_active }}</div>
                        </div>
                        <div class="ms-auto text-end">
                            <div :class="['conv-rate-badge', rateClass(cf.trialToActiveRate, [40, 20])]">
                                {{ cf.trialToActiveRate }}%
                            </div>
                            <div class="conv-rate-label">{{ t.funnel_from_trials }}</div>
                        </div>
                    </div>
                    <div class="conv-bar-wrapper">
                        <div class="conv-bar conv-bar--active" :style="`width:${activeBarWidth}%;`"></div>
                    </div>
                </div>
            </div>

            <!-- Taxa dos últimos 90 dias -->
            <div class="conv-90d-box">
                <div class="conv-90d-title">
                    <i class="ti ti-calendar-stats me-1"></i>
                    {{ t.conv_90d_title }}
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-4 text-center">
                        <div class="conv-90d-value">{{ cf.trialsEnded90d }}</div>
                        <div class="conv-90d-label">{{ t.conv_trials_ended }}</div>
                    </div>
                    <div class="col-4 text-center">
                        <div class="conv-90d-value text-success">{{ cf.trialsConverted90d }}</div>
                        <div class="conv-90d-label">{{ t.conv_converted }}</div>
                    </div>
                    <div class="col-4 text-center">
                        <div :class="['conv-90d-value', trialRateClass(cf.trialToPaid90dRate)]">
                            {{ cf.trialToPaid90dRate }}%
                        </div>
                        <div class="conv-90d-label">{{ t.conv_rate }}</div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>
