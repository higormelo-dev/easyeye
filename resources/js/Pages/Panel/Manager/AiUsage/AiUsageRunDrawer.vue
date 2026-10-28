<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans.js';
import { runStatusClass } from './runStatus.js';

/**
 * Detalhe de uma execução de IA (Manager → Uso de IA): metadados, créditos,
 * custo e as chamadas aos provedores (papel, modelo, tokens, latência,
 * custo, erro saneado). Nunca prompt/resposta — o endpoint nem os devolve.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    runId: { type: String, default: null },
    /** Textos de manager_ai_usage (usa drawer.*, internal). */
    t: { type: Object, default: () => ({}) },
});

defineEmits(['close']);

const { tx } = useTrans(() => props.t.drawer ?? {});
const { locale, money, number, dateTime } = useLocaleFormat();

const run = ref(null);
const loading = ref(false);
const error = ref(false);
let request = 0;

async function load(id) {
    const current = ++request;
    loading.value = true;
    error.value = false;
    run.value = null;

    try {
        const { data } = await axios.get(route('manager.ai-usage.runs.show', id));
        if (current === request) run.value = data.data;
    } catch {
        if (current === request) error.value = true;
    } finally {
        if (current === request) loading.value = false;
    }
}

watch(
    () => [props.open, props.runId],
    ([open, id]) => {
        if (open && id) load(id);
        if (!open) request++;
    },
    { immediate: true },
);

function usd(value) {
    if (value === null || value === undefined) return '—';

    return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 6,
    }).format(Number(value));
}

function latency(ms) {
    if (ms === null || ms === undefined) return '—';

    return new Intl.NumberFormat(locale.value, { style: 'unit', unit: 'second', maximumFractionDigits: 1 }).format(
        Number(ms) / 1000,
    );
}

const overview = computed(() => {
    if (!run.value) return [];
    const r = run.value;

    return [
        [props.t.columns?.entity, r.entity_name],
        [props.t.columns?.user, r.user_name],
        [tx('created_at'), dateTime(r.created_at)],
        [tx('updated_at'), dateTime(r.updated_at)],
        [tx('mode'), r.mode_label],
        [tx('approver'), r.approver_name],
        [tx('approved_at'), r.approved_at ? dateTime(r.approved_at) : null],
        [tx('cancelled_at'), r.cancelled_at ? dateTime(r.cancelled_at) : null],
        [tx('credits_estimated'), number(r.estimated_credits)],
        [tx('credits_reserved'), number(r.reserved_credits)],
        [tx('credits_consumed'), number(r.consumed_credits)],
        [tx('cost'), `${money(r.cost_brl)} (${usd(r.cost_usd)})`],
    ].filter(([, value]) => value !== null && value !== undefined && value !== '');
});
</script>

<template>
    <OffcanvasPanel :open="open" :width="560" :loading="loading" :close-label="tx('close')" @close="$emit('close')">
        <template #header>
            <div class="min-w-0">
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-sparkles me-2 text-info" aria-hidden="true"></i
                    >{{ run?.workflow_label ?? tx('title') }}
                </h5>
                <div v-if="run" class="mt-1 d-flex gap-2 align-items-center flex-wrap">
                    <span class="badge" :class="runStatusClass(run.status)">{{ run.status_label }}</span>
                    <span v-if="run.is_internal" class="badge badge-soft-info rounded fs-12">{{ t.internal }}</span>
                    <span v-if="run.is_escalation" class="badge badge-soft-secondary rounded fs-12">{{
                        tx('escalation')
                    }}</span>
                </div>
            </div>
        </template>

        <div v-if="error" class="alert alert-danger small mb-0" role="alert">{{ tx('load_error') }}</div>

        <template v-else-if="run">
            <div v-if="run.error" class="alert alert-danger small py-2" role="alert">
                <strong>{{ tx('error') }}:</strong> {{ run.error }}
            </div>

            <section class="au-section">
                <h3 class="au-section__title"><i class="ti ti-info-circle me-1"></i>{{ tx('overview') }}</h3>
                <dl class="au-grid mb-0">
                    <template v-for="[label, value] in overview" :key="label">
                        <dt>{{ label }}</dt>
                        <dd>{{ value }}</dd>
                    </template>
                </dl>
            </section>

            <section class="au-section">
                <h3 class="au-section__title"><i class="ti ti-plug-connected me-1"></i>{{ tx('calls') }}</h3>
                <p v-if="!run.calls.length" class="text-muted small mb-0">{{ tx('no_calls') }}</p>
                <ol v-else class="list-unstyled mb-0 d-grid gap-2">
                    <li v-for="(call, index) in run.calls" :key="index" class="au-call" data-test="run-call">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="fw-semibold">{{ call.role_label }}</span>
                            <span class="text-muted small">{{ call.provider_label }} · {{ call.model }}</span>
                            <span
                                class="badge ms-auto"
                                :class="
                                    call.status === 'success'
                                        ? 'badge-soft-success'
                                        : call.status === 'failed'
                                          ? 'badge-soft-danger'
                                          : 'badge-soft-secondary'
                                "
                                >{{ call.status_label }}</span
                            >
                        </div>
                        <div class="small text-muted mt-1 d-flex flex-wrap gap-3">
                            <span
                                >{{ tx('tokens') }}:
                                {{ tx('tokens_value', { in: number(call.tokens_in), out: number(call.tokens_out) })
                                }}<template v-if="call.tokens_reasoning">
                                    · {{ tx('tokens_reasoning', { value: number(call.tokens_reasoning) }) }}</template
                                ></span
                            >
                            <span>{{ tx('latency') }}: {{ latency(call.latency_ms) }}</span>
                            <span>{{ tx('cost') }}: {{ money(call.cost_brl) }} ({{ usd(call.cost_usd) }})</span>
                        </div>
                        <div v-if="call.error" class="small text-danger mt-1">{{ call.error }}</div>
                    </li>
                </ol>
            </section>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.au-section {
    margin-bottom: 1.5rem;
}
.au-section__title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.au-grid {
    display: grid;
    grid-template-columns: 170px 1fr;
    gap: 0.375rem 0.75rem;
    font-size: 0.875rem;
}
.au-grid dt {
    font-weight: 600;
    color: var(--bs-body-color);
}
.au-grid dd {
    margin: 0;
    color: var(--bs-secondary-color);
    word-break: break-word;
}
.au-call {
    border: 1px solid var(--bs-border-color);
    border-radius: 0.5rem;
    padding: 0.625rem 0.75rem;
}
@media (max-width: 575.98px) {
    .au-grid {
        grid-template-columns: 1fr;
        gap: 0;
    }
    .au-grid dd {
        margin-bottom: 0.375rem;
    }
}
</style>
