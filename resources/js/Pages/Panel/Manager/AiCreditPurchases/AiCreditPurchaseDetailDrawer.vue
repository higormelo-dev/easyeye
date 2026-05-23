<script setup>
import { ref, watch, computed } from 'vue';

/**
 * Drawer lateral com detalhes do pedido de compra de créditos IA.
 *
 * Carrega dados completos via fetch ao abrir (para incluir metadata,
 * subscription, wallet_balance) — payload da listagem é mais enxuto.
 *
 * Tabs:
 *   1. Informações — dados básicos do pedido
 *   2. Linha do tempo — eventos de transição de status
 *   3. Metadados — JSON técnico (idempotency_key, source, etc.)
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

async function loadDetail() {
    loading.value = true;
    errorMsg.value = '';
    try {
        const res = await fetch(props.purchase.actions_url.show, {
            headers: { 'Accept': 'application/json' },
        });
        if (!res.ok) throw new Error(`Erro ${res.status}`);
        const json = await res.json();
        detail.value = json.purchase;
    } catch (e) {
        errorMsg.value = e.message;
    } finally {
        loading.value = false;
    }
}

const events = computed(() => {
    if (!detail.value) return [];
    const items = [];
    if (detail.value.created_at) {
        items.push({
            label: props.t?.timeline?.created ?? 'Pedido criado',
            at: detail.value.created_at,
            icon: 'ti ti-plus',
            color: 'text-secondary',
        });
    }
    if (detail.value.credited_at) {
        items.push({
            label: props.t?.timeline?.credited ?? 'Creditado',
            at: detail.value.credited_at,
            icon: 'ti ti-check',
            color: 'text-success',
        });
    }
    if (detail.value.cancelled_at) {
        items.push({
            label: props.t?.timeline?.cancelled ?? 'Cancelado',
            at: detail.value.cancelled_at,
            icon: 'ti ti-x',
            color: 'text-secondary',
        });
    }
    if (detail.value.failed_at) {
        items.push({
            label: props.t?.timeline?.failed ?? 'Falha de gateway',
            at: detail.value.failed_at,
            icon: 'ti ti-alert-triangle',
            color: 'text-danger',
        });
    }
    if (detail.value.refunded_at) {
        items.push({
            label: props.t?.timeline?.refunded ?? 'Reembolsado',
            at: detail.value.refunded_at,
            icon: 'ti ti-arrow-back-up',
            color: 'text-info',
        });
    }
    return items;
});

const prettyMetadata = computed(() => {
    if (!detail.value?.metadata) return '';
    try {
        return JSON.stringify(detail.value.metadata, null, 2);
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
             style="background: rgba(0,0,0,.5);" @click.self="close">
            <div class="modal-dialog modal-lg modal-dialog-scrollable" style="max-width: 720px;">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title">
                            <i class="ti ti-coin text-warning me-1"></i>
                            Pedido de compra de créditos
                            <small v-if="purchase" class="text-muted">{{ purchase.id?.slice(0, 8) }}</small>
                        </h6>
                        <button type="button" class="btn-close" @click="close"></button>
                    </div>

                    <div class="modal-body p-0">
                        <!-- Header com status + valores -->
                        <div class="px-3 py-2 border-bottom bg-light">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-bold">{{ purchase?.entity_name ?? '—' }}</div>
                                    <small class="text-muted">{{ purchase?.package_name }} ({{ purchase?.package_code }})</small>
                                </div>
                                <div class="text-end">
                                    <div class="h5 mb-0">{{ purchase?.amount_formatted }}</div>
                                    <small class="text-muted">{{ purchase?.credits?.toLocaleString('pt-BR') }} créditos</small>
                                </div>
                            </div>
                            <div class="mt-2">
                                <span class="badge" :class="purchase?.status_badge">{{ purchase?.status_label }}</span>
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
                                <i class="ti ti-loader ti-rotate"></i> Carregando…
                            </div>
                            <div v-else-if="errorMsg" class="alert alert-danger small">
                                {{ errorMsg }}
                            </div>

                            <!-- Tab: Informações -->
                            <div v-else-if="tab === 'info'" class="small">
                                <dl class="row mb-0">
                                    <dt class="col-5 text-muted">Solicitado em</dt>
                                    <dd class="col-7">{{ purchase?.created_at ?? '—' }}</dd>

                                    <dt class="col-5 text-muted">Solicitante</dt>
                                    <dd class="col-7">
                                        {{ purchase?.requested_by ?? '—' }}
                                        <div v-if="purchase?.requested_email" class="text-muted small">{{ purchase.requested_email }}</div>
                                    </dd>

                                    <dt class="col-5 text-muted">{{ t?.detail?.package_code ?? 'Código do pacote' }}</dt>
                                    <dd class="col-7"><code>{{ purchase?.package_code }}</code></dd>

                                    <dt v-if="detail?.subscription" class="col-5 text-muted">{{ t?.detail?.subscription ?? 'Assinatura' }}</dt>
                                    <dd v-if="detail?.subscription" class="col-7">
                                        {{ detail.subscription.plan_name }}
                                        <small class="text-muted d-block">{{ detail.subscription.id }}</small>
                                    </dd>

                                    <dt v-if="detail?.idempotency_key" class="col-5 text-muted">{{ t?.detail?.idempotency_key ?? 'Idempotency' }}</dt>
                                    <dd v-if="detail?.idempotency_key" class="col-7"><code class="small">{{ detail.idempotency_key }}</code></dd>

                                    <dt v-if="detail?.wallet_after" class="col-5 text-muted">{{ t?.detail?.wallet_balance ?? 'Saldo carteira' }}</dt>
                                    <dd v-if="detail?.wallet_after" class="col-7">
                                        <span class="fw-bold">{{ detail.wallet_after.available?.toLocaleString('pt-BR') }}</span> disponíveis
                                        <small class="text-muted d-block">
                                            Total comprado: {{ detail.wallet_after.lifetime_purchased?.toLocaleString('pt-BR') }} ·
                                            consumido: {{ detail.wallet_after.lifetime_consumed?.toLocaleString('pt-BR') }}
                                        </small>
                                    </dd>
                                </dl>
                            </div>

                            <!-- Tab: Linha do tempo -->
                            <div v-else-if="tab === 'timeline'">
                                <div v-if="events.length === 0" class="text-muted text-center small py-3">
                                    Sem eventos registrados.
                                </div>
                                <div v-for="(e, i) in events" :key="i" class="d-flex align-items-start gap-2 py-2 border-bottom">
                                    <i :class="`${e.icon} ${e.color} mt-1`" style="font-size: 1.1rem;"></i>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold small">{{ e.label }}</div>
                                        <small class="text-muted">{{ e.at }}</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Tab: Metadados -->
                            <div v-else-if="tab === 'metadata'">
                                <pre v-if="prettyMetadata" class="small bg-light p-2 rounded" style="max-height: 320px; overflow-y: auto;">{{ prettyMetadata }}</pre>
                                <div v-else class="text-muted small">Sem metadados.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer: ações condicionais -->
                    <div class="modal-footer py-2 flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="close">
                            Fechar
                        </button>
                        <button v-if="permissions.credit && allowed.credit"
                                type="button"
                                class="btn btn-sm btn-success"
                                @click="emit('credit', purchase)">
                            <i class="ti ti-check me-1"></i>{{ t?.actions?.credit ?? 'Aprovar e creditar' }}
                        </button>
                        <button v-if="permissions.cancel && allowed.cancel"
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                @click="emit('cancel', purchase)">
                            <i class="ti ti-x me-1"></i>{{ t?.actions?.cancel ?? 'Cancelar' }}
                        </button>
                        <button v-if="permissions.fail && allowed.fail"
                                type="button"
                                class="btn btn-sm btn-outline-warning"
                                @click="emit('fail', purchase)">
                            <i class="ti ti-alert-triangle me-1"></i>{{ t?.actions?.fail ?? 'Marcar como falha' }}
                        </button>
                        <button v-if="permissions.refund && allowed.refund"
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                @click="emit('refund', purchase)">
                            <i class="ti ti-arrow-back-up me-1"></i>{{ t?.actions?.refund ?? 'Reembolsar' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
