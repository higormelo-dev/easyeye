<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Aba "Número do EasyEye": app Gupshup GLOBAL (padrão de todas as clínicas
 * sem número próprio, do código de verificação do cadastro e dos avisos de
 * cobrança). Mesmo formulário e rotas de antes, em seções: App da Gupshup ·
 * Webhook · Teste de envio. A faixa de situação da página chama
 * verify()/registerWebhook() daqui e recebe o resultado por evento.
 */
const props = defineProps({
    global: { type: Object, default: null },
    routes: { type: Object, required: true },
    t: { type: Object, required: true },
});

const emit = defineEmits(['health', 'saving', 'testing', 'saved']);

const { number, dateTime } = useLocaleFormat();
const ui = computed(() => props.t.ui ?? {});

const form = reactive({ active: props.global?.active ?? true, app_id: '' });
const saving = ref(false);
const savedFlash = ref('');
const errors = ref({});
const testing = ref(false);
const result = ref(null);
const webhookWarn = ref(false);
const phone = ref('');
const phoneInput = ref(null);
let flashTimer = null;

// Recarregou (outra aba/salvou): o switch acompanha o valor salvo.
watch(
    () => props.global?.active,
    (value) => {
        if (value !== undefined) form.active = value;
    },
);
watch(saving, (value) => emit('saving', value));
watch(testing, (value) => emit('testing', value));

const canTest = computed(() => Boolean(form.app_id || props.global?.has_app));
const operational = computed(() => Boolean(props.global?.has_app && props.global?.active));

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function save(extra = {}) {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    webhookWarn.value = false;
    try {
        const body = { active: form.active, ...(form.app_id ? { app_id: form.app_id } : {}), ...extra };
        const { data } = await window.axios.patch(props.routes.global_update, body, {
            headers: { 'X-CSRF-TOKEN': csrf() },
        });
        savedFlash.value = data.message;
        clearTimeout(flashTimer);
        flashTimer = setTimeout(() => (savedFlash.value = ''), 3000);
        webhookWarn.value = data.webhook_ok === false;
        form.app_id = '';
        emit('saved');
        router.reload({ only: ['global', 'clinics', 'kpis'], preserveScroll: true });
    } catch (e) {
        errors.value = e.response?.data?.errors ?? {};
    } finally {
        saving.value = false;
    }
}

function clearApp() {
    if (!props.global?.has_app) return;
    if (!window.confirm(props.t.app.clear_global_confirm)) return;
    result.value = null;
    emit('health', null);
    save({ clear_app: true });
}

async function test(withMessage = false) {
    const body = {};
    if (form.app_id) body.app_id = form.app_id;
    if (withMessage) body.phone = phone.value;

    testing.value = true;
    result.value = null;
    try {
        const { data } = await window.axios.post(props.routes.global_test, body, {
            headers: { 'X-CSRF-TOKEN': csrf() },
        });
        result.value = data;
    } catch (e) {
        result.value = { ok: false, error: e.response?.data?.error ?? props.t.connection.failed };
    } finally {
        testing.value = false;
    }
    // Só a verificação de saúde (sem telefone) vale como "saúde do app" na faixa.
    if (!withMessage) emit('health', result.value);
}

/** Usado pela faixa de situação e pelo botão "Mensagem de teste" do cabeçalho. */
function verify() {
    return test(false);
}

function registerWebhook() {
    return save({ register_webhook: true });
}

async function focusTest() {
    await nextTick();
    phoneInput.value?.focus();
}

defineExpose({ verify, registerWebhook, focusTest });
</script>

