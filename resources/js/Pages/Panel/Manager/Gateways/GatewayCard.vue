<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    gateway: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['open-credentials', 'open-priority', 'open-set-default']);

const toggling = ref(false);

// O que o gateway faz hoje na EasyEye (GatewaysController::capabilities).
const caps = computed(() => props.gateway.capabilities ?? null);
const methodLabel = (method) => props.t.caps_method?.[method] ?? method;
// Último health check real (billing:gateway-health).
const health = computed(() => props.gateway.health ?? null);
const healthBadge = computed(() => {
    const status = health.value?.status;
    if (!status) return null;
    if (status === 'ok') return { cls: 'badge-soft-success', icon: 'ti-circle-check' };
    if (['auth_error', 'environment_mismatch', 'not_configured'].includes(status))
        return { cls: 'badge-soft-danger', icon: 'ti-alert-octagon' };
    if (status === 'config_only') return { cls: 'badge-soft-secondary', icon: 'ti-settings' };
    return { cls: 'badge-soft-warning', icon: 'ti-alert-triangle' };
});
const healthLabel = computed(() => props.t.health_status?.[health.value?.status] ?? health.value?.status ?? '');
const healthTitle = computed(() => {
    if (!health.value) return '';
    const when = health.value.checked_at ? new Date(health.value.checked_at).toLocaleString() : '';
    return [health.value.message, when ? (props.t.health_checked_at ?? '').replace(':date', when) : '']
        .filter(Boolean)
        .join(' — ');
});

const installmentsLabel = computed(() => {
    const max = caps.value?.max_installments;
    if (!max) return null;
    return max <= 1 ? props.t.caps_installments_one : (props.t.caps_installments ?? '').replace(':count', max);
});

async function toggleActive() {
    toggling.value = true;
    try {
        const res = await fetch(props.gateway.toggle_active_url, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                Accept: 'application/json',
            },
        });
        const json = await res.json();
        if (res.ok) {
            if (window.showSuccessToast) window.showSuccessToast(json.message);
            router.reload({ only: ['gateways', 'defaultGateway'] });
        } else {
            if (window.showErrorToast) window.showErrorToast(json.message ?? props.t.js_error_generic);
        }
    } finally {
        toggling.value = false;
    }
}

async function setDefault() {
    const confirmMsg = (props.t.js_confirm_set_default ?? '').replace(':name', props.gateway.name);
    if (!confirm(confirmMsg)) return;

    const res = await fetch(props.gateway.set_default_url, {
        method: 'PATCH',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            Accept: 'application/json',
        },
    });
    const json = await res.json();
    if (res.ok) {
        if (window.showSuccessToast) window.showSuccessToast(json.message);
        router.reload({ only: ['gateways', 'defaultGateway'] });
    } else {
        if (window.showErrorToast) window.showErrorToast(json.message ?? props.t.js_error_set_default);
    }
}
</script>

