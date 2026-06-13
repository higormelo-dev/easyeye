<script setup>
import { ref, computed } from 'vue';

/**
 * Painel de CUSTOS REAIS do EasyEye nos provedores (lado supplier).
 *
 * Diferente do consumo de créditos do cliente (que é abstração).
 * Aqui mostra: quanto a empresa SaaS gastou em USD com OpenAI/Claude/Gemini
 * no mês corrente, últimos 7 dias, ontem + forecast mensal + margem bruta.
 *
 * Fonte: ai_run_provider_calls.raw_cost_usd (calculado pelo AiPricingService).
 */
const props = defineProps({
    costs:       { type: Object, default: () => null },
    permissions: { type: Object, default: () => ({}) },
    t:           { type: Object, default: () => ({}) },
});

const emit = defineEmits(['add-topup']);

const PROVIDER_META = {
    openai:    { label: 'ChatGPT', icon: 'ti ti-brand-openai',    color: '#10a37f', billingUrl: 'https://platform.openai.com/settings/organization/limits' },
    anthropic: { label: 'Claude',  icon: 'ti ti-message-chatbot', color: '#cc785c', billingUrl: 'https://console.anthropic.com/settings/limits' },
    gemini:    { label: 'Gemini',  icon: 'ti ti-brand-google',    color: '#4285f4', billingUrl: 'https://console.cloud.google.com/billing/budgets' },
};

const PROVIDER_ORDER = ['openai', 'anthropic', 'gemini'];

const showChecklist = ref(false);

function fmtUsd(value) {
    return '$' + Number(value ?? 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: value < 1 ? 4 : 2,
    });
}

function fmtNum(n) {
    return Number(n ?? 0).toLocaleString('pt-BR');
}

const summary = computed(() => props.costs?.summary ?? {});
const byProvider = computed(() => props.costs?.by_provider ?? {});
const margin = computed(() => props.costs?.margin ?? {});

const marginVariant = computed(() => {
    const pct = margin.value?.gross_margin_pct ?? 0;
    if (pct >= 40) return 'success';
    if (pct >= 20) return 'warning';
    return 'danger';
});

function forecastVariant(forecast, mtd) {
    if (!mtd || mtd <= 0) return 'secondary';
    const ratio = forecast / mtd;
    if (ratio > 5) return 'danger';   // explodiu nos últimos 7d
    if (ratio > 2) return 'warning';
    return 'info';
}

const ALERT_META = {
    ok:        { color: 'success', icon: 'ti ti-circle-check',   label: 'OK' },
    warning:   { color: 'warning', icon: 'ti ti-alert-triangle', label: 'Atenção' },
    critical:  { color: 'danger',  icon: 'ti ti-flame',          label: 'Crítico' },
    exhausted: { color: 'danger',  icon: 'ti ti-x',              label: 'Esgotado' },
    unknown:   { color: 'secondary', icon: 'ti ti-question-mark', label: 'Sem topup' },
};

function alertMeta(level) {
    return ALERT_META[level] ?? ALERT_META.unknown;
}

function fmtDateOnly(iso) {
    if (!iso) return '—';
    try { return new Date(iso).toLocaleDateString('pt-BR'); } catch { return '—'; }
}
</script>

