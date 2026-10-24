<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

const props = defineProps({
    rate: { type: Object, required: true },
    settings: { type: Object, required: true },
    packages: { type: Array, required: true },
    examples: { type: Array, required: true },
    history: { type: Object, required: true },
    t: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { moneyCents, quantity, dateTime } = useLocaleFormat();
const currentRate = ref({ ...props.rate });
const currentSettings = ref({ ...props.settings });
const historyRows = ref([...props.history.data]);
const settingsForm = useForm({
    markup_multiplier: props.settings.markup_multiplier,
    minimum_charge_cents_default: props.settings.minimum_charge_cents_default,
    minimum_charge_cents_by_workflow: { ...props.settings.minimum_charge_cents_by_workflow },
    custom_min_cents: props.settings.custom_min_cents,
    custom_max_cents: props.settings.custom_max_cents,
    reason: '',
});
const rateForm = useForm({ rate_brl_per_usd: props.rate.rate, effective_at: '', note: '', reason: '' });
const confirmation = ref(null);
const pendingPayload = ref(null);
const saving = ref(false);
const message = ref('');
const examplesStale = ref(false);
const pageRef = ref(null);
const modalForm = computed(() => (confirmation.value === 'settings' ? settingsForm : rateForm));
const limits = computed(() => currentSettings.value.limits);
const settingsFields = computed(() => [
    { key: 'markup_multiplier', min: limits.value.markup_min, max: limits.value.markup_max, step: '0.01' },
    { key: 'minimum_charge_cents_default', min: limits.value.minimum_min, max: limits.value.minimum_max, step: 1 },
    {
        key: 'custom_min_cents',
        min: currentSettings.value.custom_step_cents,
        max: limits.value.custom_hard_max_cents,
        step: currentSettings.value.custom_step_cents,
    },
    {
        key: 'custom_max_cents',
        min: currentSettings.value.custom_step_cents,
        max: limits.value.custom_hard_max_cents,
        step: currentSettings.value.custom_step_cents,
    },
]);
const breadcrumbs = computed(() => [
    { label: props.t.breadcrumb_home, url: route('manager.dashboard') },
    { label: props.t.title, url: '#', active: true },
]);

watch(
    () => props.rate,
    (value) => {
        currentRate.value = { ...value };
    },
);
watch(
    () => props.history,
    (value) => {
        historyRows.value = [...value.data];
    },
);

function prepare(kind) {
    if (saving.value) return;
    const form = kind === 'settings' ? settingsForm : rateForm;
    form.clearErrors();
    message.value = '';
    const payload = form.data();
    if (kind === 'settings') {
        payload.minimum_charge_cents_by_workflow = { ...payload.minimum_charge_cents_by_workflow };
    } else {
        payload.effective_at = payload.effective_at ? new Date(payload.effective_at).toISOString() : null;
        payload.note = payload.note || null;
    }
    pendingPayload.value = payload;
    confirmation.value = kind;
}

function closeConfirmation() {
    if (!saving.value) confirmation.value = null;
}

async function focusError(kind) {
    await nextTick();
    pageRef.value?.querySelector(`[data-test="${kind}-form"] [aria-invalid="true"]`)?.focus();
}

async function save() {
    if (saving.value || !confirmation.value) return;
    const kind = confirmation.value;
    const form = kind === 'settings' ? settingsForm : rateForm;
    const reason = form.reason.trim();
    form.clearErrors();
    if (reason.length < 10 || reason.length > 500) {
        form.setError('reason', props.t.reason_invalid);
        return;
    }
    saving.value = true;
    try {
        // These endpoints return JSON; useForm owns data/errors, fetch transports the JSON response.
        const response = await fetch(kind === 'settings' ? props.urls.settings : props.urls.exchange_rates, {
            method: kind === 'settings' ? 'PUT' : 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ ...pendingPayload.value, reason }),
        });
        const json = await response.json();
        if (!response.ok) {
            const errors = Object.fromEntries(
                Object.entries(json.errors ?? {}).map(([key, value]) => [key, [].concat(value)[0]]),
            );
            if (json.field) {
                const fields = json.field === 'custom_limits' ? ['custom_min_cents', 'custom_max_cents'] : [json.field];
                fields.forEach((key) => {
                    errors[key] = json.message;
                });
            }
            form.setError(Object.keys(errors).length ? errors : { _form: json.message || props.t.save_failed });
            if (!errors.reason) {
                confirmation.value = null;
                saving.value = false;
                await focusError(kind);
            }
            return;
        }
        if (kind === 'settings') {
            currentSettings.value = json.settings;
            Object.keys(settingsForm.data())
                .filter((key) => key !== 'reason')
                .forEach((key) => {
                    settingsForm[key] =
                        key === 'minimum_charge_cents_by_workflow' ? { ...json.settings[key] } : json.settings[key];
                });
        } else {
            currentRate.value = json.rate;
            historyRows.value = [
                json.history_first,
                ...historyRows.value.filter((row) => row.id !== json.history_first.id),
            ];
            rateForm.note = '';
            rateForm.effective_at = '';
        }
        form.reason = '';
        confirmation.value = null;
        message.value = json.message;
        examplesStale.value = true;
        router.reload({
            only: ['examples', 'packages', 'history', 'rate'],
            onSuccess: () => {
                examplesStale.value = false;
            },
        });
    } catch {
        form.setError('_form', props.t.save_failed);
        confirmation.value = null;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div ref="pageRef" class="ai-pricing" data-ee-density="default">
            <PageHeader class="pricing-header" :title="t.title" :subtitle="t.subtitle" />
            <div v-if="message" class="alert alert-success" role="status">{{ message }}</div>
            <div v-if="currentRate.is_fallback" class="alert alert-warning" role="alert" data-test="fallback">
                {{ t.fallback_warning }}
            </div>
            <div class="row g-3 mb-3">
                <section class="col-12 col-xl-6" aria-labelledby="pricing-settings-title">
                    <div class="card h-100 mb-0">
                        <div class="card-header">
                            <h2 id="pricing-settings-title" class="h6 mb-0">{{ t.settings_title }}</h2>
                        </div>
                        <form class="card-body" data-test="settings-form" @submit.prevent="prepare('settings')">
                            <div v-if="settingsForm.errors._form" class="alert alert-danger" role="alert">
                                {{ settingsForm.errors._form }}
                            </div>
                            <fieldset :disabled="saving">
                                <div class="row g-3">
                                    <div v-for="field in settingsFields" :key="field.key" class="col-12 col-sm-6">
                                        <label :for="`pricing-${field.key}`" class="form-label">{{
                                            t.fields[field.key]
                                        }}</label>
                                        <input
                                            :id="`pricing-${field.key}`"
                                            v-model.number="settingsForm[field.key]"
                                            type="number"
                                            class="form-control"
                                            :class="{ 'is-invalid': settingsForm.errors[field.key] }"
                                            :min="field.min"
                                            :max="field.max"
                                            :step="field.step"
                                            required
                                            :aria-invalid="!!settingsForm.errors[field.key]"
                                            :aria-describedby="`pricing-${field.key}-hint pricing-${field.key}-error`"
                                        />
                                        <div :id="`pricing-${field.key}-hint`" class="form-text">
                                            {{ t.hints[field.key] }}
                                        </div>
                                        <div :id="`pricing-${field.key}-error`" class="invalid-feedback">
                                            {{ settingsForm.errors[field.key] }}
                                        </div>
                                    </div>
                                </div>
                                <fieldset class="mt-3" aria-describedby="workflow-hint workflow-error">
                                    <legend class="fs-6">{{ t.workflow_title }}</legend>
                                    <p id="workflow-hint" class="form-text">{{ t.workflow_hint }}</p>
                                    <div
                                        v-for="(
                                            value, workflow, index
                                        ) in settingsForm.minimum_charge_cents_by_workflow"
                                        :key="workflow"
                                        class="mb-2"
                                    >
                                        <label :for="`pricing-workflow-${index}`" class="form-label">{{
                                            t.workflows[workflow] ?? workflow
                                        }}</label>
                                        <input
                                            :id="`pricing-workflow-${index}`"
                                            v-model.number="settingsForm.minimum_charge_cents_by_workflow[workflow]"
                                            type="number"
                                            class="form-control"
                                            :min="limits.minimum_min"
                                            :max="limits.minimum_max"
                                            step="1"
                                            required
                                            :aria-invalid="
                                                !!(
                                                    settingsForm.errors.minimum_charge_cents_by_workflow ||
                                                    settingsForm.errors[`minimum_charge_cents_by_workflow.${workflow}`]
                                                )
                                            "
                                            aria-describedby="workflow-hint workflow-error"
                                        />
                                        <div
                                            v-if="settingsForm.errors[`minimum_charge_cents_by_workflow.${workflow}`]"
                                            class="text-danger small"
                                            role="alert"
                                        >
                                            {{ settingsForm.errors[`minimum_charge_cents_by_workflow.${workflow}`] }}
                                        </div>
                                    </div>
                                    <p id="workflow-error" class="text-danger small" role="alert">
                                        {{ settingsForm.errors.minimum_charge_cents_by_workflow }}
                                    </p>
                                </fieldset>
                                <button class="btn btn-primary mt-3" type="submit" data-test="settings-review">
                                    {{ t.review_settings }}
                                </button>
                            </fieldset>
                        </form>
                    </div>
                </section>
                <section class="col-12 col-xl-6" aria-labelledby="pricing-rate-title">
                    <div class="card h-100 mb-0">
                        <div class="card-header">
                            <h2 id="pricing-rate-title" class="h6 mb-0">{{ t.rate_title }}</h2>
                        </div>
                        <div class="card-body">
                            <dl class="row">
                                <dt class="col-sm-5">{{ t.current_rate }}</dt>
                                <dd class="col-sm-7" data-test="current-rate">
                                    R$ {{ quantity(currentRate.rate, 6) }} / US$ 1
                                </dd>
                                <dt class="col-sm-5">{{ t.source }}</dt>
                                <dd class="col-sm-7">{{ t.sources[currentRate.source] ?? currentRate.source }}</dd>
                                <dt class="col-sm-5">{{ t.effective_at }}</dt>
                                <dd class="col-sm-7">{{ dateTime(currentRate.effective_at) }}</dd>
                                <dt class="col-sm-5">{{ t.recorded_by }}</dt>
                                <dd class="col-sm-7">{{ currentRate.recorded_by_name || '—' }}</dd>
                            </dl>
                            <p v-if="currentRate.note" class="text-break">{{ currentRate.note }}</p>
                            <form data-test="rate-form" @submit.prevent="prepare('rate')">
                                <div v-if="rateForm.errors._form" class="alert alert-danger" role="alert">
                                    {{ rateForm.errors._form }}
                                </div>
                                <fieldset :disabled="saving">
                                    <div class="mb-3">
                                        <label for="pricing-rate" class="form-label">{{
                                            t.fields.rate_brl_per_usd
                                        }}</label>
                                        <input
                                            id="pricing-rate"
                                            v-model.number="rateForm.rate_brl_per_usd"
                                            class="form-control"
                                            :class="{ 'is-invalid': rateForm.errors.rate_brl_per_usd }"
                                            type="number"
                                            min="1"
                                            max="20"
                                            step="0.000001"
                                            required
                                            :aria-invalid="!!rateForm.errors.rate_brl_per_usd"
                                            aria-describedby="pricing-rate-hint pricing-rate-error"
                                        />
                                        <div id="pricing-rate-hint" class="form-text">{{ t.rate_hint }}</div>
                                        <div id="pricing-rate-error" class="invalid-feedback">
                                            {{ rateForm.errors.rate_brl_per_usd }}
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="pricing-effective" class="form-label">{{ t.effective_at }}</label>
                                        <input
                                            id="pricing-effective"
                                            v-model="rateForm.effective_at"
                                            class="form-control"
                                            type="datetime-local"
                                            :aria-invalid="!!rateForm.errors.effective_at"
                                            aria-describedby="pricing-effective-hint pricing-effective-error"
                                        />
                                        <div id="pricing-effective-hint" class="form-text">{{ t.effective_hint }}</div>
                                        <div id="pricing-effective-error" class="text-danger small">
                                            {{ rateForm.errors.effective_at }}
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="pricing-note" class="form-label">{{ t.note }}</label>
                                        <textarea
                                            id="pricing-note"
                                            v-model="rateForm.note"
                                            class="form-control"
                                            maxlength="255"
                                            rows="2"
                                            :aria-invalid="!!rateForm.errors.note"
                                            aria-describedby="pricing-note-error"
                                        ></textarea>
                                        <div id="pricing-note-error" class="text-danger small">
                                            {{ rateForm.errors.note }}
                                        </div>
                                    </div>
                                    <button class="btn btn-primary" type="submit" data-test="rate-review">
                                        {{ t.review_rate }}
                                    </button>
                                </fieldset>
                            </form>
                        </div>
                    </div>
                </section>
            </div>
            <section class="card mb-3" aria-labelledby="pricing-examples-title">
                <div class="card-header">
                    <h2 id="pricing-examples-title" class="h6 mb-0">{{ t.examples_title }}</h2>
                </div>
                <div class="card-body">
                    <p>{{ t.examples_hint }}</p>
                    <p v-if="examplesStale" role="status">{{ t.examples_refresh }}</p>
                    <ul v-else class="list-unstyled mb-0">
                        <li v-for="example in examples" :key="example.raw_cost_usd" class="mb-2" data-test="example">
                            {{ t.provider_cost }} US$ {{ quantity(example.raw_cost_usd, 6) }} × {{ t.exchange_rate }}
                            {{ quantity(currentRate.rate, 6) }} × {{ t.markup }}
                            {{ quantity(currentSettings.markup_multiplier, 2) }} = {{ example.charge_formatted }}
                            <span v-if="example.minimum_applied" class="badge badge-soft-info ms-2">{{
                                t.minimum_applied
                            }}</span>
                        </li>
                    </ul>
                </div>
            </section>
            <section class="card mb-3" aria-labelledby="pricing-packages-title">
                <div class="card-header">
                    <h2 id="pricing-packages-title" class="h6 mb-0">{{ t.packages_title }}</h2>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li v-for="pack in packages" :key="pack.code" class="mb-2">
                            {{ pack.name ?? pack.label ?? pack.code }}:
                            <template v-if="pack.custom"
                                >{{ moneyCents(currentSettings.custom_min_cents) }} –
                                {{ moneyCents(currentSettings.custom_max_cents) }}</template
                            >
                            <template v-else>{{ moneyCents(pack.value_cents) }}</template>
                            <span v-if="pack.featured" class="badge badge-soft-primary ms-2">{{ t.featured }}</span>
                        </li>
                    </ul>
                </div>
            </section>
            <section class="card mb-0" aria-labelledby="pricing-history-title">
                <div class="card-header">
                    <h2 id="pricing-history-title" class="h6 mb-0">{{ t.history_title }}</h2>
                </div>
                <div class="card-body">
                    <p>{{ t.history_hint }}</p>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <caption class="visually-hidden">
                                {{
                                    t.history_title
                                }}
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ t.effective_at }}</th>
                                    <th scope="col">{{ t.exchange_rate }}</th>
                                    <th scope="col">{{ t.source }}</th>
                                    <th scope="col">{{ t.recorded_by }}</th>
                                    <th scope="col">{{ t.note }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in historyRows" :key="row.id" data-test="history-row">
                                    <td>{{ dateTime(row.effective_at) }}</td>
                                    <td>{{ quantity(row.rate_brl_per_usd, 6) }}</td>
                                    <td>{{ t.sources[row.source] ?? row.source }}</td>
                                    <td>{{ row.recorded_by_name || '—' }}</td>
                                    <td class="text-break">{{ row.note || '—' }}</td>
                                </tr>
                                <tr v-if="!historyRows.length">
                                    <td colspan="5">{{ t.history_empty }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <TablePagination
                        :data="history"
                        :showing-from="t.showing_from"
                        :showing-of="t.showing_of"
                        :showing-suffix="t.records"
                        :aria-label="t.pagination"
                        :previous-label="t.previous"
                        :next-label="t.next"
                    />
                </div>
            </section>
        </div>
        <CenteredModal
            :open="!!confirmation"
            size="md"
            density="default"
            :close-label="t.close"
            @close="closeConfirmation"
        >
            <template #header
                ><h2 class="h5 mb-0">
                    {{ confirmation === 'settings' ? t.confirm_settings : t.confirm_rate }}
                </h2></template
            >
            <p>{{ t.confirm_hint }}</p>
            <dl v-if="confirmation === 'settings'">
                <template v-for="field in settingsFields" :key="field.key"
                    ><dt>{{ t.fields[field.key] }}</dt>
                    <dd>{{ pendingPayload[field.key] }}</dd></template
                >
                <template v-for="(value, workflow) in pendingPayload.minimum_charge_cents_by_workflow" :key="workflow"
                    ><dt>{{ t.workflows[workflow] ?? workflow }} ({{ t.cents }})</dt>
                    <dd>{{ value }}</dd></template
                >
            </dl>
            <dl v-else-if="confirmation">
                <dt>{{ t.fields.rate_brl_per_usd }}</dt>
                <dd>{{ pendingPayload.rate_brl_per_usd }}</dd>
                <dt>{{ t.effective_at }}</dt>
                <dd>{{ pendingPayload.effective_at ? dateTime(pendingPayload.effective_at) : t.now }}</dd>
                <dt>{{ t.note }}</dt>
                <dd>{{ pendingPayload.note || '—' }}</dd>
            </dl>
            <form id="pricing-confirm-form" @submit.prevent="save">
                <label for="pricing-reason" class="form-label">{{ t.reason }}</label>
                <textarea
                    id="pricing-reason"
                    v-model="modalForm.reason"
                    class="form-control"
                    :class="{ 'is-invalid': modalForm.errors.reason }"
                    rows="3"
                    required
                    minlength="10"
                    maxlength="500"
                    :disabled="saving"
                    :aria-invalid="!!modalForm.errors.reason"
                    aria-describedby="pricing-reason-hint pricing-reason-error"
                ></textarea>
                <div id="pricing-reason-hint" class="form-text">{{ t.reason_hint }}</div>
                <div id="pricing-reason-error" class="invalid-feedback" role="alert">{{ modalForm.errors.reason }}</div>
            </form>
            <template #footer
                ><button type="button" class="btn btn-light" :disabled="saving" @click="closeConfirmation">
                    {{ t.cancel }}</button
                ><button
                    type="submit"
                    form="pricing-confirm-form"
                    class="btn btn-primary"
                    :disabled="saving"
                    data-test="confirm-save"
                >
                    {{ saving ? t.saving : t.confirm_save }}
                </button></template
            >
        </CenteredModal>
    </AppLayout>
</template>

<style scoped>
.pricing-header :deep(.me-auto) {
    flex-wrap: wrap;
}

:is(
    .ai-pricing input,
    .ai-pricing textarea,
    .ai-pricing button,
    .ai-pricing a,
    #pricing-reason,
    [data-test='confirm-save']
):focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 3px;
}
</style>