<template>
    <div class="card" data-test="wa-global-panel">
        <div class="card-header bg-transparent d-flex flex-wrap align-items-center gap-2">
            <div class="flex-grow-1 min-w-0">
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-building-broadcast-tower me-1 text-success" aria-hidden="true"></i
                    >{{ t.manager.global_title }}
                    <span
                        v-if="operational"
                        class="badge badge-soft-success rounded fs-12 fw-medium ms-1"
                        data-test="global-status"
                        >{{ t.manager.status_via_global }}</span
                    >
                    <span
                        v-else-if="global?.has_app"
                        class="badge badge-soft-warning rounded fs-12 fw-medium ms-1"
                        data-test="global-status"
                        >{{ t.manager.status_inactive }}</span
                    >
                    <span
                        v-else
                        class="badge badge-soft-secondary rounded fs-12 fw-medium ms-1"
                        data-test="global-status"
                        >{{ t.manager.status_unconfigured }}</span
                    >
                </h5>
                <p class="small text-muted mb-0">{{ t.manager.global_hint }}</p>
            </div>
            <span v-if="savedFlash" class="badge bg-success-subtle text-success" role="status">{{ savedFlash }}</span>
        </div>

        <div class="card-body">
            <!-- ── App da Gupshup ──────────────────────────────────────── -->
            <section class="wag-section" aria-labelledby="wag-app-title">
                <h6 id="wag-app-title" class="wag-section__title">
                    <i class="ti ti-brand-whatsapp me-1" aria-hidden="true"></i>{{ ui.global_section_app }}
                </h6>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold" for="g-app-id">{{ t.app.app_id }}</label>
                        <input
                            id="g-app-id"
                            v-model.trim="form.app_id"
                            type="text"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': errors.app_id }"
                            autocomplete="off"
                            :placeholder="global?.app_id ?? t.app.app_id_placeholder"
                            :aria-describedby="global?.has_app ? 'g-app-id-hint' : undefined"
                            data-test="global-app-id"
                        />
                        <div v-if="errors.app_id" class="invalid-feedback">{{ errors.app_id[0] }}</div>
                        <div v-if="global?.has_app" id="g-app-id-hint" class="form-text">{{ t.app.replace_hint }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="form-check form-switch mb-1">
                            <input
                                id="gActive"
                                v-model="form.active"
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                                data-test="global-active"
                            />
                            <label for="gActive" class="form-check-label small">{{ t.manager.global_active }}</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap mt-3">
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        :disabled="testing || !canTest"
                        :title="canTest ? undefined : t.connection.fill_first"
                        data-test="global-test"
                        @click="test(false)"
                    >
                        <span v-if="testing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        <i v-else class="ti ti-plug me-1" aria-hidden="true"></i>
                        {{ testing ? t.connection.testing : t.connection.test }}
                    </button>
                    <span aria-live="polite">
                        <span v-if="result?.ok && result.sent" class="badge bg-success-subtle text-success">{{
                            t.connection.sent
                        }}</span>
                        <span
                            v-else-if="result?.ok && result.healthy"
                            class="badge bg-success-subtle text-success"
                            data-test="global-healthy"
                            >{{ t.connection.healthy }}</span
                        >
                        <span v-else-if="result?.ok" class="badge bg-warning-subtle text-warning-emphasis">{{
                            t.connection.unhealthy
                        }}</span>
                        <span v-else-if="result" class="badge bg-danger-subtle text-danger text-wrap">{{
                            result.error
                        }}</span>
                    </span>

                    <button
                        v-if="global?.has_app"
                        type="button"
                        class="btn btn-outline-danger btn-sm ms-auto"
                        :disabled="saving"
                        data-test="global-clear-app"
                        @click="clearApp"
                    >
                        <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ t.app.clear }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :class="{ 'ms-auto': !global?.has_app }"
                        :disabled="saving"
                        data-test="global-save"
                        @click="save()"
                    >
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        <i v-else class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ t.save }}
                    </button>
                </div>
            </section>

            <!-- ── Webhook ─────────────────────────────────────────────── -->
            <section class="wag-section" aria-labelledby="wag-webhook-title" data-test="global-webhook">
                <h6 id="wag-webhook-title" class="wag-section__title d-flex align-items-center flex-wrap gap-2">
                    <span><i class="ti ti-webhook me-1" aria-hidden="true"></i>{{ ui.global_section_webhook }}</span>
                    <template v-if="global?.has_app">
                        <span v-if="global.webhook_ok" class="badge badge-soft-success rounded wag-badge">{{
                            t.webhook.ok
                        }}</span>
                        <span v-else class="badge badge-soft-warning rounded wag-badge">{{ t.webhook.pending }}</span>
                    </template>
                    <span v-if="global?.opt_outs" class="badge badge-soft-secondary rounded wag-badge"
                        >{{ number(global.opt_outs) }} {{ t.manager.opt_outs }}</span
                    >
                </h6>
                <template v-if="global?.has_app">
                    <p class="text-muted small mb-2">
                        {{ t.webhook.hint }}
                        <span v-if="global.webhook_since">({{ dateTime(global.webhook_since) }})</span>
                    </p>
                    <div v-if="webhookWarn" class="alert alert-warning py-1 small mb-2" role="alert">
                        {{ t.webhook.warn_failed }}
                    </div>
                    <code class="wag-code d-block border rounded p-2 text-break mb-2">{{ global.webhook_url }}</code>
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        :disabled="saving"
                        data-test="global-register-webhook"
                        @click="registerWebhook"
                    >
                        <i class="ti ti-rotate me-1" aria-hidden="true"></i>{{ t.webhook.register }}
                    </button>
                </template>
                <p v-else class="small text-muted mb-0">{{ ui.global_save_first }}</p>
            </section>

            <!-- ── Teste de envio ──────────────────────────────────────── -->
            <section class="wag-section mb-0" aria-labelledby="wag-test-title">
                <h6 id="wag-test-title" class="wag-section__title">
                    <i class="ti ti-send me-1" aria-hidden="true"></i>{{ ui.global_section_test }}
                </h6>
                <p class="text-muted small mb-2">{{ canTest ? ui.global_test_hint : ui.global_save_first }}</p>
                <div v-if="canTest" class="d-flex align-items-end gap-2 flex-wrap">
                    <div>
                        <label class="form-label small mb-1" for="g-test-phone">{{ t.connection.test_phone }}</label>
                        <input
                            id="g-test-phone"
                            ref="phoneInput"
                            v-model.trim="phone"
                            v-mask="'phone'"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            class="form-control form-control-sm wag-phone"
                        />
                    </div>
                    <button
                        type="button"
                        class="btn btn-outline-success btn-sm"
                        :disabled="testing || !phone"
                        data-test="global-send-test"
                        @click="test(true)"
                    >
                        <i class="ti ti-brand-whatsapp me-1" aria-hidden="true"></i>{{ t.connection.send_test }}
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.wag-section {
    margin-bottom: 1.5rem;
}
.wag-section__title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.75rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.wag-badge {
    text-transform: none;
    letter-spacing: normal;
    font-weight: 500;
}
.wag-code {
    font-size: 0.7rem;
    background: var(--bs-tertiary-bg);
}
.wag-phone {
    max-width: 200px;
}
[data-bs-theme='dark'] .wag-code {
    background: var(--bs-secondary-bg);
}
</style>
