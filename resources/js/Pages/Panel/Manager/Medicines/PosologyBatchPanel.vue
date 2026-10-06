<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useImportProgress } from '@/composables/useImportProgress';
import KpiCard from '@/Components/Panel/KpiCard.vue';
import { brl, etaSeconds, shortDuration, usd } from './posologyBatch.js';

/**
 * Lote "Gerar posologia com IA": barra de progresso em tempo real
 * (WebSocket/Reverb, sem polling — mesmo padrão e estilo da importação CMED)
 * e histórico dos lotes (quem, quando, filtros, IA, grupos, itens, falhas,
 * custo real).
 */
const props = defineProps({
    // Lote na fila/processando (progressPayload do servidor) ou null.
    running: { type: Object, default: null },
    batches: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

// A página acompanha o estado (botão do cabeçalho mostra o progresso).
const emit = defineEmits(['progress']);

const { number, money } = useLocaleFormat();
const page = usePage();
const locale = computed(() => String(page?.props?.locale ?? 'pt_BR').replace('_', '-'));

const progress = ref(props.running);
const isRunning = computed(() => !!progress.value && !progress.value.is_done);

const { realtimeConnected, resync } = useImportProgress(progress, {
    onDone: () => router.reload({ only: ['posologyBatches', 'medicines', 'runningPosologyBatch'] }),
    // Assinou/reconectou (ou "Atualizar status"): relê o estado uma vez.
    onResync: () => router.reload({ only: ['runningPosologyBatch', 'posologyBatches', 'medicines'] }),
});

watch(
    () => props.running,
    (value) => {
        if (value) {
            progress.value = value;
            return;
        }
        // Terminou antes de o WebSocket conectar: resultado final vem do histórico.
        if (isRunning.value) {
            const finished = props.batches.find((b) => b.id === progress.value.id);
            if (finished) progress.value = finished;
        }
    },
);

watch(progress, (value) => emit('progress', value), { immediate: true });

/** Mostra um lote vindo de fora (ex.: a prévia achou um lote já rodando). */
function show(batch) {
    if (batch) progress.value = batch;
}

defineExpose({ show });

// Parada há quanto tempo (sem polling): idle_seconds do servidor + relógio local.
const idleSeconds = ref(0);
let idleBase = { at: Date.now(), seconds: 0 };

watch(
    progress,
    (batch) => {
        idleBase = { at: Date.now(), seconds: batch?.idle_seconds ?? 0 };
        idleSeconds.value = idleBase.seconds;
    },
    { immediate: true },
);

const idleTimer = setInterval(() => {
    if (isRunning.value) idleSeconds.value = idleBase.seconds + Math.floor((Date.now() - idleBase.at) / 1000);
}, 5000);

onBeforeUnmount(() => clearInterval(idleTimer));

const queued = computed(() => isRunning.value && progress.value?.status === 'pending');
const stalled = computed(
    () =>
        isRunning.value &&
        progress.value?.stall_after_seconds != null &&
        idleSeconds.value >= progress.value.stall_after_seconds,
);

const cancelling = ref(false);
const cancelError = ref('');

function cancel() {
    if (!progress.value || cancelling.value) return;

    cancelling.value = true;
    cancelError.value = '';
    router.post(
        route('manager.medicines.posology-batches.cancel', progress.value.id),
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                cancelError.value = errors.batch ?? Object.values(errors)[0] ?? '';
            },
            onFinish: () => {
                cancelling.value = false;
            },
        },
    );
}

function alertClass(batch) {
    if (isRunning.value) return stalled.value ? 'alert-warning' : 'alert-info';
    if (batch.status === 'done') return batch.failed_groups > 0 ? 'alert-warning' : 'alert-success';

    return batch.status === 'cancelled' ? 'alert-secondary' : 'alert-danger';
}

function barClass(batch) {
    if (isRunning.value) return 'bg-info progress-bar-striped progress-bar-animated';
    if (batch.status === 'done') return 'bg-success';

    return batch.status === 'cancelled' ? 'bg-secondary' : 'bg-danger';
}

function groupsText(batch) {
    return (props.t.batch_progress_groups ?? '')
        .replace(':processed', number(batch.processed_groups ?? 0))
        .replace(':total', number(batch.total_groups ?? 0));
}

function remainingText(batch) {
    return (props.t.batch_remaining_notice ?? '').replace(':count', number(batch.remaining_groups ?? 0));
}

function costUsd(value) {
    return usd(value ?? 0, locale.value);
}

function costBrl(value) {
    return brl(value ?? 0, locale.value);
}

// Tempo restante: recalculado no mesmo relógio local de 5 s do "parado".
const nowMs = ref(Date.now());
const etaTimer = setInterval(() => {
    if (isRunning.value) nowMs.value = Date.now();
}, 5000);

onBeforeUnmount(() => clearInterval(etaTimer));