<template>
    <div v-if="costs" class="card mb-3 provider-costs-card">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <div>
                <i class="ti ti-receipt-tax text-info me-2"></i>
                <strong class="small">{{ t?.provider_costs?.title ?? 'Custo do EasyEye nos provedores (lado supplier)' }}</strong>
                <small class="text-muted ms-2">{{ t?.provider_costs?.subtitle ?? 'Quanto vocês gastam em USD nas APIs' }}</small>
            </div>
            <button
                type="button"
                class="btn btn-sm btn-link text-decoration-none"
                @click="showChecklist = !showChecklist"
            >
                <i class="ti ti-shield-check me-1"></i>
                {{ t?.provider_costs?.alerts_setup ?? 'Alertas externos' }}
            </button>
        </div>

        <div class="card-body p-3">

            <!-- ─── Resumo agregado ─────────────────────────────────────── -->
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                    <div class="cost-tile">
                        <div class="text-muted small">{{ t?.provider_costs?.mtd ?? 'Mês até hoje' }}</div>
                        <h5 class="mb-0 fw-bold">{{ fmtUsd(summary.month_to_date_usd) }}</h5>
                        <small class="text-muted">{{ fmtNum(summary.total_calls_mtd) }} chamadas</small>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="cost-tile">
                        <div class="text-muted small">{{ t?.provider_costs?.last_7d ?? 'Últimos 7 dias' }}</div>
                        <h5 class="mb-0 fw-bold">{{ fmtUsd(summary.last_7d_usd) }}</h5>
                        <small class="text-muted">~{{ fmtUsd((summary.last_7d_usd ?? 0) / 7) }}/dia</small>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="cost-tile">
                        <div class="text-muted small">{{ t?.provider_costs?.yesterday ?? 'Ontem' }}</div>
                        <h5 class="mb-0 fw-bold">{{ fmtUsd(summary.yesterday_usd) }}</h5>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="cost-tile cost-tile--forecast">
                        <div class="text-muted small">
                            <i class="ti ti-chart-arrows me-1"></i>
                            {{ t?.provider_costs?.forecast ?? 'Forecast mensal' }}
                        </div>
                        <h5 class="mb-0 fw-bold text-info">{{ fmtUsd(summary.month_forecast_usd) }}</h5>
                        <small class="text-muted">{{ t?.provider_costs?.forecast_help ?? 'extrapolação 7d' }}</small>
                    </div>
                </div>
            </div>

            <!-- ─── Por provedor ───────────────────────────────────────── -->
            <div class="row g-2 mb-3">
                <div
                    v-for="key in PROVIDER_ORDER"
                    :key="key"
                    class="col-12 col-md-4"
                >
                    <div
                        class="provider-cost-tile p-3 rounded h-100"
                        :class="byProvider[key]?.estimated_balance?.alert_level === 'critical' || byProvider[key]?.estimated_balance?.alert_level === 'exhausted' ? 'provider-cost-tile--danger' : ''"
                        :style="{ borderLeft: `4px solid ${PROVIDER_META[key].color}` }"
                    >
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="fw-semibold" :style="{ color: PROVIDER_META[key].color }">
                                <i :class="PROVIDER_META[key].icon" class="me-1"></i>
                                {{ PROVIDER_META[key].label }}
                            </div>
                            <div class="d-flex align-items-center gap-1">
                                <button
                                    v-if="permissions?.create_topup"
                                    type="button"
                                    class="btn btn-sm btn-outline-info py-0 px-2"
                                    :title="t?.topup?.add ?? 'Registrar recarga'"
                                    @click="emit('add-topup', key)"
                                >
                                    <i class="ti ti-plus" style="font-size: .85rem;"></i>
                                </button>
                                <a
                                    :href="PROVIDER_META[key].billingUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-muted small text-decoration-none"
                                    title="Abrir painel de billing externo"
                                >
                                    <i class="ti ti-external-link"></i>
                                </a>
                            </div>
                        </div>

                        <!-- ─── Saldo estimado (destaque) ─────────────────── -->
                        <div
                            v-if="byProvider[key]?.estimated_balance?.has_topups"
                            class="estimated-balance mb-2 p-2 rounded"
                            :class="`estimated-balance--${alertMeta(byProvider[key].estimated_balance.alert_level).color}`"
                        >
                            <div class="d-flex justify-content-between align-items-baseline">
                                <div>
                                    <div class="text-muted small" style="font-size: .65rem; text-transform: uppercase;">
                                        {{ t?.provider_costs?.estimated_balance ?? 'Saldo estimado' }}
                                    </div>
                                    <strong
                                        class="fs-5"
                                        :class="`text-${alertMeta(byProvider[key].estimated_balance.alert_level).color}`"
                                    >
                                        {{ fmtUsd(byProvider[key].estimated_balance.remaining_usd) }}
                                    </strong>
                                </div>
                                <div class="text-end">
                                    <i :class="alertMeta(byProvider[key].estimated_balance.alert_level).icon"
                                       :style="{ color: `var(--bs-${alertMeta(byProvider[key].estimated_balance.alert_level).color})` }"></i>
                                    <div
                                        v-if="byProvider[key].estimated_balance.days_remaining !== null"
                                        class="small"
                                        :class="`text-${alertMeta(byProvider[key].estimated_balance.alert_level).color}`"
                                    >
                                        ~{{ byProvider[key].estimated_balance.days_remaining }} {{ t?.provider_costs?.days_left ?? 'dias' }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-else
                            class="estimated-balance estimated-balance--secondary mb-2 p-2 rounded"
                        >
                            <div class="text-muted small text-center">
                                <i class="ti ti-info-circle me-1"></i>
                                {{ t?.provider_costs?.no_topups ?? 'Registre uma recarga para ver o saldo estimado' }}
                            </div>
                        </div>

                        <div class="row g-1">
                            <div class="col-6">
                                <div class="text-muted small" style="font-size: .7rem;">{{ t?.provider_costs?.mtd ?? 'Mês' }}</div>
                                <strong>{{ fmtUsd(byProvider[key]?.month_to_date_usd) }}</strong>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small" style="font-size: .7rem;">{{ t?.provider_costs?.forecast ?? 'Forecast' }}</div>
                                <span
                                    class="fw-semibold"
                                    :class="`text-${forecastVariant(byProvider[key]?.month_forecast_usd, byProvider[key]?.month_to_date_usd)}`"
                                >
                                    {{ fmtUsd(byProvider[key]?.month_forecast_usd) }}
                                </span>
                            </div>
                        </div>

                        <hr class="my-2">

                        <div class="small text-muted">
                            <div class="d-flex justify-content-between">
                                <span>{{ fmtNum(byProvider[key]?.calls_mtd) }} chamadas</span>
                                <span>{{ fmtUsd(byProvider[key]?.avg_cost_per_call_usd) }}/chamada</span>
                            </div>
                            <div v-if="byProvider[key]?.models_breakdown?.length" class="mt-2">
                                <div style="font-size: .65rem; text-transform: uppercase; letter-spacing: 0.04em;" class="mb-1">
                                    Top modelos
                                </div>
                                <div
                                    v-for="m in byProvider[key].models_breakdown.slice(0, 3)"
                                    :key="m.model"
                                    class="d-flex justify-content-between"
                                    style="font-size: .7rem;"
                                >
                                    <span class="text-truncate" style="max-width: 60%;" :title="m.model">{{ m.model }}</span>
                                    <span>{{ fmtUsd(m.cost_usd) }}</span>
                                </div>
                            </div>
                            <div
                                v-if="byProvider[key]?.recent_topups?.length"
                                class="mt-2 pt-2 border-top"
                            >
                                <div style="font-size: .65rem; text-transform: uppercase; letter-spacing: 0.04em;" class="mb-1">
                                    {{ t?.provider_costs?.recent_topups ?? 'Últimas recargas' }}
                                </div>
                                <div
                                    v-for="topup in byProvider[key].recent_topups.slice(0, 3)"
                                    :key="topup.id"
                                    class="d-flex justify-content-between"
                                    style="font-size: .7rem;"
                                >
                                    <span>{{ fmtDateOnly(topup.topped_up_at) }}</span>
                                    <span class="text-end">
                                        <strong class="text-success">+{{ fmtUsd(topup.amount_usd) }}</strong>
                                        <span v-if="topup.amount_brl" class="text-muted ms-1">
                                            (R$ {{ topup.amount_brl.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }})
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Margem bruta ──────────────────────────────────────── -->
            <div class="margin-strip p-3 rounded" :class="`margin-strip--${marginVariant}`">
                <div class="row align-items-center g-2">
                    <div class="col-12 col-md-3">
                        <div class="text-muted small">{{ t?.provider_costs?.margin?.title ?? 'Margem bruta (mês)' }}</div>
                        <h4 class="mb-0 fw-bold" :class="`text-${marginVariant}`">
                            {{ margin.gross_margin_pct ?? 0 }}%
                        </h4>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small">
                            <i class="ti ti-cash me-1"></i>
                            {{ t?.provider_costs?.margin?.revenue ?? 'Receita estimada' }}
                        </div>
                        <strong class="text-success">{{ fmtUsd(margin.revenue_estimate_usd) }}</strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small">
                            <i class="ti ti-arrow-down-right me-1"></i>
                            {{ t?.provider_costs?.margin?.cost ?? 'Custo provedores' }}
                        </div>
                        <strong class="text-danger">{{ fmtUsd(margin.cost_mtd_usd) }}</strong>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="text-muted small">
                            {{ t?.provider_costs?.margin?.gross ?? 'Lucro bruto' }}
                        </div>
                        <strong :class="`text-${marginVariant}`">{{ fmtUsd(margin.gross_margin_usd) }}</strong>
                        <small class="text-muted d-block" style="font-size: .65rem;">
                            multiplicador config: {{ margin.margin_multiplier }}×
                        </small>
                    </div>
                </div>
            </div>

            <!-- ─── Checklist de billing externo (colapsável) ────────── -->
            <div v-if="showChecklist" class="alert alert-info mt-3 small mb-0">
                <div class="d-flex align-items-start gap-2 mb-2">
                    <i class="ti ti-info-circle mt-1"></i>
                    <div>
                        <strong>{{ t?.provider_costs?.checklist?.title ?? 'Configure alertas no painel dos provedores' }}</strong>
                        <p class="mb-2 small text-muted">
                            {{ t?.provider_costs?.checklist?.subtitle ?? 'O EasyEye não consegue recarregar saldo automaticamente — você precisa configurar limites/alertas em cada provedor.' }}
                        </p>
                    </div>
                </div>
                <ul class="mb-0" style="list-style: none; padding-left: 0;">
                    <li
                        v-for="key in PROVIDER_ORDER"
                        :key="key"
                        class="mb-2"
                    >
                        <i :class="PROVIDER_META[key].icon" class="me-1" :style="{ color: PROVIDER_META[key].color }"></i>
                        <strong>{{ PROVIDER_META[key].label }}:</strong>
                        configure limite mensal em
                        <a :href="PROVIDER_META[key].billingUrl" target="_blank" rel="noopener" class="text-decoration-underline">
                            {{ PROVIDER_META[key].billingUrl.replace('https://', '') }}
                        </a>
                    </li>
                </ul>
            </div>

        </div>
    </div>
</template>

<style scoped>
.provider-costs-card {
    border: 1px solid #e2e8f0;
}
.cost-tile {
    padding: .625rem .875rem;
    background: #f8fafc;
    border-radius: .5rem;
}
.cost-tile--forecast {
    background: linear-gradient(135deg, rgba(13, 202, 240, 0.06), rgba(13, 202, 240, 0.01));
    border: 1px solid rgba(13, 202, 240, 0.2);
}
.provider-cost-tile {
    background: #fff;
    border: 1px solid #e2e8f0;
    transition: transform .15s ease;
}
.provider-cost-tile:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.provider-cost-tile--danger {
    animation: pulse-danger 2s ease-in-out infinite;
}
@keyframes pulse-danger {
    0%, 100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
    50%      { box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.15); }
}

.estimated-balance {
    border: 1px solid #e2e8f0;
    background: #f8fafc;
}
.estimated-balance--success { background: rgba(25, 135, 84, 0.06);  border-color: rgba(25, 135, 84, 0.2); }
.estimated-balance--warning { background: rgba(255, 193, 7, 0.06);  border-color: rgba(255, 193, 7, 0.25); }
.estimated-balance--danger  { background: rgba(220, 53, 69, 0.06);  border-color: rgba(220, 53, 69, 0.25); }
.estimated-balance--secondary { background: #f8fafc; border-color: #e2e8f0; }
.margin-strip {
    border: 1px solid #e2e8f0;
}
.margin-strip--success { background: rgba(25, 135, 84, 0.04);  border-color: rgba(25, 135, 84, 0.2); }
.margin-strip--warning { background: rgba(255, 193, 7, 0.05);  border-color: rgba(255, 193, 7, 0.25); }
.margin-strip--danger  { background: rgba(220, 53, 69, 0.05);  border-color: rgba(220, 53, 69, 0.25); }
</style>