<template>
    <div class="card h-100" :class="{ 'opacity-75': !gateway.active, 'gw-gold-border': gateway.is_default }">
        <!-- ── Card Header ─────────────────────────────────────────────────── -->
        <div
            class="card-header d-flex align-items-center justify-content-between py-2 px-3"
            :class="{ 'gw-default-header': gateway.is_default }"
        >
            <!-- Name + badges -->
            <div class="d-flex align-items-center gap-2 flex-wrap min-w-0">
                <i v-if="gateway.is_default" class="ti ti-star flex-shrink-0 gw-gold-icon"></i>
                <span class="fw-bold text-truncate">{{ gateway.name }}</span>
                <span
                    class="badge badge-soft-secondary text-uppercase flex-shrink-0"
                    style="font-size: 0.7rem; letter-spacing: 0.04em"
                >
                    {{ gateway.code }}
                </span>
                <span v-if="gateway.is_default" class="badge flex-shrink-0 gw-gold-badge" style="font-size: 0.7rem">
                    {{ t.default_badge }}
                </span>
            </div>

            <!-- Active toggle -->
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <span
                    class="badge"
                    :class="gateway.active ? 'badge-soft-success' : 'badge-soft-secondary'"
                    style="font-size: 0.72rem"
                >
                    {{ gateway.active ? t.status_active : t.status_inactive }}
                </span>
                <div class="form-check form-switch mb-0">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        :checked="gateway.active"
                        :disabled="toggling"
                        :title="gateway.active ? t.toggle_deactivate : t.toggle_activate"
                        @change="toggleActive"
                    />
                </div>
            </div>
        </div>

        <!-- ── Card Body ──────────────────────────────────────────────────── -->
        <div class="card-body py-3 px-3">
            <!-- Priority -->
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="text-muted small">{{ t.priority_label }}</span>
                <span class="badge badge-soft-info">
                    <i class="ti ti-sort-ascending me-1"></i>{{ gateway.priority }}
                </span>
                <button
                    type="button"
                    class="btn btn-link btn-sm p-0 text-muted"
                    style="font-size: 0.78rem"
                    @click="$emit('open-priority', gateway)"
                >
                    {{ t.priority_change }}
                </button>
            </div>

            <!-- Billing credentials -->
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="ti ti-key text-muted" style="font-size: 0.95rem"></i>
                    <span class="small text-muted">{{ t.billing_credentials }}</span>
                </div>
                <span v-if="gateway.credentials_label" class="badge badge-soft-success" style="font-size: 0.72rem">
                    <i class="ti ti-check me-1"></i>{{ gateway.credentials_label }}
                </span>
                <span v-else class="badge badge-soft-warning" style="font-size: 0.72rem">
                    <i class="ti ti-alert-triangle me-1"></i>{{ t.credentials_none }}
                </span>
            </div>

            <!-- Health check real (diário) -->
            <div
                v-if="healthBadge"
                class="d-flex align-items-center justify-content-between mb-2"
                data-test="gateway-health"
            >
                <div class="d-flex align-items-center gap-2">
                    <i class="ti ti-heartbeat text-muted" style="font-size: 0.95rem"></i>
                    <span class="small text-muted">{{ t.health_title }}</span>
                </div>
                <span
                    class="badge"
                    :class="healthBadge.cls"
                    style="font-size: 0.72rem"
                    :title="healthTitle"
                    :data-status="health.status"
                >
                    <i :class="['ti me-1', healthBadge.icon]"></i>{{ healthLabel }}
                </span>
            </div>

            <!-- O que faz hoje na EasyEye (métodos da classe do gateway) -->
            <div v-if="caps" class="mt-3 pt-2 border-top" data-test="gateway-caps">
                <div class="small text-muted mb-1">{{ t.caps_title }}</div>
                <div class="d-flex flex-wrap align-items-center gap-1">
                    <template v-if="caps.transparent.length">
                        <span class="small text-muted me-1">{{ t.caps_transparent }}</span>
                        <span
                            v-for="method in caps.transparent"
                            :key="method"
                            class="badge badge-soft-info"
                            style="font-size: 0.7rem"
                            :title="method === 'credit_card' ? t.caps_card_public_key_title : undefined"
                            :data-test="`gateway-cap-method-${method}`"
                        >
                            {{ methodLabel(method) }}
                        </span>
                    </template>
                    <span
                        v-else
                        class="badge badge-soft-warning"
                        style="font-size: 0.7rem"
                        :title="t.caps_link_only_title"
                        data-test="gateway-cap-link-only"
                    >
                        <i class="ti ti-external-link me-1"></i>{{ t.caps_link_only }}
                    </span>
                </div>
                <div class="d-flex flex-wrap gap-1 mt-1">
                    <span
                        v-if="caps.hosted_card_checkout"
                        class="badge badge-soft-secondary"
                        style="font-size: 0.7rem"
                        :title="t.caps_hosted_card_title"
                        data-test="gateway-cap-hosted"
                    >
                        <i class="ti ti-shield-lock me-1"></i>{{ t.caps_hosted_card }}
                    </span>
                    <span
                        v-else-if="caps.card_link"
                        class="badge badge-soft-secondary"
                        style="font-size: 0.7rem"
                        :title="t.caps_card_link_title"
                    >
                        <i class="ti ti-link me-1"></i>{{ t.caps_card_link }}
                    </span>
                    <span
                        v-if="caps.refund"
                        class="badge badge-soft-secondary"
                        style="font-size: 0.7rem"
                        :title="caps.partial_refund ? t.caps_refund_partial_title : t.caps_refund_title"
                        data-test="gateway-cap-refund"
                    >
                        <i class="ti ti-receipt-refund me-1"></i
                        >{{ caps.partial_refund ? t.caps_refund_partial : t.caps_refund }}
                    </span>
                    <span
                        v-if="installmentsLabel"
                        class="badge badge-soft-secondary"
                        style="font-size: 0.7rem"
                        :title="t.caps_installments_title"
                        data-test="gateway-cap-installments"
                    >
                        <i class="ti ti-credit-card me-1"></i>{{ installmentsLabel }}
                    </span>
                    <span
                        v-if="caps.saved_card_renewal"
                        class="badge badge-soft-secondary"
                        style="font-size: 0.7rem"
                        :title="t.caps_saved_card_title"
                    >
                        <i class="ti ti-repeat me-1"></i>{{ t.caps_saved_card }}
                    </span>
                    <span
                        v-if="caps.card_replacement"
                        class="badge badge-soft-secondary"
                        style="font-size: 0.7rem"
                        :title="t.caps_card_replacement_title"
                    >
                        <i class="ti ti-replace me-1"></i>{{ t.caps_card_replacement }}
                    </span>
                    <span
                        class="badge badge-soft-secondary"
                        style="font-size: 0.7rem"
                        :title="caps.native_recurrence ? t.caps_native_recurrence_title : t.caps_local_renewal_title"
                        data-test="gateway-cap-recurrence"
                    >
                        <i class="ti ti-calendar-repeat me-1"></i
                        >{{ caps.native_recurrence ? t.caps_native_recurrence : t.caps_local_renewal }}
                    </span>
                </div>
            </div>
        </div>

        <!-- ── Card Footer ─────────────────────────────────────────────────── -->
        <div class="card-footer bg-transparent py-2 px-3">
            <button
                type="button"
                class="btn btn-sm btn-outline-primary w-100 mb-2"
                data-test="gateway-btn-credentials"
                @click="$emit('open-credentials', gateway)"
            >
                <i class="ti ti-key me-1"></i>{{ t.btn_credentials }}
            </button>

            <!-- Default button -->
            <template v-if="!gateway.is_default">
                <button
                    type="button"
                    class="btn btn-sm w-100"
                    :class="gateway.can_be_default ? 'btn-outline-warning' : 'btn-outline-secondary'"
                    :disabled="!gateway.can_be_default"
                    :title="
                        !gateway.active
                            ? t.btn_activate_first
                            : !gateway.credentials_label
                              ? t.btn_add_credential
                              : t.btn_set_default_title
                    "
                    @click="setDefault"
                >
                    <i class="ti ti-star me-1"></i>{{ t.btn_set_default }}
                </button>
            </template>
            <template v-else>
                <button type="button" class="btn btn-sm w-100 btn-outline-secondary gw-gold-outline-btn" disabled>
                    <i class="ti ti-star me-1"></i>{{ t.btn_current_default }}
                </button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.gw-gold-border {
    border: 2px solid #fdd835 !important;
}
.gw-default-header {
    background: var(--warning-transparent);
}
.gw-gold-icon {
    color: #f9a825;
}
.gw-gold-badge {
    background: #fdd835;
    color: #5d4037;
}
.gw-gold-outline-btn {
    border-color: #fdd835;
    color: #f9a825;
}

:root[data-bs-theme='dark'] .gw-gold-border {
    border-color: #a3821f !important;
}
:root[data-bs-theme='dark'] .gw-gold-icon {
    color: #d1a936;
}
:root[data-bs-theme='dark'] .gw-gold-badge {
    background: #a3821f;
    color: #fff6df;
}
:root[data-bs-theme='dark'] .gw-gold-outline-btn {
    border-color: #a3821f;
    color: #d1a936;
}

/* Modo dark: botão outline e toggle usam a mesma cor viva do modo
   claro (--primary), o que soa "gritante" repetido em 6 cards. Aqui só
   no dark mode, tons mais discretos/profundos. */
:root[data-bs-theme='dark'] .btn-outline-primary {
    --bs-btn-color: #6ea0dd;
    --bs-btn-border-color: #6ea0dd;
    --bs-btn-hover-bg: #6ea0dd;
    --bs-btn-hover-border-color: #6ea0dd;
    color: #6ea0dd;
    border-color: #6ea0dd;
}
:root[data-bs-theme='dark'] .form-check-input:checked {
    background-color: #4a7dc2;
    border-color: #4a7dc2;
}
</style>
