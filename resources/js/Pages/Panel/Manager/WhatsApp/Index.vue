<script setup>
import { ref, reactive, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * Manager → WhatsApp (Z-API): configuração POR CLÍNICA, exclusiva do dono/
 * admin do SaaS. Cada clínica recebe uma instância Z-API (número próprio) —
 * as credenciais são da conta da empresa dona e nunca chegam à clínica.
 */
const props = defineProps({
    clinics: { type: Array,  default: () => [] },
    routes:  { type: Object, required: true },
    t:       { type: Object, required: true },
});

const breadcrumbs = [];

function url(key, entityId) {
    return props.routes[key].replace('__ID__', entityId);
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

// ── Modal de configuração por clínica ───────────────────────────────────────
const showModal   = ref(false);
const editing     = ref(null); // clinic row
const saving      = ref(false);
const savedFlash  = ref('');
const webhookWarn = ref(false);
const errors      = ref({});

const form = reactive({
    active: false,
    confirmation_enabled: true,
    confirmation_hours_before: 24,
    survey_enabled: true,
    survey_delay_hours: 2,
    instance_id: '',
    instance_token: '',
    client_token: '',
});

function openConfig(clinic) {
    editing.value = clinic;
    Object.assign(form, {
        active:                    clinic.setting?.active ?? false,
        confirmation_enabled:      clinic.setting?.confirmation_enabled ?? true,
        confirmation_hours_before: clinic.setting?.confirmation_hours_before ?? 24,
        survey_enabled:            clinic.setting?.survey_enabled ?? true,
        survey_delay_hours:        clinic.setting?.survey_delay_hours ?? 2,
        instance_id: '', instance_token: '', client_token: '',
    });
    errors.value = {};
    webhookWarn.value = false;
    testResult.value = null;
    showModal.value = true;
}

async function save() {
    if (!editing.value) return;
    saving.value = true;
    errors.value = {};
    webhookWarn.value = false;
    try {
        const { data } = await window.axios.patch(url('update', editing.value.id), form, {
            headers: { 'X-CSRF-TOKEN': csrf() },
        });
        savedFlash.value = data.message;
        webhookWarn.value = !data.webhook_ok;
        setTimeout(() => { savedFlash.value = ''; }, 3000);
        form.instance_id = form.instance_token = form.client_token = '';
        router.reload({ only: ['clinics'], preserveScroll: true });
    } catch (e) {
        errors.value = e.response?.data?.errors ?? {};
    } finally {
        saving.value = false;
    }
}

// ── Teste de conexão ─────────────────────────────────────────────────────────
const testing    = ref(false);
const testResult = ref(null);

// Credenciais recém-digitadas (ainda não salvas) — completas quando os 3
// campos estão preenchidos. Permite testar ANTES de salvar.
const typedCredentials = computed(() =>
    form.instance_id && form.instance_token && form.client_token
        ? { instance_id: form.instance_id, instance_token: form.instance_token, client_token: form.client_token }
        : null,
);

const canTest = computed(() => Boolean(typedCredentials.value || editing.value?.setting?.has_credentials));

async function testConnection() {
    if (!editing.value) return;
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await window.axios.post(url('test', editing.value.id), typedCredentials.value ?? {}, {
            headers: { 'X-CSRF-TOKEN': csrf() },
        });
        testResult.value = data;
    } catch (e) {
        testResult.value = { ok: false, error: e.response?.data?.error ?? 'Erro ao testar.' };
    } finally {
        testing.value = false;
    }
}

const configuredCount = computed(() => props.clinics.filter(c => c.setting?.has_credentials).length);
const activeCount     = computed(() => props.clinics.filter(c => c.setting?.active).length);
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.title" :subtitle="t.manager_subtitle" />

            <div class="d-flex gap-2 mb-3">
                <span class="badge bg-light text-dark border">{{ clinics.length }} {{ t.manager.clinics }}</span>
                <span class="badge bg-info-subtle text-info border border-info-subtle">{{ configuredCount }} {{ t.manager.configured }}</span>
                <span class="badge bg-success-subtle text-success border border-success-subtle">{{ activeCount }} {{ t.manager.active }}</span>
            </div>

            <div class="card">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>{{ t.manager.clinic }}</th>
                            <th>{{ t.manager.status }}</th>
                            <th class="text-center">{{ t.toggles.confirmation_enabled }}</th>
                            <th class="text-center">{{ t.toggles.survey_enabled }}</th>
                            <th class="text-center">{{ t.stats.survey_average }} (30d)</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="clinic in clinics" :key="clinic.id">
                            <td class="fw-semibold">{{ clinic.name }}</td>
                            <td>
                                <span v-if="clinic.setting?.active && clinic.setting?.has_credentials"
                                      class="badge bg-success-subtle text-success border border-success-subtle">
                                    <i class="fab fa-whatsapp me-1"></i>{{ t.manager.status_active }}
                                </span>
                                <span v-else-if="clinic.setting?.has_credentials"
                                      class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                    {{ t.manager.status_inactive }}
                                </span>
                                <span v-else class="badge bg-light text-muted border">
                                    {{ t.manager.status_unconfigured }}
                                </span>
                            </td>
                            <td class="text-center">
                                <i v-if="clinic.setting?.confirmation_enabled" class="fas fa-check text-success"></i>
                                <i v-else class="fas fa-minus text-muted"></i>
                            </td>
                            <td class="text-center">
                                <i v-if="clinic.setting?.survey_enabled" class="fas fa-check text-success"></i>
                                <i v-else class="fas fa-minus text-muted"></i>
                            </td>
                            <td class="text-center">
                                <template v-if="clinic.stats?.survey_average">
                                    {{ clinic.stats.survey_average }} <i class="fas fa-star text-warning" style="font-size:.7rem;"></i>
                                </template>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-outline-primary btn-sm" @click="openConfig(clinic)">
                                    <i class="fas fa-gear me-1"></i>{{ t.manager.configure }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal de configuração -->
        <Teleport to="body">
            <div v-if="showModal" class="modal fade show d-block" style="background:rgba(0,0,0,.5);" @click.self="showModal = false">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h6 class="modal-title">
                                <i class="fab fa-whatsapp text-success me-2"></i>{{ editing?.name }}
                            </h6>
                            <button type="button" class="btn-close" @click="showModal = false"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Credenciais -->
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="fw-semibold small">{{ t.credentials.title }}</span>
                                    <span v-if="editing?.setting?.has_credentials" class="badge bg-success-subtle text-success">{{ t.credentials.configured }}</span>
                                    <span v-else class="badge bg-warning-subtle text-warning-emphasis">{{ t.credentials.not_configured }}</span>
                                </div>
                                <p class="text-muted" style="font-size:.75rem;">{{ t.credentials.hint }}</p>
                                <p v-if="editing?.setting?.has_credentials" class="text-muted fst-italic" style="font-size:.72rem;">{{ t.credentials.replace_hint }}</p>

                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label small">{{ t.credentials.instance_id }}</label>
                                        <input v-model="form.instance_id" type="text" class="form-control form-control-sm"
                                               :class="{ 'is-invalid': errors.instance_id }" autocomplete="off"
                                               :placeholder="editing?.setting?.instance_id ?? ''">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">{{ t.credentials.instance_token }}</label>
                                        <input v-model="form.instance_token" type="password" class="form-control form-control-sm"
                                               :class="{ 'is-invalid': errors.instance_token }" autocomplete="new-password">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small">{{ t.credentials.client_token }}</label>
                                        <input v-model="form.client_token" type="password" class="form-control form-control-sm"
                                               :class="{ 'is-invalid': errors.client_token }" autocomplete="new-password">
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
                                    <button type="button" class="btn btn-outline-secondary btn-sm"
                                            :disabled="testing || !canTest"
                                            :title="canTest ? '' : t.connection.fill_first"
                                            @click="testConnection">
                                        <span v-if="testing" class="spinner-border spinner-border-sm me-1"></span>
                                        <i v-else class="fas fa-plug me-1"></i>
                                        {{ testing ? t.connection.testing : t.connection.test }}
                                    </button>
                                    <span v-if="testResult?.ok && testResult.connected" class="badge bg-success-subtle text-success">{{ t.connection.connected }}</span>
                                    <span v-else-if="testResult?.ok" class="badge bg-warning-subtle text-warning-emphasis">{{ t.connection.disconnected }}</span>
                                    <span v-else-if="testResult" class="badge bg-danger-subtle text-danger">{{ testResult.error }}</span>
                                </div>
                            </div>

                            <!-- Webhook -->
                            <div v-if="editing?.setting?.webhook_url" class="border rounded p-3 mb-3">
                                <div class="fw-semibold small mb-1">{{ t.webhook.title }}</div>
                                <p class="text-muted mb-2" style="font-size:.72rem;">{{ t.webhook.hint }}</p>
                                <div v-if="webhookWarn" class="alert alert-warning py-1 small mb-2">{{ t.webhook.warn_failed }}</div>
                                <code class="d-block bg-light border rounded p-2 text-break" style="font-size:.7rem;">{{ editing.setting.webhook_url }}</code>
                            </div>

                            <!-- Toggles -->
                            <div class="form-check form-switch mb-2">
                                <input v-model="form.active" type="checkbox" class="form-check-input" id="mgr-wpp-active" role="switch">
                                <label class="form-check-label fw-semibold small" for="mgr-wpp-active">{{ t.toggles.active }}</label>
                            </div>

                            <div class="border rounded p-2 mb-2">
                                <div class="form-check form-switch">
                                    <input v-model="form.confirmation_enabled" type="checkbox" class="form-check-input" id="mgr-wpp-confirm" role="switch">
                                    <label class="form-check-label small fw-semibold" for="mgr-wpp-confirm">{{ t.toggles.confirmation_enabled }}</label>
                                </div>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <label class="small text-muted mb-0">{{ t.toggles.confirmation_hours_before }}</label>
                                    <input v-model.number="form.confirmation_hours_before" type="number" min="1" max="168"
                                           class="form-control form-control-sm" style="width:80px;">
                                    <span class="small text-muted">h</span>
                                </div>
                            </div>

                            <div class="border rounded p-2">
                                <div class="form-check form-switch">
                                    <input v-model="form.survey_enabled" type="checkbox" class="form-check-input" id="mgr-wpp-survey" role="switch">
                                    <label class="form-check-label small fw-semibold" for="mgr-wpp-survey">{{ t.toggles.survey_enabled }}</label>
                                </div>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <label class="small text-muted mb-0">{{ t.toggles.survey_delay_hours }}</label>
                                    <input v-model.number="form.survey_delay_hours" type="number" min="0" max="168"
                                           class="form-control form-control-sm" style="width:80px;">
                                    <span class="small text-muted">h</span>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer py-2 d-flex justify-content-between">
                            <span v-if="savedFlash" class="badge bg-success-subtle text-success">{{ savedFlash }}</span>
                            <span v-else></span>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-secondary btn-sm" @click="showModal = false">Fechar</button>
                                <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="save">
                                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="fas fa-floppy-disk me-1"></i>{{ t.save }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
