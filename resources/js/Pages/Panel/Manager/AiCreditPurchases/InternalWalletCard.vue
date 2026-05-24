<script setup>
import { computed } from 'vue';

const props = defineProps({
    wallet:      { type: Object, default: () => null },
    permissions: { type: Object, default: () => ({}) },
    t:           { type: Object, default: () => ({}) },
});

const emit = defineEmits(['add-credit']);

const PROVIDER_META = {
    openai:    { label: 'ChatGPT', icon: 'ti ti-brand-openai',    color: '#10a37f', bg: 'rgba(16,163,127,0.10)' },
    anthropic: { label: 'Claude',  icon: 'ti ti-message-chatbot', color: '#cc785c', bg: 'rgba(204,120,92,0.10)' },
    gemini:    { label: 'Gemini',  icon: 'ti ti-brand-google',    color: '#4285f4', bg: 'rgba(66,133,244,0.10)' },
};

const PROVIDER_ORDER = ['openai', 'anthropic', 'gemini'];

const balance = computed(() => props.wallet?.balance ?? null);

const consumedByProvider = computed(() => balance.value?.consumed_by_provider ?? {});
const totalConsumed      = computed(() =>
    PROVIDER_ORDER.reduce((sum, p) => sum + (consumedByProvider.value[p] ?? 0), 0),
);

const quotaPct = computed(() => {
    const total = balance.value?.quota_total ?? 0;
    const used  = balance.value?.quota_used  ?? 0;
    return total > 0 ? Math.min(100, Math.round((used / total) * 100)) : 0;
});

function fmt(n) {
    return Number(n ?? 0).toLocaleString('pt-BR');
}

function providerPct(provider) {
    if (totalConsumed.value === 0) return 0;
    return Math.round(((consumedByProvider.value[provider] ?? 0) / totalConsumed.value) * 100);
}

function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleDateString('pt-BR');
    } catch {
        return '—';
    }
}
</script>

<template>
    <div v-if="wallet" class="card mb-3 border-primary border-opacity-25 internal-wallet-card">
        <div class="card-body p-3">

            <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-primary-subtle text-primary border border-primary border-opacity-25">
                            <i class="ti ti-building me-1"></i>
                            {{ t?.manual?.badge_internal ?? 'Sua empresa' }}
                        </span>
                        <strong class="small">{{ wallet.entity_name }}</strong>
                    </div>
                    <h6 class="mb-0">{{ t?.internal_wallet?.title ?? 'Sua empresa — consumo interno' }}</h6>
                    <small class="text-muted">{{ t?.internal_wallet?.subtitle ?? '' }}</small>
                </div>
                <button
                    v-if="permissions?.create_manual_for_internal"
                    type="button"
                    class="btn btn-sm btn-primary"
                    @click="emit('add-credit', wallet.entity_id)"
                >
                    <i class="ti ti-coin-plus me-1"></i>
                    {{ t?.internal_wallet?.add_credit ?? 'Adicionar créditos' }}
                </button>
            </div>

            <!-- ─── Saldo principal ────────────────────────────────────── -->
            <div class="row g-3 mb-3">
                <!-- Disponível total -->
                <div class="col-12 col-md-4">
                    <div class="balance-tile balance-tile--main p-3 rounded h-100">
                        <div class="text-muted small mb-1">
                            <i class="ti ti-wallet me-1"></i>
                            {{ t?.internal_wallet?.available ?? 'Disponível agora' }}
                        </div>
                        <h3 class="mb-0 fw-bold text-primary">{{ fmt(balance?.available) }}</h3>
                        <small class="text-muted">
                            {{ t?.internal_wallet?.includes_quota ?? 'cota + comprado' }}
                        </small>
                    </div>
                </div>

                <!-- Cota mensal -->
                <div class="col-12 col-md-4">
                    <div class="balance-tile p-3 rounded h-100">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <div class="text-muted small">
                                <i class="ti ti-calendar-month me-1"></i>
                                {{ t?.internal_wallet?.quota ?? 'Cota mensal' }}
                            </div>
                            <span
                                v-if="balance?.quota_expired"
                                class="badge bg-danger-subtle text-danger small"
                            >
                                {{ t?.internal_wallet?.quota_expired ?? 'expirada' }}
                            </span>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <h4 class="mb-0 fw-bold">{{ fmt(balance?.quota_remaining) }}</h4>
                            <small class="text-muted">/ {{ fmt(balance?.quota_total) }}</small>
                        </div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div
                                class="progress-bar bg-info"
                                role="progressbar"
                                :style="{ width: quotaPct + '%' }">
                            </div>
                        </div>
                        <small class="text-muted">
                            <i class="ti ti-clock me-1"></i>
                            {{ t?.internal_wallet?.resets_at ?? 'Reseta em' }} {{ fmtDate(balance?.quota_period_ends_at) }}
                        </small>
                    </div>
                </div>

                <!-- Comprado (não expira) -->
                <div class="col-12 col-md-4">
                    <div class="balance-tile p-3 rounded h-100">
                        <div class="text-muted small mb-1">
                            <i class="ti ti-coin me-1"></i>
                            {{ t?.internal_wallet?.balance ?? 'Comprados (não expiram)' }}
                        </div>
                        <h4 class="mb-0 fw-bold">{{ fmt(balance?.balance) }}</h4>
                        <small class="text-muted">
                            <i class="ti ti-lock me-1"></i>
                            {{ fmt(balance?.reserved) }} {{ t?.internal_wallet?.reserved ?? 'reservados' }}
                        </small>
                    </div>
                </div>
            </div>

            <!-- ─── Consumo analítico por provedor ─────────────────────── -->
            <div v-if="totalConsumed > 0" class="provider-analytics">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <small class="text-muted fw-semibold text-uppercase" style="letter-spacing: 0.04em; font-size: .7rem;">
                        <i class="ti ti-chart-pie me-1"></i>
                        {{ t?.internal_wallet?.consumed_by_provider ?? 'Consumo histórico por provedor' }}
                    </small>
                    <small class="text-muted">{{ fmt(totalConsumed) }} créditos no total</small>
                </div>

                <div class="row g-2">
                    <div
                        v-for="key in PROVIDER_ORDER"
                        :key="key"
                        class="col-12 col-md-4"
                    >
                        <div
                            class="provider-mini-tile p-2 rounded"
                            :style="{ background: PROVIDER_META[key].bg, borderLeft: `3px solid ${PROVIDER_META[key].color}` }"
                        >
                            <div class="d-flex align-items-center justify-content-between mb-1 small fw-semibold" :style="{ color: PROVIDER_META[key].color }">
                                <span>
                                    <i :class="PROVIDER_META[key].icon" class="me-1"></i>
                                    {{ PROVIDER_META[key].label }}
                                </span>
                                <strong>{{ providerPct(key) }}%</strong>
                            </div>
                            <small class="text-muted">{{ fmt(consumedByProvider[key]) }} créditos consumidos</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.internal-wallet-card {
    background: linear-gradient(135deg, rgba(25, 118, 210, 0.04), rgba(25, 118, 210, 0.01));
}
.balance-tile {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    transition: transform .15s ease;
}
.balance-tile:hover {
    transform: translateY(-1px);
}
.balance-tile--main {
    background: linear-gradient(135deg, rgba(25, 118, 210, 0.08), rgba(25, 118, 210, 0.02));
    border-color: rgba(25, 118, 210, 0.2);
}
.provider-mini-tile {
    background: #f8fafc;
}
</style>
