<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';

const props = defineProps({
    conversionFunnel: { type: Object, required: true },
    t:                { type: Object, required: true },
});

const cf = computed(() => props.conversionFunnel);

// ── Seletor de período ───────────────────────────────────────────────────────
// Reload parcial via Inertia (only: ['conversionFunnel']) — os demais widgets
// do dashboard não recarregam. `days` reflete o período efetivamente
// devolvido pelo backend (fonte da verdade), não o clicado localmente, para
// não dessincronizar se o backend cair no default por período inválido.
const PERIOD_OPTIONS = [7, 30, 60, 90];
const loadingPeriod  = ref(false);
const selectedDays   = ref(cf.value.days ?? 90);

watch(() => cf.value.days, (days) => {
    if (days) selectedDays.value = days;
});

function periodLabel(days) {
    return (props.t.funnel_period_option ?? ':days dias').replace(':days', days);
}

function selectPeriod(days) {
    if (days === selectedDays.value || loadingPeriod.value) return;

    loadingPeriod.value = true;
    router.reload({
        only: ['conversionFunnel'],
        data: { funnel_period: days },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { loadingPeriod.value = false; },
    });
}

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

            <ActionDropdown
                :title="t.conversion_funnel_title"
                align="right"
                :min-width="140"
                btn-class="badge badge-soft-primary fw-medium border py-1 px-2 border-primary fs-13"
            >
                <template #trigger>
                    <span v-if="loadingPeriod" class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem;"></span>
                    {{ (t.funnel_period_label ?? 'Últimos :days dias').replace(':days', selectedDays) }}
                    <i class="ti ti-chevron-down ms-1"></i>
                </template>

                <li v-for="days in PERIOD_OPTIONS" :key="days">
                    <button
                        type="button"
                        class="dropdown-item rounded-1 d-flex align-items-center justify-content-between"
                        :class="{ active: days === selectedDays }"
                        @click="selectPeriod(days)"
                    >
                        {{ periodLabel(days) }}
                        <i v-if="days === selectedDays" class="ti ti-check ms-2"></i>
                    </button>
                </li>
            </ActionDropdown>
        </div>
        <div class="card-body" :class="{ 'opacity-50 pe-none': loadingPeriod }">

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

            <!-- Taxa do período selecionado -->
            <div class="conv-90d-box">
                <div class="conv-90d-title">
                    <i class="ti ti-calendar-stats me-1"></i>
                    {{ (t.conv_period_title ?? 'Conversão — trials finalizados (:days d)').replace(':days', cf.days) }}
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-4 text-center">
                        <div class="conv-90d-value">{{ cf.trialsEndedPeriod }}</div>
                        <div class="conv-90d-label">{{ t.conv_trials_ended }}</div>
                    </div>
                    <div class="col-4 text-center">
                        <div class="conv-90d-value text-success">{{ cf.trialsConvertedPeriod }}</div>
                        <div class="conv-90d-label">{{ t.conv_converted }}</div>
                    </div>
                    <div class="col-4 text-center">
                        <div :class="['conv-90d-value', trialRateClass(cf.trialToPaidPeriodRate)]">
                            {{ cf.trialToPaidPeriodRate }}%
                        </div>
                        <div class="conv-90d-label">{{ t.conv_rate }}</div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>