const etaText = computed(() => {
    const seconds = etaSeconds(progress.value, nowMs.value);

    return seconds === null
        ? props.t.batch_eta_calculating
        : (props.t.batch_eta_value ?? ':value').replace(':value', shortDuration(seconds, locale.value));
});

const costEstimateText = computed(() =>
    progress.value?.estimated_cost_usd == null
        ? ''
        : (props.t.batch_tile_cost_estimate ?? '')
              .replace(':usd', costUsd(progress.value.estimated_cost_usd))
              .replace(':brl', costBrl(progress.value.estimated_cost_brl)),
);

const rateText = computed(() => {
    if (progress.value?.usd_brl_rate == null) return '';
    const key = progress.value.usd_brl_is_fallback ? 'batch_rate_hint_fallback' : 'batch_rate_hint';

    return (props.t[key] ?? '').replace(':brl', costBrl(progress.value.usd_brl_rate));
});

// Cards do progresso (tom = cor do KpiCard tinted).
const tiles = computed(() => {
    const b = progress.value;
    if (!b) return [];

    return [
        {
            key: 'groups',
            icon: 'ti ti-stack-2',
            tone: 'primary',
            label: props.t.batch_tile_groups,
            value: `${number(b.processed_groups)} / ${number(b.total_groups)}`,
            subtitle: `${number(b.progress)}%`,
        },
        {
            key: 'updated',
            icon: 'ti ti-circle-check',
            tone: 'success',
            label: props.t.batch_result_updated,
            value: number(b.updated_count),
        },
        {
            key: 'skipped',
            icon: 'ti ti-player-skip-forward',
            tone: 'secondary',
            label: props.t.batch_result_skipped,
            value: number(b.skipped_count),
        },
        {
            key: 'failed',
            icon: 'ti ti-alert-triangle',
            tone: b.failed_groups > 0 ? 'danger' : 'secondary',
            label: props.t.batch_result_failed,
            value: number(b.failed_groups),
        },
        {
            key: 'calls',
            icon: 'ti ti-sparkles',
            tone: 'purple',
            label: props.t.batch_tile_calls,
            value: number(b.ai_calls),
        },
        {
            key: 'cost',
            icon: 'ti ti-coin',
            tone: 'warning',
            label: props.t.batch_tile_cost,
            value: costUsd(b.cost_usd),
            subtitle: `≈ ${costBrl(b.cost_brl)}`,
        },
        ...(isRunning.value
            ? [
                  {
                      key: 'eta',
                      icon: 'ti ti-clock-hour-4',
                      tone: 'info',
                      label: props.t.batch_tile_eta,
                      value: etaText.value,
                  },
              ]
            : []),
    ];
});
</script>

