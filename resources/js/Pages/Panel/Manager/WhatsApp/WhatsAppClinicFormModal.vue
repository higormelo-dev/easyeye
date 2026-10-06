<script setup>
import { computed, reactive, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';

/**
 * Configuração do WhatsApp de uma clínica (mesmo padrão do MedicineFormModal):
 * Automação (integração, confirmação, pesquisa) · Número próprio (opcional:
 * App ID da Gupshup, webhook, verificar app e mensagem de teste). Mesmos
 * campos, validações e rotas de antes (PATCH/POST JSON). Nenhum segredo
 * chega aqui — só App ID e situação.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    clinic: { type: Object, default: null },
    // Sem app próprio, o teste da clínica cai no app global (por onde ela envia).
    globalHasApp: { type: Boolean, default: false },
    routes: { type: Object, required: true },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close', 'saved']);

const ui = computed(() => props.t.ui ?? {});

const form = reactive({
    active: false,
    confirmation_enabled: true,
    confirmation_hours_before: 24,
    survey_enabled: true,
    survey_delay_hours: 2,
    app_id: '',
});

// Situação do app salva (atualizada com a resposta de cada save).
const current = ref(null);
const saving = ref(false);
const savedFlash = ref('');
const webhookWarn = ref(false);
const errors = ref({});
const testing = ref(false);
const testResult = ref(null);
const testPhone = ref('');
let flashTimer = null;

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen || !props.clinic) return;
        const s = props.clinic.setting;
        Object.assign(form, {
            active: s?.active ?? false,
            confirmation_enabled: s?.confirmation_enabled ?? true,
            confirmation_hours_before: s?.confirmation_hours_before ?? 24,
            survey_enabled: s?.survey_enabled ?? true,
            survey_delay_hours: s?.survey_delay_hours ?? 2,
            app_id: '',
        });
        current.value = s ? { ...s } : null;
        errors.value = {};
        webhookWarn.value = false;
        savedFlash.value = '';
        testResult.value = null;
        testPhone.value = '';
    },
    { immediate: true },
);

function url(key) {
    return props.routes[key].replace('__ID__', props.clinic.id);
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function payload(extra = {}) {
    return {
        active: form.active,
        confirmation_enabled: form.confirmation_enabled,
        confirmation_hours_before: form.confirmation_hours_before,
        survey_enabled: form.survey_enabled,
        survey_delay_hours: form.survey_delay_hours,
        ...extra,
    };
}

async function saveClinic(extra = {}) {
    if (!props.clinic || saving.value) return;
    saving.value = true;
    errors.value = {};
    webhookWarn.value = false;
    try {
        const { data } = await window.axios.patch(url('update'), payload(extra), {
            headers: { 'X-CSRF-TOKEN': csrf() },
        });
        savedFlash.value = data.message;
        clearTimeout(flashTimer);
        flashTimer = setTimeout(() => (savedFlash.value = ''), 3000);
        webhookWarn.value = data.webhook_ok === false;
        current.value = { ...(current.value ?? {}), ...payload(), ...data };
        form.app_id = '';
        emit('saved', props.clinic.id);
    } catch (e) {
        errors.value = e.response?.data?.errors ?? {};
    } finally {
        saving.value = false;
    }
}

function save() {
    saveClinic(form.app_id ? { app_id: form.app_id } : {});
}

function clearApp() {
    if (!current.value?.has_app) return;
    if (!window.confirm(props.t.app.clear_confirm)) return;
    testResult.value = null;
    saveClinic({ clear_app: true });
}

const canTest = computed(() => Boolean(form.app_id || current.value?.has_app || props.globalHasApp));

async function test(withMessage = false) {
    if (!props.clinic) return;
    const body = {};
    if (form.app_id) body.app_id = form.app_id;
    if (withMessage) body.phone = testPhone.value;

    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await window.axios.post(url('test'), body, { headers: { 'X-CSRF-TOKEN': csrf() } });
        testResult.value = data;
    } catch (e) {
        testResult.value = { ok: false, error: e.response?.data?.error ?? props.t.connection.failed };
    } finally {
        testing.value = false;
    }
}

const firstError = computed(() => {
    const other = Object.entries(errors.value).find(([key]) => key !== 'app_id');

    return other ? other[1][0] : '';
});
</script>

<template>
    <CenteredModal :open="open" size="lg" :close-label="t.close" @close="emit('close')">
        <template #header>
            <h5 class="mb-0 text-break">
                <i class="ti ti-brand-whatsapp me-1 text-success" aria-hidden="true"></i>
                {{ ui.config_title }} — {{ clinic?.name }}
            </h5>
        </template>

        <form v-if="clinic" id="wa-clinic-form" data-test="wa-clinic-form" @submit.prevent="save">
            <!-- ── Automação ─────────────────────────────────────────── -->
            <fieldset class="mb-4">
                <legend class="h6 fw-semibold mb-2">
                    <i class="ti ti-message-check me-1 text-primary" aria-hidden="true"></i>{{ ui.section_automation }}
                </legend>

                <div class="form-check form-switch mb-1">
                    <input
                        id="mgr-wpp-active"
                        v-model="form.active"
                        type="checkbox"
                        class="form-check-input"
                        role="switch"
                        aria-describedby="mgr-wpp-active-hint"
                    />
                    <label class="form-check-label fw-semibold" for="mgr-wpp-active">{{ t.toggles.active }}</label>
                </div>
                <p id="mgr-wpp-active-hint" class="form-text mt-0 mb-3">{{ ui.active_hint }}</p>

                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <div class="border rounded p-2 h-100">
                            <div class="form-check form-switch">
                                <input
                                    id="mgr-wpp-confirm"
                                    v-model="form.confirmation_enabled"
                                    type="checkbox"
                                    class="form-check-input"
                                    role="switch"
                                    aria-describedby="mgr-wpp-confirm-hint"
                                />
                                <label class="form-check-label small fw-semibold" for="mgr-wpp-confirm">{{
                                    t.toggles.confirmation_enabled
                                }}</label>
                            </div>
                            <p id="mgr-wpp-confirm-hint" class="text-muted mb-1 small">
                                {{ t.toggles.confirmation_hint }}
                            </p>
                            <label class="form-label small text-muted mb-1" for="mgr-wpp-hours">{{
                                t.toggles.confirmation_hours_before
                            }}</label>
                            <div class="input-group input-group-sm wa-hours">
                                <input
                                    id="mgr-wpp-hours"
                                    v-model.number="form.confirmation_hours_before"
                                    type="number"
                                    min="1"
                                    max="168"
                                    class="form-control"
                                    :class="{ 'is-invalid': errors.confirmation_hours_before }"
                                />
                                <span class="input-group-text">{{ ui.hours_unit }}</span>
                            </div>
                            <div v-if="errors.confirmation_hours_before" class="text-danger small mt-1">
                                {{ errors.confirmation_hours_before[0] }}
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="border rounded p-2 h-100">
                            <div class="form-check form-switch">
                                <input
                                    id="mgr-wpp-survey"
                                    v-model="form.survey_enabled"
                                    type="checkbox"
                                    class="form-check-input"
                                    role="switch"
                                    aria-describedby="mgr-wpp-survey-hint"
                                />
                                <label class="form-check-label small fw-semibold" for="mgr-wpp-survey">{{
                                    t.toggles.survey_enabled
                                }}</label>
                            </div>
                            <p id="mgr-wpp-survey-hint" class="text-muted mb-1 small">{{ t.toggles.survey_hint }}</p>
                            <label class="form-label small text-muted mb-1" for="mgr-wpp-delay">{{
                                t.toggles.survey_delay_hours
                            }}</label>
                            <div class="input-group input-group-sm wa-hours">
                                <input
                                    id="mgr-wpp-delay"
                                    v-model.number="form.survey_delay_hours"
                                    type="number"
                                    min="0"
                                    max="168"
                                    class="form-control"
                                    :class="{ 'is-invalid': errors.survey_delay_hours }"
                                />
                                <span class="input-group-text">{{ ui.hours_unit }}</span>
                            </div>
                            <div v-if="errors.survey_delay_hours" class="text-danger small mt-1">
                                {{ errors.survey_delay_hours[0] }}
                            </div>
                        </div>
                    </div>
                </div>
            </fieldset>

            <!-- ── Número próprio (opcional) ──────────────────────────── -->
            <fieldset>
                <legend class="h6 fw-semibold mb-1 d-flex align-items-center flex-wrap gap-2">
                    <span
                        ><i class="ti ti-brand-whatsapp me-1 text-success" aria-hidden="true"></i
                        >{{ ui.section_own_number }}</span
                    >
                    <span v-if="current?.has_app" class="badge badge-soft-success rounded fs-12 fw-medium">{{
                        t.app.configured
                    }}</span>
                    <span v-else class="badge badge-soft-info rounded fs-12 fw-medium">{{ t.app.not_configured }}</span>
                </legend>
                <p class="text-muted small mb-2">{{ t.app.hint }}</p>

                <label class="form-label small fw-semibold" for="clinic-app-id">{{ t.app.app_id }}</label>
                <input
                    id="clinic-app-id"
                    v-model.trim="form.app_id"
                    type="text"
                    class="form-control form-control-sm"
                    :class="{ 'is-invalid': errors.app_id }"
                    autocomplete="off"
                    :placeholder="current?.app_id ?? t.app.app_id_placeholder"
                    :aria-describedby="current?.has_app ? 'clinic-app-id-hint' : undefined"
                    data-test="clinic-app-id"
                />
                <div v-if="errors.app_id" class="invalid-feedback">{{ errors.app_id[0] }}</div>
                <div v-if="current?.has_app" id="clinic-app-id-hint" class="form-text">{{ t.app.replace_hint }}</div>

                <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        :disabled="testing || !canTest"
                        :title="canTest ? undefined : t.connection.fill_first"
                        data-test="clinic-test"
                        @click="test(false)"
                    >
                        <span v-if="testing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        <i v-else class="ti ti-plug me-1" aria-hidden="true"></i>
                        {{ testing ? t.connection.testing : t.connection.test }}
                    </button>
                    <span aria-live="polite" data-test="clinic-test-result">
                        <span v-if="testResult?.ok && testResult.sent" class="badge bg-success-subtle text-success">{{
                            t.connection.sent
                        }}</span>
                        <span
                            v-else-if="testResult?.ok && testResult.healthy"
                            class="badge bg-success-subtle text-success"
                            >{{ t.connection.healthy }}</span
                        >
                        <span v-else-if="testResult?.ok" class="badge bg-warning-subtle text-warning-emphasis">{{
                            t.connection.unhealthy
                        }}</span>
                        <span v-else-if="testResult" class="badge bg-danger-subtle text-danger text-wrap">{{
                            testResult.error
                        }}</span>
                    </span>
                    <button
                        v-if="current?.has_app"
                        type="button"
                        class="btn btn-outline-danger btn-sm ms-auto"
                        :disabled="saving"
                        data-test="clinic-clear-app"
                        @click="clearApp"
                    >
                        <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ t.app.clear }}
                    </button>
                </div>

                <div v-if="canTest" class="d-flex align-items-end gap-2 flex-wrap mt-2">
                    <div>
                        <label class="form-label small mb-1" for="clinic-test-phone">{{
                            t.connection.test_phone
                        }}</label>
                        <input
                            id="clinic-test-phone"
                            v-model.trim="testPhone"
                            v-mask="'phone'"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            class="form-control form-control-sm wa-phone"
                        />
                    </div>
                    <button
                        type="button"
                        class="btn btn-outline-success btn-sm"
                        :disabled="testing || !testPhone"
                        data-test="clinic-send-test"
                        @click="test(true)"
                    >
                        <i class="ti ti-brand-whatsapp me-1" aria-hidden="true"></i>{{ t.connection.send_test }}
                    </button>
                </div>

                <!-- Webhook do app próprio -->
                <div v-if="current?.has_app" class="border rounded p-2 mt-3" data-test="clinic-webhook">
                    <div class="fw-semibold small mb-1">
                        {{ t.webhook.title }}
                        <span v-if="current.webhook_ok" class="badge badge-soft-success rounded ms-1">{{
                            t.webhook.ok
                        }}</span>
                        <span v-else class="badge badge-soft-warning rounded ms-1">{{ t.webhook.pending }}</span>
                    </div>
                    <p class="text-muted mb-2 small">{{ t.webhook.hint }}</p>
                    <div v-if="webhookWarn" class="alert alert-warning py-1 small mb-2" role="alert">
                        {{ t.webhook.warn_failed }}
                    </div>
                    <code class="wa-code d-block border rounded p-2 text-break mb-2">{{ current.webhook_url }}</code>
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        :disabled="saving"
                        data-test="clinic-register-webhook"
                        @click="saveClinic({ register_webhook: true })"
                    >
                        <i class="ti ti-rotate me-1" aria-hidden="true"></i>{{ t.webhook.register }}
                    </button>
                </div>
            </fieldset>

            <div v-if="firstError" class="text-danger small mt-2" role="alert">{{ firstError }}</div>
        </form>

        <template #footer>
            <span
                v-if="savedFlash"
                class="badge bg-success-subtle text-success me-auto align-self-center"
                role="status"
                data-test="clinic-saved"
                >{{ savedFlash }}</span
            >
            <button type="button" class="btn btn-light" @click="emit('close')">{{ t.close }}</button>
            <button
                type="submit"
                form="wa-clinic-form"
                class="btn btn-primary"
                :disabled="saving"
                data-test="clinic-save"
            >
                <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ t.save }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.wa-hours {
    max-width: 160px;
}
.wa-phone {
    max-width: 200px;
}
.wa-code {
    font-size: 0.7rem;
    background: var(--bs-tertiary-bg);
}
[data-bs-theme='dark'] .wa-code {
    background: var(--bs-secondary-bg);
}
</style>
