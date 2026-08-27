<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    gateway: { type: Object, required: true },
    t:       { type: Object, default: () => ({}) },
});

const emit = defineEmits(['open-credentials', 'open-entity-access', 'open-priority', 'open-set-default']);

const toggling = ref(false);

async function toggleActive() {
    toggling.value = true;
    try {
        const res  = await fetch(props.gateway.toggle_active_url, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
        });
        const json = await res.json();
        if (res.ok) {
            if (window.showSuccessToast) showSuccessToast(json.message);
            router.reload({ only: ['gateways', 'defaultGateway'] });
        } else {
            if (window.showErrorToast) showErrorToast(json.message ?? props.t.js_error_generic);
        }
    } finally {
        toggling.value = false;
    }
}

async function setDefault() {
    const confirmMsg = (props.t.js_confirm_set_default ?? '').replace(':name', props.gateway.name);
    if (!confirm(confirmMsg)) return;

    const res  = await fetch(props.gateway.set_default_url, {
        method: 'PATCH',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            'Accept': 'application/json',
        },
    });
    const json = await res.json();
    if (res.ok) {
        if (window.showSuccessToast) showSuccessToast(json.message);
        router.reload({ only: ['gateways', 'defaultGateway'] });
    } else {
        if (window.showErrorToast) showErrorToast(json.message ?? props.t.js_error_set_default);
    }
}
</script>

<template>
    <div
        class="card h-100"
        :class="{ 'opacity-75': !gateway.active }"
        :style="gateway.is_default ? 'border:2px solid #fdd835 !important;' : ''"
    >
        <!-- ── Card Header ─────────────────────────────────────────────────── -->
        <div
            class="card-header d-flex align-items-center justify-content-between py-2 px-3"
            :style="gateway.is_default ? 'background:linear-gradient(135deg,#fff8e1,#fffde7);' : ''"
        >
            <!-- Name + badges -->
            <div class="d-flex align-items-center gap-2 flex-wrap min-w-0">
                <i v-if="gateway.is_default" class="ti ti-star flex-shrink-0" style="color:#f9a825;"></i>
                <span class="fw-bold text-truncate">{{ gateway.name }}</span>
                <span class="badge badge-soft-secondary text-uppercase flex-shrink-0" style="font-size:.7rem;letter-spacing:.04em;">
                    {{ gateway.code }}
                </span>
                <span v-if="gateway.is_default" class="badge flex-shrink-0" style="background:#fdd835;color:#5d4037;font-size:.7rem;">
                    {{ t.default_badge }}
                </span>
            </div>

            <!-- Active toggle -->
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <span class="badge" :class="gateway.active ? 'badge-soft-success' : 'badge-soft-secondary'" style="font-size:.72rem;">
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
                    >
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
                    style="font-size:.78rem;"
                    @click="$emit('open-priority', gateway)"
                >{{ t.priority_change }}</button>
            </div>

            <!-- Billing credentials -->
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="ti ti-key text-muted" style="font-size:.95rem;"></i>
                    <span class="small text-muted">{{ t.billing_credentials }}</span>
                </div>
                <span
                    v-if="gateway.credentials_label"
                    class="badge badge-soft-success"
                    style="font-size:.72rem;"
                >
                    <i class="ti ti-check me-1"></i>{{ gateway.credentials_label }}
                </span>
                <span v-else class="badge badge-soft-warning" style="font-size:.72rem;">
                    <i class="ti ti-alert-triangle me-1"></i>{{ t.credentials_none }}
                </span>
            </div>

            <!-- Clinics with access -->
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="ti ti-building-hospital text-muted" style="font-size:.95rem;"></i>
                    <span class="small text-muted">{{ t.clinics_with_access }}</span>
                </div>
                <span
                    v-if="gateway.clinics_label"
                    class="badge badge-soft-primary"
                    style="font-size:.72rem;"
                >{{ gateway.clinics_label }}</span>
                <span v-else class="badge badge-soft-secondary" style="font-size:.72rem;">{{ t.clinics_none }}</span>
            </div>

            <!-- Capabilities -->
            <div class="d-flex flex-wrap gap-1">
                <span v-if="gateway.supports_subscriptions" class="badge badge-soft-success" style="font-size:.7rem;">
                    <i class="ti ti-refresh me-1"></i>{{ t.cap_subscriptions }}
                </span>
                <span v-if="gateway.supports_one_time_charges" class="badge badge-soft-success" style="font-size:.7rem;">
                    <i class="ti ti-bolt me-1"></i>{{ t.cap_one_time }}
                </span>
                <span v-if="gateway.supports_refunds" class="badge badge-soft-success" style="font-size:.7rem;">
                    <i class="ti ti-arrow-back me-1"></i>{{ t.cap_refunds }}
                </span>
                <span v-if="gateway.supports_webhooks" class="badge badge-soft-success" style="font-size:.7rem;">
                    <i class="ti ti-webhook me-1"></i>{{ t.cap_webhooks }}
                </span>
            </div>

        </div>

        <!-- ── Card Footer ─────────────────────────────────────────────────── -->
        <div class="card-footer bg-transparent py-2 px-3">
            <div class="d-flex gap-2 mb-2">
                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary flex-grow-1"
                    @click="$emit('open-credentials', gateway)"
                >
                    <i class="ti ti-key me-1"></i>{{ t.btn_credentials }}
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-success flex-grow-1"
                    @click="$emit('open-entity-access', gateway)"
                >
                    <i class="ti ti-building-hospital me-1"></i>{{ t.btn_entity_access }}
                </button>
            </div>

            <!-- Default button -->
            <template v-if="!gateway.is_default">
                <button
                    type="button"
                    class="btn btn-sm w-100"
                    :class="gateway.can_be_default ? 'btn-outline-warning' : 'btn-outline-secondary'"
                    :disabled="!gateway.can_be_default"
                    :title="!gateway.active
                        ? t.btn_activate_first
                        : (!gateway.credentials_label ? t.btn_add_credential : t.btn_set_default_title)"
                    @click="setDefault"
                >
                    <i class="ti ti-star me-1"></i>{{ t.btn_set_default }}
                </button>
            </template>
            <template v-else>
                <button
                    type="button"
                    class="btn btn-sm w-100 btn-outline-secondary"
                    disabled
                    style="border-color:#fdd835;color:#f9a825;"
                >
                    <i class="ti ti-star me-1"></i>{{ t.btn_current_default }}
                </button>
            </template>
        </div>
    </div>
</template>