<template>
    <div>
        <div class="card">
            <div class="card-body">
                <h6 class="fw-semibold">{{ t.batch_modal_title }}</h6>
                <p class="text-muted small mb-3">{{ t.batch_scope_hint }}</p>

                <!-- Progresso em tempo real (WebSocket) -->
                <div
                    v-if="progress"
                    class="alert mb-0"
                    :class="alertClass(progress)"
                    role="status"
                    aria-live="polite"
                    data-test="batch-progress"
                >
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <strong
                            ><i class="ti ti-sparkles me-1" aria-hidden="true"></i>{{ progress.provider_label }}</strong
                        >
                        <span :class="`badge bg-${progress.status_color}`">{{ progress.status_label }}</span>
                        <span v-if="progress.phase_label" class="small text-muted">— {{ progress.phase_label }}</span>
                        <button
                            v-if="isRunning"
                            type="button"
                            class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1 ms-auto"
                            :disabled="cancelling"
                            data-test="batch-cancel"
                            @click="cancel"
                        >
                            <span v-if="cancelling" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                            <i v-else class="ti ti-circle-x" aria-hidden="true"></i>
                            <span>{{ t.batch_cancel }}</span>
                        </button>
                        <button
                            v-if="!isRunning"
                            type="button"
                            class="btn-close ms-auto"
                            :aria-label="t.close"
                            @click="progress = null"
                        ></button>
                    </div>
                    <div
                        class="progress mb-3"
                        style="height: 8px"
                        role="progressbar"
                        :aria-valuenow="progress.progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="t.batch_progress_label"
                    >
                        <div
                            class="progress-bar"
                            :class="barClass(progress)"
                            :style="`width: ${isRunning ? Math.max(progress.progress, 3) : 100}%`"
                        ></div>
                    </div>
                    <div v-if="queued" class="small mb-2">
                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                        >{{ t.batch_waiting_worker }}
                    </div>

                    <!-- Números ao vivo (cada evento do WebSocket atualiza) -->
                    <div class="batch-tiles" data-test="batch-tiles">
                        <KpiCard
                            v-for="tile in tiles"
                            :key="tile.key"
                            tinted
                            :tone="tile.tone"
                            :icon="tile.icon"
                            :label="tile.label"
                            :value="tile.value"
                            :subtitle="tile.subtitle"
                            :data-tile="tile.key"
                        >
                        </KpiCard>
                    </div>
                    <div class="d-flex flex-wrap gap-3 small text-muted mt-2">
                        <span v-if="costEstimateText" data-test="batch-estimate">
                            <i class="ti ti-calculator me-1" aria-hidden="true"></i>{{ costEstimateText }}
                        </span>
                        <span v-if="rateText" data-test="batch-rate">
                            <i class="ti ti-currency-dollar me-1" aria-hidden="true"></i>{{ rateText }}
                        </span>
                    </div>

                    <div v-if="progress.is_done && progress.remaining_groups > 0" class="small mt-2">
                        <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ remainingText(progress) }}
                    </div>
                    <div v-if="progress.error" class="small text-danger mt-2">{{ progress.error }}</div>
                    <div v-if="stalled" class="small mt-2" role="alert">
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.batch_stalled }}
                    </div>
                    <div v-if="cancelError" class="text-danger small mt-2">{{ cancelError }}</div>
                    <div
                        v-if="isRunning && !realtimeConnected"
                        class="d-flex flex-wrap align-items-center gap-2 mt-2 small text-muted"
                    >
                        <span
                            ><i class="ti ti-plug-connected-x me-1" aria-hidden="true"></i
                            >{{ page.props.t_ui?.realtime_offline }}</span
                        >
                        <button type="button" class="btn btn-sm btn-light" @click="resync">
                            <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ t.import_refresh_status }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-transparent">
                <div class="fw-semibold">{{ t.batch_history }}</div>
                <div class="small text-muted">{{ t.batch_history_hint }}</div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>{{ t.col_date }}</th>
                            <th>{{ t.col_status }}</th>
                            <th class="d-none d-md-table-cell">{{ t.batch_col_filters }}</th>
                            <th>{{ t.col_result }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!batches.length">
                            <td colspan="4" class="text-center text-muted py-3">{{ t.batch_empty }}</td>
                        </tr>
                        <tr v-for="b in batches" :key="b.id" data-test="batch-row">
                            <td class="text-nowrap">
                                {{ b.created_at }}
                                <div v-if="b.user" class="text-muted">{{ b.user }}</div>
                            </td>
                            <td>
                                <span class="badge" :class="`bg-${b.status_color}`">{{ b.status_label }}</span>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <div>
                                    <i class="ti ti-sparkles me-1 text-primary" aria-hidden="true"></i
                                    >{{ b.provider_label }}
                                </div>
                                <div class="text-muted">
                                    {{
                                        b.filters_summary?.length ? b.filters_summary.join(' · ') : t.batch_filters_none
                                    }}
                                </div>
                            </td>
                            <td>
                                <div>
                                    <span class="me-2"
                                        >{{ t.batch_result_updated }}:
                                        <strong>{{ number(b.updated_count) }}</strong></span
                                    >
                                    <span class="me-2"
                                        >{{ t.batch_result_skipped }}:
                                        <strong>{{ number(b.skipped_count) }}</strong></span
                                    >
                                    <span class="me-2" :class="{ 'text-danger': b.failed_groups > 0 }"
                                        >{{ t.batch_result_failed }}:
                                        <strong>{{ number(b.failed_groups) }}</strong></span
                                    >
                                </div>
                                <div class="text-muted">
                                    {{ groupsText(b) }} · {{ t.batch_result_calls }}: {{ number(b.ai_calls) }} ·
                                    {{ t.batch_result_cost }}: <strong>{{ costUsd(b.cost_usd) }}</strong> (≈
                                    {{ money(b.cost_brl) }})
                                    <template v-if="b.estimated_cost_usd != null">
                                        ·
                                        {{ (t.batch_estimated ?? '').replace(':value', costUsd(b.estimated_cost_usd)) }}
                                    </template>
                                </div>
                                <div v-if="b.remaining_groups > 0" class="text-muted">{{ remainingText(b) }}</div>
                                <div v-if="b.error" class="text-danger">{{ b.error }}</div>
                                <details v-if="b.failures?.length" class="mt-1">
                                    <summary class="text-danger">
                                        {{ t.batch_failures }} ({{ b.failures.length }})
                                    </summary>
                                    <ul class="mb-0 ps-3">
                                        <li v-for="(f, i) in b.failures" :key="i">
                                            <strong>{{ f.label }}</strong
                                            >: <span class="text-muted">{{ f.error }}</span>
                                        </li>
                                    </ul>
                                </details>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<style scoped>
.batch-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 0.75rem;
}
/* Celular: dentro do quadro do progresso duas colunas cortavam números e rótulos. */
@media (max-width: 575.98px) {
    .batch-tiles {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }
}
</style>
