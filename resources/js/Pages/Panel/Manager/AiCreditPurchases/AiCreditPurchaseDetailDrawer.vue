<script setup>
import { ref, watch, computed, onMounted } from 'vue';

/**
 * Modal de detalhes do pedido de compra de créditos IA (painel manager).
 *
 * Carrega dados completos via fetch ao abrir (metadata, subscription,
 * wallet_balance) — payload da listagem é mais enxuto. Header, info e timeline
 * caem para o `purchase` da lista quando o fetch ainda não respondeu, então a
 * tela nunca fica "vazia" enquanto há dados disponíveis.
 *
 * Tabs: Informações · Linha do tempo · Metadados.
 */
const props = defineProps({
    open:        { type: Boolean, required: true },
    purchase:    { type: Object,  default: null },
    permissions: { type: Object,  default: () => ({}) },
    t:           { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'credit', 'cancel', 'fail', 'refund']);

const tab = ref('info');
const detail = ref(null);
const loading = ref(false);
const errorMsg = ref('');

watch(() => props.open, async (val) => {
    if (val && props.purchase?.actions_url?.show) {
        await loadDetail();
    } else {
        detail.value = null;
        tab.value = 'info';
    }
});

// O watch é lazy: se o drawer montar já com open === true (ou a primeira
// abertura não disparar o watch), o fetch nunca rodava e timeline/metadados
// ficavam vazios mesmo com dados no banco. Garante o carregamento no mount.
onMounted(() => {
    if (props.open && props.purchase?.actions_url?.show && !detail.value) {
        loadDetail();
    }
});

async function loadDetail() {
    const showUrl = props.purchase?.actions_url?.show;
    if (!showUrl) return;

    loading.value = true;
    errorMsg.value = '';
    try {
        const res = await fetch(showUrl, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const json = await res.json();
        if (!json?.purchase) throw new Error('Resposta sem dados do pedido.');
        detail.value = json.purchase;
    } catch (e) {
        errorMsg.value = e.message;
        // eslint-disable-next-line no-console
        console.error('[AiCreditPurchaseDetail] falha ao carregar detalhe:', e);
    } finally {
        loading.value = false;
    }
}

// Fonte única: detalhe completo quando disponível, senão a linha da lista.
// Garante header/info/timeline preenchidos mesmo antes (ou no lugar) do fetch.
const source = computed(() => detail.value ?? props.purchase ?? {});

const TONE = {
    credited:        'success',
    pending_payment: 'warning',
    cancelled:       'secondary',
    failed:          'danger',
    refunded:        'info',
};
const headerTone = computed(() => TONE[source.value?.status] ?? 'secondary');

const isCourtesy = computed(() => {
    const s = source.value;
    return s?.kind === 'courtesy' || Number(s?.amount_cents) === 0;
});

const creditsFmt = computed(() => Number(source.value?.credits ?? 0).toLocaleString('pt-BR'));

const events = computed(() => {
    const s = source.value;
    if (!s) return [];
    const items = [];
    if (s.created_at) {
        items.push({
            label: props.t?.timeline?.created ?? 'Pedido criado',
            at: s.created_at,
            by: s.requested_by,
            icon: 'ti ti-plus',
            tone: 'secondary',
        });
    }
    if (s.credited_at) {
        items.push({
            label: props.t?.timeline?.credited ?? 'Créditos liberados',
            at: s.credited_at,
            icon: 'ti ti-check',
            tone: 'success',
        });
    }
    if (s.cancelled_at) {
        items.push({
            label: props.t?.timeline?.cancelled ?? 'Cancelado',
            at: s.cancelled_at,
            icon: 'ti ti-x',
            tone: 'secondary',
        });
    }
    if (s.failed_at) {
        items.push({
            label: props.t?.timeline?.failed ?? 'Falha de gateway',
            at: s.failed_at,
            icon: 'ti ti-alert-triangle',
            tone: 'danger',
        });
    }
    if (s.refunded_at) {
        items.push({
            label: props.t?.timeline?.refunded ?? 'Reembolsado (créditos estornados)',
            at: s.refunded_at,
            icon: 'ti ti-arrow-back-up',
            tone: 'info',
        });
    }
    return items;
});

const prettyMetadata = computed(() => {
    const m = detail.value?.metadata;
    if (!m) return '';
    if (Array.isArray(m) && m.length === 0) return '';
    if (typeof m === 'object' && Object.keys(m).length === 0) return '';
    try {
        return JSON.stringify(m, null, 2);
    } catch {
        return '';
    }
});

const allowed = computed(() => detail.value?.allowed ?? props.purchase?.allowed ?? {});

function close() { emit('close'); }
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="modal fade show d-block" tabindex="-1"
             style="background: rgba(15,23,42,.55);" @click.self="close">
            <div class="modal-dialog modal-lg modal-dialog-scrollable" style="max-width: 720px;">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header py-2 border-0">
                        <h6 class="modal-title d-flex align-items-center gap-2 mb-0">
                            <i class="ti ti-coin text-warning"></i>
                            {{ t?.detail?.title ?? 'Pedido de compra de créditos' }}
                            <small v-if="source.id" class="text-muted fw-normal">
                                <code class="text-muted">{{ source.id.slice(0, 8) }}</code>
                            </small>
                        </h6>
                        <button type="button" class="btn-close" @click="close"></button>
                    </div>

                    <div class="modal-body p-0">
                        <!-- Header: entidade · métrica de créditos · status -->
                        <div class="acp-hero px-3 py-3" :class="`acp-hero--${headerTone}`">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div class="min-w-0">
                                    <div class="fw-bold text-truncate">{{ source.entity_name ?? '—' }}</div>
                                    <div class="small text-muted text-truncate">
                                        {{ source.package_name }}
                                        <span v-if="source.package_code">· <code class="text-muted">{{ source.package_code }}</code></span>
                                    </div>
                                    <div class="d-flex flex-wrap gap-1 mt-2">
                                        <span class="badge" :class="source.status_badge ?? `text-bg-${headerTone}`">
                                            {{ source.status_label ?? source.status }}
                                        </span>
                                        <span v-if="isCourtesy" class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                            <i class="ti ti-gift me-1"></i>{{ t?.detail?.courtesy ?? 'Cortesia — sem cobrança' }}
                                        </span>
                                        <span v-else-if="source.kind_label" class="badge text-bg-light border">
                                            {{ source.kind_label }}
                                        </span>
                                    </div>
                                </div>
                                <div class="text-end flex-shrink-0">
                                    <div class="acp-credits lh-1">{{ creditsFmt }}</div>
                                    <div class="small text-muted">{{ t?.detail?.credits ?? 'créditos' }}</div>
                                    <div class="small mt-1" :class="isCourtesy ? 'text-success fw-semibold' : 'fw-semibold'">
                                        {{ isCourtesy ? (t?.detail?.no_charge ?? 'R$ 0,00') : source.amount_formatted }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tabs -->
                        <ul class="nav nav-tabs px-3 pt-2">
                            <li class="nav-item">
                                <button class="nav-link" :class="{ active: tab === 'info' }" @click="tab = 'info'">
                                    <i class="ti ti-info-circle me-1"></i>{{ t?.detail?.tab_info ?? 'Informações' }}
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" :class="{ active: tab === 'timeline' }" @click="tab = 'timeline'">
                                    <i class="ti ti-history me-1"></i>{{ t?.detail?.tab_timeline ?? 'Linha do tempo' }}
                                    <span v-if="events.length" class="badge text-bg-light ms-1">{{ events.length }}</span>
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" :class="{ active: tab === 'metadata' }" @click="tab = 'metadata'">
                                    <i class="ti ti-code me-1"></i>{{ t?.detail?.tab_metadata ?? 'Metadados' }}
                                </button>
                            </li>
                        </ul>

                        <div class="p-3">
                            <div v-if="loading" class="text-center text-muted py-4">
                                <i class="ti ti-loader ti-rotate"></i> {{ t?.detail?.loading ?? 'Carregando…' }}
                            </div>
                            <div v-else-if="errorMsg" class="alert alert-danger small mb-0 d-flex justify-content-between align-items-center gap-2">
                                <span><i class="ti ti-alert-circle me-1"></i>{{ errorMsg }}</span>
                                <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" @click="loadDetail">
                                    <i class="ti ti-refresh me-1"></i>{{ t?.detail?.reload ?? 'Recarregar' }}
                                </button>
                            </div>

                            <!-- Tab: Informações -->
                            <div v-else-if="tab === 'info'" class="small">
                                <dl class="row gy-2 mb-0">
                                    <dt class="col-5 text-muted fw-normal">{{ t?.detail?.requested_at ?? 'Solicitado em' }}</dt>
                                    <dd class="col-7 mb-0">{{ source.created_at ?? '—' }}</dd>

                                    <dt class="col-5 text-muted fw-normal">{{ t?.detail?.requested_by ?? 'Solicitante' }}</dt>
                                    <dd class="col-7 mb-0">
                                        {{ source.requested_by ?? '—' }}
                                        <div v-if="source.requested_email" class="text-muted">{{ source.requested_email }}</div>
                                    </dd>

                                    <dt class="col-5 text-muted fw-normal">{{ t?.detail?.package_code ?? 'Código do pacote' }}</dt>
                                    <dd class="col-7 mb-0"><code>{{ source.package_code }}</code></dd>

                                    <template v-if="detail?.subscription">
                                        <dt class="col-5 text-muted fw-normal">{{ t?.detail?.subscription ?? 'Assinatura' }}</dt>
                                        <dd class="col-7 mb-0">
                                            {{ detail.subscription.plan_name }}
                                            <small class="text-muted d-block">{{ detail.subscription.id }}</small>
                                        </dd>
                                    </template>

                                    <template v-if="detail?.idempotency_key">
                                        <dt class="col-5 text-muted fw-normal">{{ t?.detail?.idempotency_key ?? 'Idempotency' }}</dt>
                                        <dd class="col-7 mb-0"><code class="small">{{ detail.idempotency_key }}</code></dd>
                                    </template>

                                    <template v-if="detail?.wallet_after">
                                        <dt class="col-5 text-muted fw-normal">{{ t?.detail?.wallet_balance ?? 'Saldo carteira' }}</dt>
                                        <dd class="col-7 mb-0">
                                            <span class="fw-bold">{{ detail.wallet_after.available?.toLocaleString('pt-BR') }}</span> disponíveis
                                            <small class="text-muted d-block">
                                                Total comprado: {{ detail.wallet_after.lifetime_purchased?.toLocaleString('pt-BR') }} ·
                                                consumido: {{ detail.wallet_after.lifetime_consumed?.toLocaleString('pt-BR') }}
                                            </small>
                                        </dd>
                                    </template>
                                </dl>
                            </div>

                            <!-- Tab: Linha do tempo -->
                            <div v-else-if="tab === 'timeline'">
                                <div v-if="events.length === 0" class="text-center text-muted py-4">
                                    <i class="ti ti-history d-block mb-1" style="font-size: 1.5rem; opacity:.5;"></i>
                                    <div class="small">{{ t?.timeline?.empty ?? 'Sem eventos registrados.' }}</div>
                                </div>
                                <ol v-else class="acp-timeline list-unstyled mb-0">
                                    <li v-for="(e, i) in events" :key="i" class="acp-timeline__item">
                                        <span class="acp-timeline__dot" :class="`text-${e.tone}`">
                                            <i :class="e.icon"></i>
                                        </span>
                                        <div class="acp-timeline__body">
                                            <div class="fw-semibold small">{{ e.label }}</div>
                                            <div class="text-muted small">
                                                <i class="ti ti-clock me-1"></i>{{ e.at }}
                                                <span v-if="e.by"> · {{ e.by }}</span>
                                            </div>
                                        </div>
                                    </li>
                                </ol>
                            </div>

                            <!-- Tab: Metadados -->
                            <div v-else-if="tab === 'metadata'">
                                <pre v-if="prettyMetadata" class="small bg-light p-2 rounded mb-0" style="max-height: 320px; overflow-y: auto;">{{ prettyMetadata }}</pre>
                                <div v-else class="text-muted small">
                                    {{ detail ? (t?.detail?.no_metadata ?? 'Sem metadados.') : (t?.detail?.detail_not_loaded ?? 'Detalhes ainda não carregados — reabra o pedido.') }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer: ações condicionais -->
                    <div class="modal-footer py-2 border-0 flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary me-auto" @click="close">
                            {{ t?.actions?.close ?? 'Fechar' }}
                        </button>
                        <button v-if="permissions.credit && allowed.credit"
                                type="button" class="btn btn-sm btn-success"
                                @click="emit('credit', purchase)">
                            <i class="ti ti-check me-1"></i>{{ t?.actions?.credit ?? 'Aprovar e creditar' }}
                        </button>
                        <button v-if="permissions.cancel && allowed.cancel"
                                type="button" class="btn btn-sm btn-outline-secondary"
                                @click="emit('cancel', purchase)">
                            <i class="ti ti-x me-1"></i>{{ t?.actions?.cancel ?? 'Cancelar' }}
                        </button>
                        <button v-if="permissions.fail && allowed.fail"
                                type="button" class="btn btn-sm btn-outline-warning"
                                @click="emit('fail', purchase)">
                            <i class="ti ti-alert-triangle me-1"></i>{{ t?.actions?.fail ?? 'Marcar como falha' }}
                        </button>
                        <button v-if="permissions.refund && allowed.refund"
                                type="button" class="btn btn-sm btn-outline-danger"
                                :title="`${t?.actions?.refund ?? 'Reembolsar'} · ${creditsFmt} créditos`"
                                @click="emit('refund', purchase)">
                            <i class="ti ti-arrow-back-up me-1"></i>{{ t?.actions?.refund ?? 'Reembolsar (estornar créditos)' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.acp-hero {
    background: var(--bs-body-bg);
    border-bottom: 1px solid var(--bs-border-color);
    border-left: 3px solid var(--bs-secondary);
}
.acp-hero--success { border-left-color: var(--bs-success); }
.acp-hero--warning { border-left-color: var(--bs-warning); }
.acp-hero--danger  { border-left-color: var(--bs-danger); }
.acp-hero--info    { border-left-color: var(--bs-info); }

.acp-credits {
    font-size: 1.75rem;
    font-weight: 700;
    letter-spacing: -.02em;
}

/* Timeline vertical com linha conectora e bolinhas por status */
.acp-timeline {
    position: relative;
    padding-left: 1.75rem;
}
.acp-timeline::before {
    content: '';
    position: absolute;
    left: .6rem;
    top: .35rem;
    bottom: .35rem;
    width: 2px;
    background: var(--bs-border-color);
}
.acp-timeline__item {
    position: relative;
    padding: 0 0 1rem;
}
.acp-timeline__item:last-child { padding-bottom: 0; }
.acp-timeline__dot {
    position: absolute;
    left: -1.4rem;
    top: -.05rem;
    width: 1.4rem;
    height: 1.4rem;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--bs-body-bg);
    border: 2px solid currentColor;
    font-size: .75rem;
}
.acp-timeline__body { padding-left: .25rem; }
</style>
