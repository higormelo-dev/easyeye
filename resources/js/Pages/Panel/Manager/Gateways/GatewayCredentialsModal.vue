<script setup>
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import { useConfirmationWithReason } from '@/composables/useConfirmationWithReason.js';

const props = defineProps({
    open:    { type: Boolean, required: true },
    gateway: { type: Object,  default: null },
    t:       { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close']);

// Modal de confirmação destrutiva (LGPD/CFM)
const { state: reasonModal, open: openReasonModal, close: closeReasonModal, handle: handleReasonConfirm } = useConfirmationWithReason();

// Credential list
const credentials  = ref([]);
const listLoading  = ref(false);
const listError    = ref('');

// New credential form
const form         = ref({ label: '', secret: '', webhook_secret: '', valid_from: '', valid_to: '' });
const saving       = ref(false);
const formError    = ref('');
const showSecret   = ref(false);
const showWebhook  = ref(false);

// Secret field label/hint from t.secret_label per gateway code
const secretInfo = computed(() => {
    const code = props.gateway?.code ?? '';
    return props.t?.secret_label?.[code] ?? { label: props.t?.modal_cred_api_key ?? 'API Key', hint: '' };
});

watch(() => props.open, async (val) => {
    if (val && props.gateway) {
        resetForm();
        await loadCredentials();
    }
    if (!val) { credentials.value = []; listError.value = ''; }
});

async function loadCredentials() {
    listLoading.value = true;
    listError.value   = '';
    credentials.value = [];
    try {
        const res  = await fetch(props.gateway.credentials_url);
        const json = await res.json();
        if (!res.ok) throw new Error(json.message);
        credentials.value = json.data ?? [];
    } catch {
        listError.value = props.t.js_error_load ?? 'Erro ao carregar.';
    } finally {
        listLoading.value = false;
    }
}

function resetForm() {
    form.value      = { label: '', secret: '', webhook_secret: '', valid_from: '', valid_to: '' };
    formError.value = '';
    showSecret.value  = false;
    showWebhook.value = false;
}

// saveCredential agora exige reason — cadastro de credencial de pagamento
// é evento crítico (afeta capacidade de cobrar clientes).
function saveCredential() {
    if (!form.value.secret) {
        formError.value = props.t.js_error_secret_required ?? 'Informe o secret.';
        return;
    }
    openReasonModal({
        title: props.t.js_confirm_store_title ?? 'Cadastrar credencial',
        message: props.t.js_confirm_store ?? 'A credencial anterior será revogada automaticamente.',
        confirmVariant: 'primary',
        async onConfirm(reason) {
            saving.value    = true;
            formError.value = '';
            try {
                const body = { reason };
                if (form.value.label)          body.label          = form.value.label;
                if (form.value.secret)         body.secret         = form.value.secret;
                if (form.value.webhook_secret) body.webhook_secret = form.value.webhook_secret;
                if (form.value.valid_from)     body.valid_from     = form.value.valid_from;
                if (form.value.valid_to)       body.valid_to       = form.value.valid_to;

                const res  = await fetch(props.gateway.credentials_store_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(body),
                });
                const json = await res.json();
                if (!res.ok) {
                    formError.value = json.errors
                        ? Object.values(json.errors).flat().join(' ')
                        : (json.message ?? props.t.js_error_save);
                    return;
                }
                if (window.showSuccessToast) showSuccessToast(json.message);
                emit('close');
                router.reload({ only: ['gateways', 'defaultGateway'] });
            } finally {
                saving.value = false;
            }
        },
    });
}

function revokeCredential(cred) {
    openReasonModal({
        title: props.t.js_confirm_revoke_title ?? 'Revogar credencial',
        message: props.t.js_confirm_revoke ?? 'Revogar?',
        confirmVariant: 'danger',
        async onConfirm(reason) {
            const res  = await fetch(cred.revoke_url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ reason }),
            });
            const json = await res.json();
            if (res.ok) {
                if (window.showSuccessToast) showSuccessToast(json.message);
                await loadCredentials();
                router.reload({ only: ['gateways', 'defaultGateway'] });
            } else {
                if (window.showErrorToast) showErrorToast(json.message ?? props.t.js_error_generic);
            }
        },
    });
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
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <!-- Header -->
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="ti ti-key me-2"></i>{{ t.modal_cred_title }}
                            <span v-if="gateway" class="text-muted fw-normal ms-1">— {{ gateway.name }}</span>
                        </h5>
                        <button type="button" class="btn-close" @click="$emit('close')"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Info alert -->
                        <div class="alert alert-info d-flex gap-2 align-items-start py-2 mb-4">
                            <i class="ti ti-info-circle flex-shrink-0 mt-1"></i>
                            <div class="small" v-html="t.modal_cred_alert"></div>
                        </div>

                        <!-- History section -->
                        <h6 class="fw-semibold mb-2 small text-uppercase text-muted">{{ t.modal_cred_history }}</h6>

                        <!-- Loading -->
                        <div v-if="listLoading" class="text-center py-3">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                        </div>

                        <!-- Error -->
                        <div v-else-if="listError" class="alert alert-danger small py-2">
                            <i class="ti ti-alert-circle me-1"></i>{{ listError }}
                        </div>

                        <!-- Empty -->
                        <div v-else-if="credentials.length === 0" class="text-center py-3 text-muted small">
                            {{ t.modal_cred_empty }}
                        </div>

                        <!-- Credential list -->
                        <div v-else class="mb-4">
                            <div
                                v-for="c in credentials"
                                :key="c.id"
                                class="d-flex align-items-center justify-content-between py-2 border-bottom gap-3"
                            >
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold small text-truncate">{{ c.label ?? t.js_no_label }}</div>
                                    <div class="d-flex flex-wrap gap-1 mt-1">
                                        <span
                                            class="badge"
                                            :class="c.active ? 'badge-soft-success' : 'badge-soft-secondary'"
                                            style="font-size:.7rem;"
                                        >{{ c.active ? t.modal_cred_active : t.modal_cred_inactive }}</span>
                                        <span
                                            v-if="c.valid_from"
                                            class="badge badge-soft-info"
                                            style="font-size:.7rem;"
                                        >{{ c.valid_from }} → {{ c.valid_to ?? '∞' }}</span>
                                        <span class="text-muted" style="font-size:.75rem;">{{ c.created_at }}</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                    <span class="text-muted fst-italic" style="font-size:.75rem;">{{ t.modal_cred_hidden }}</span>
                                    <button
                                        v-if="c.active"
                                        type="button"
                                        class="btn btn-sm btn-outline-danger py-0 px-2"
                                        style="font-size:.75rem;"
                                        @click="revokeCredential(c)"
                                    >{{ t.modal_cred_revoke }}</button>
                                </div>
                            </div>
                        </div>

                        <hr class="my-3">

                        <!-- New credential form -->
                        <h6 class="fw-semibold mb-3 small text-uppercase text-muted">
                            <i class="ti ti-plus me-1"></i>{{ t.modal_cred_new }}
                        </h6>
                        <form autocomplete="off" @submit.prevent="saveCredential">
                            <div class="row g-3">
                                <!-- Label -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">{{ t.modal_cred_label }}</label>
                                    <input
                                        v-model="form.label"
                                        type="text"
                                        class="form-control form-control-sm"
                                        :placeholder="t.modal_cred_label_ph"
                                    >
                                </div>

                                <!-- Secret key -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">
                                        {{ secretInfo.label }} <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <input
                                            v-model="form.secret"
                                            :type="showSecret ? 'text' : 'password'"
                                            class="form-control font-monospace"
                                            autocomplete="new-password"
                                            required
                                            :placeholder="t.modal_cred_api_key_ph"
                                        >
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary"
                                            @click="showSecret = !showSecret"
                                        >
                                            <i :class="`ti ${showSecret ? 'ti-eye-off' : 'ti-eye'}`"></i>
                                        </button>
                                    </div>
                                    <div v-if="secretInfo.hint" class="form-text">{{ secretInfo.hint }}</div>
                                </div>

                                <!-- Webhook secret -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">
                                        {{ t.modal_cred_webhook }}
                                        <span class="text-muted fw-normal">{{ t.modal_cred_webhook_opt }}</span>
                                    </label>
                                    <div class="input-group input-group-sm">
                                        <input
                                            v-model="form.webhook_secret"
                                            :type="showWebhook ? 'text' : 'password'"
                                            class="form-control font-monospace"
                                            autocomplete="new-password"
                                        >
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary"
                                            @click="showWebhook = !showWebhook"
                                        >
                                            <i :class="`ti ${showWebhook ? 'ti-eye-off' : 'ti-eye'}`"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Validity -->
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">{{ t.modal_cred_valid_from }}</label>
                                    <input v-model="form.valid_from" type="date" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">{{ t.modal_cred_valid_to }}</label>
                                    <input v-model="form.valid_to" type="date" class="form-control form-control-sm">
                                </div>
                            </div>

                            <!-- Form error -->
                            <div v-if="formError" class="alert alert-danger mt-3 py-2 small mb-0">
                                <i class="ti ti-alert-circle me-1"></i>{{ formError }}
                            </div>
                        </form>
                    </div>

                    <!-- Footer -->
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" @click="$emit('close')">
                            {{ t.modal_cred_close }}
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="saveCredential">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="ti ti-device-floppy me-1"></i>
                            {{ t.modal_cred_save }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirmação destrutiva com justificativa (LGPD/CFM) -->
        <ConfirmationWithReasonModal
            :open="reasonModal.open"
            :title="reasonModal.title"
            :message="reasonModal.message"
            :confirm-variant="reasonModal.confirmVariant"
            :saving="reasonModal.saving"
            @close="closeReasonModal"
            @confirm="handleReasonConfirm"
        />
    </Teleport>
</template>
