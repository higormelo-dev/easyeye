<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    open:           { type: Boolean, required: true },
    gateways:       { type: Array,   default: () => [] },
    defaultGateway: { type: Object,  default: null },
    t:              { type: Object,  default: () => ({}) },
});

const emit    = defineEmits(['close']);
const saving  = ref(null); // gateway id being saved

async function setDefault(gateway) {
    if (!confirm((props.t.js_confirm_set_default_modal ?? '').replace(':name', gateway.name))) return;
    saving.value = gateway.id;
    try {
        const res  = await fetch(gateway.set_default_url, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
        });
        const json = await res.json();
        if (res.ok) {
            if (window.showSuccessToast) showSuccessToast(json.message);
            emit('close');
            router.reload({ only: ['gateways', 'defaultGateway'] });
        } else {
            if (window.showErrorToast) showErrorToast(json.message ?? props.t.js_error_set_default);
        }
    } finally {
        saving.value = null;
    }
}

function isDefault(g) {
    return props.defaultGateway?.id === g.id;
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="modal fade show d-block"
            tabindex="-1"
            style="background:rgba(0,0,0,.4)"
            @click.self="$emit('close')"
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="ti ti-star me-2 text-warning"></i>{{ t.modal_default_title }}
                        </h5>
                        <button type="button" class="btn-close" @click="$emit('close')"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Alert -->
                        <div class="alert alert-warning d-flex gap-2 align-items-start py-2 mb-4">
                            <i class="ti ti-alert-triangle flex-shrink-0 mt-1"></i>
                            <div class="small" v-html="t.modal_default_alert"></div>
                        </div>

                        <!-- Gateway list -->
                        <div class="list-group list-group-flush">
                            <div
                                v-for="g in gateways"
                                :key="g.id"
                                class="list-group-item list-group-item-action d-flex align-items-center gap-3 px-0 py-3"
                                :class="{ 'opacity-50': !g.can_be_default }"
                                style="border-left:0;border-right:0;"
                            >
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <i v-if="isDefault(g)" class="ti ti-star" style="color:#f9a825;"></i>
                                        <span class="fw-semibold small">{{ g.name }}</span>
                                        <span class="badge badge-soft-secondary text-uppercase" style="font-size:.68rem;">{{ g.code }}</span>
                                        <span v-if="isDefault(g)" class="badge" style="background:#fdd835;color:#5d4037;font-size:.68rem;">
                                            {{ t.modal_default_current }}
                                        </span>
                                    </div>
                                    <div class="d-flex gap-1 mt-1">
                                        <span v-if="!g.active" class="badge badge-soft-secondary" style="font-size:.68rem;">{{ t.status_inactive }}</span>
                                        <span
                                            v-if="g.credentials_label"
                                            class="badge badge-soft-success"
                                            style="font-size:.68rem;"
                                        >{{ g.credentials_label }}</span>
                                        <span v-else class="badge badge-soft-warning" style="font-size:.68rem;">{{ t.credentials_none }}</span>
                                    </div>
                                </div>
                                <div class="flex-shrink-0">
                                    <button
                                        v-if="isDefault(g)"
                                        class="btn btn-sm"
                                        style="background:#fdd835;color:#5d4037;border:none;"
                                        disabled
                                    >
                                        <i class="ti ti-check me-1"></i>{{ t.default_badge }}
                                    </button>
                                    <button
                                        v-else-if="g.can_be_default"
                                        type="button"
                                        class="btn btn-sm btn-outline-warning"
                                        :disabled="saving === g.id"
                                        @click="setDefault(g)"
                                    >
                                        <span v-if="saving === g.id" class="spinner-border spinner-border-sm me-1"></span>
                                        <i v-else class="ti ti-star me-1"></i>{{ t.modal_default_btn }}
                                    </button>
                                    <button v-else class="btn btn-sm btn-outline-secondary" disabled>
                                        {{ t.modal_default_unavailable }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" @click="$emit('close')">
                            {{ t.modal_default_close }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
