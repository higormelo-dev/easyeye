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
    workflow_examples: { type: Array, default: () => [] },
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
    markup_by_workflow: { ...props.settings.markup_by_workflow },
    minimum_charge_cents_default: props.settings.minimum_charge_cents_default,
    minimum_charge_cents_by_workflow: { ...props.settings.minimum_charge_cents_by_workflow },
    custom_min_cents: props.settings.custom_min_cents,
    custom_max_cents: props.settings.custom_max_cents,
    reason: '',
});
const rateForm = useForm({ rate_brl_per_usd: props.rate.rate, effective_at: '', note: '', reason: '' });
const fetchForm = useForm({ reason: '' });
const forms = { settings: settingsForm, rate: rateForm, fetch: fetchForm };
const confirmation = ref(null);
const pendingPayload = ref(null);
const saving = ref(false);
const message = ref('');
const examplesStale = ref(false);
const pageRef = ref(null);
const modalForm = computed(() => forms[confirmation.value] ?? settingsForm);
const limits = computed(() => currentSettings.value.limits);
const workflows = computed(() => Object.keys(props.t.workflows ?? {}));
const internalWorkflows = computed(() => currentSettings.value.internal_workflows ?? []);
const fetchDisabled = computed(() => currentRate.value.fetched_today && !currentRate.value.is_fallback);
const rateAge = computed(() => {
    const days = currentRate.value.age_days;
    if (days === null || days === undefined) return '—';
    if (days === 0) return props.t.today;
    if (days === 1) return props.t.age_one_day;
    return (props.t.age_days ?? '').replace(':count', days);
});
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
const modalTitle = computed(
    () =>
        ({ settings: props.t.confirm_settings, rate: props.t.confirm_rate, fetch: props.t.fetch_confirm })[
            confirmation.value
        ],
);

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

function usesDefaultMarkup(workflow) {
    return !(workflow in settingsForm.markup_by_workflow);
}

function effectiveMarkup(workflow) {
    return usesDefaultMarkup(workflow) ? settingsForm.markup_multiplier : settingsForm.markup_by_workflow[workflow];
}

function toggleDefaultMarkup(workflow, useDefault) {
    if (useDefault) {
        delete settingsForm.markup_by_workflow[workflow];
    } else {
        settingsForm.markup_by_workflow[workflow] = settingsForm.markup_multiplier;
    }
}

function prepare(kind) {
    if (saving.value) return;
    const form = forms[kind];
    form.clearErrors();
    message.value = '';
    const payload = form.data();
    if (kind === 'settings') {
        payload.minimum_charge_cents_by_workflow = { ...payload.minimum_charge_cents_by_workflow };
        payload.markup_by_workflow = { ...payload.markup_by_workflow };
    } else if (kind === 'rate') {
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
    pageRef.value?.querySelector(`[data-test="${kind}-form"] [aria-invalid="true"]:not(:disabled)`)?.focus();
}

function applyRate(json) {
    currentRate.value = json.rate;
    if (json.history_first) {
        historyRows.value = [
            json.history_first,
            ...historyRows.value.filter((row) => row.id !== json.history_first.id),
        ];
    }
}

async function save() {
    if (saving.value || !confirmation.value) return;
    const kind = confirmation.value;
    const form = forms[kind];
    const reason = form.reason.trim();
    form.clearErrors();
    if (reason.length < 10 || reason.length > 500) {
        form.setError('reason', props.t.reason_invalid);
        return;
    }
    saving.value = true;
    try {
        // These endpoints return JSON; useForm owns data/errors, fetch transports the JSON response.
        const url = {
            settings: props.urls.settings,
            rate: props.urls.exchange_rates,
            fetch: props.urls.exchange_rates_fetch,
        }[kind];
        const response = await fetch(url, {
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
                        typeof json.settings[key] === 'object' ? { ...json.settings[key] } : json.settings[key];
                });
        } else {
            applyRate(json);
            rateForm.note = '';
            rateForm.effective_at = '';
        }
        form.reason = '';
        confirmation.value = null;
        message.value = json.message;
        examplesStale.value = true;
        router.reload({
            only: ['examples', 'workflow_examples', 'packages', 'history', 'rate'],
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
                                <fieldset
                                    class="mt-3"
                                    :class="{
                                        'border border-danger rounded p-2': settingsForm.errors.markup_by_workflow,
                                    }"
                                    aria-describedby="markup-workflow-hint markup-workflow-error"
                                    data-test="markup-fieldset"
                                >
                                    <legend class="fs-6">{{ t.markup_workflow_title }}</legend>
                                    <p id="markup-workflow-hint" class="form-text">{{ t.markup_workflow_hint }}</p>
                                    <div
                                        v-for="(workflow, index) in workflows"
                                        :key="workflow"
                                        class="markup-row mb-2"
                                        data-test="markup-row"
                                    >
                                        <label :for="`pricing-markup-${index}`" class="form-label mb-1">
                                            {{ t.workflows[workflow] }}
                                            <span
                                                v-if="internalWorkflows.includes(workflow)"
                                                class="badge badge-soft-secondary ms-1"
                                                >{{ t.internal_not_charged }}</span
                                            >
                                        </label>
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <div class="form-check mb-0">
                                                <input
                                                    :id="`pricing-markup-default-${index}`"
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    :checked="usesDefaultMarkup(workflow)"
                                                    @change="toggleDefaultMarkup(workflow, $event.target.checked)"
                                                />
                                                <label
                                                    class="form-check-label"
                                                    :for="`pricing-markup-default-${index}`"
                                                    >{{ t.markup_use_default }}</label
                                                >
                                            </div>
                                            <input
                                                :id="`pricing-markup-${index}`"
                                                type="number"
                                                class="form-control markup-input"
                                                :class="{ 'is-invalid': settingsForm.errors.markup_by_workflow }"
                                                :value="effectiveMarkup(workflow)"
                                                :disabled="usesDefaultMarkup(workflow)"
                                                :min="limits.markup_min"
                                                :max="limits.markup_max"
                                                step="0.01"
                                                :required="!usesDefaultMarkup(workflow)"
                                                :aria-invalid="!!settingsForm.errors.markup_by_workflow"
                                                aria-describedby="markup-workflow-hint markup-workflow-error"
                                                @input="
                                                    settingsForm.markup_by_workflow[workflow] = Number(
                                                        $event.target.value,
                                                    )
                                                "
                                            />
                                            <span class="small text-muted"
                                                >{{ t.markup_effective }}:
                                                {{ quantity(effectiveMarkup(workflow), 2) }}×</span
                                            >
                                        </div>
                                    </div>
                                    <p id="markup-workflow-error" class="text-danger small" role="alert">
                                        {{ settingsForm.errors.markup_by_workflow }}
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
                        <div class="card-body" data-test="rate-card">
                            <dl class="row">
                                <dt class="col-sm-5">{{ t.current_rate }}</dt>
                                <dd class="col-sm-7" data-test="current-rate">
                                    R$ {{ quantity(currentRate.rate, 6) }} / US$ 1
                                </dd>
                                <dt class="col-sm-5">{{ t.source }}</dt>
                                <dd class="col-sm-7">
                                    {{ t.sources[currentRate.source] ?? currentRate.source }}
                                    <span
                                        v-if="currentRate.is_automatic"
                                        class="badge badge-soft-info ms-1"
                                        data-test="badge-automatic"
                                        >{{ t.automatic }}</span
                                    >
                                    <span
                                        v-if="currentRate.is_manual_override"
                                        class="badge badge-soft-warning ms-1"
                                        data-test="badge-manual-override"
                                        >{{ t.manual_override }}</span
                                    >
                                </dd>
                                <dt class="col-sm-5">{{ t.effective_at }}</dt>
                                <dd class="col-sm-7">{{ dateTime(currentRate.effective_at) }} ({{ rateAge }})</dd>
                                <template v-if="currentRate.fetched_at">
                                    <dt class="col-sm-5">{{ t.fetched_at }}</dt>
                                    <dd class="col-sm-7">{{ dateTime(currentRate.fetched_at) }}</dd>
                                </template>
                                <dt class="col-sm-5">{{ t.recorded_by }}</dt>
                                <dd class="col-sm-7">{{ currentRate.recorded_by_name || '—' }}</dd>
                            </dl>
                            <p v-if="currentRate.note" class="text-break">{{ currentRate.note }}</p>
                            <div class="mb-3" data-test="fetch-form">
                                <div
                                    v-if="fetchForm.errors._form"
                                    class="alert alert-danger"
                                    role="alert"
                                    data-test="fetch-error"
                                >
                                    {{ fetchForm.errors._form }}
                                </div>
                                <button
                                    type="button"
                                    class="btn btn-outline-primary"
                                    :disabled="saving || fetchDisabled"
                                    data-test="fetch-now"
                                    @click="prepare('fetch')"
                                >
                                    {{ t.fetch_now }}
                                </button>
                                <p v-if="fetchDisabled" class="form-text mb-0">{{ t.fetch_unavailable }}</p>
                                <p v-else class="form-text mb-0">{{ t.fetch_hint }}</p>
                            </div>
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
                            {{ quantity(example.exchange_rate_brl_usd ?? currentRate.rate, 6) }} × {{ t.markup }}
                            {{ quantity(example.markup_multiplier ?? currentSettings.markup_multiplier, 2) }} =
                            {{ example.charge_formatted }}
                            <span v-if="example.minimum_applied" class="badge badge-soft-info ms-2">{{
                                t.minimum_applied
                            }}</span>
                        </li>
                    </ul>
                </div>
            </section>
            <section class="card mb-3" aria-labelledby="pricing-workflow-examples-title">
                <div class="card-header">
                    <h2 id="pricing-workflow-examples-title" class="h6 mb-0">{{ t.workflow_examples_title }}</h2>
                </div>
                <div class="card-body">
                    <p>{{ t.workflow_examples_hint }}</p>
                    <p v-if="examplesStale" role="status">{{ t.examples_refresh }}</p>
                    <div v-else class="table-responsive">
                        <table class="table align-middle mb-0">
                            <caption class="visually-hidden">
                                {{
                                    t.workflow_examples_title
                                }}
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ t.action }}</th>
                                    <th scope="col">{{ t.provider_cost }}</th>
                                    <th scope="col">{{ t.exchange_rate }}</th>
                                    <th scope="col">{{ t.markup }}</th>
                                    <th scope="col">{{ t.price }}</th>
                                    <th scope="col">{{ t.minimum }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in workflow_examples" :key="row.workflow" data-test="workflow-example">
                                    <th scope="row" class="fw-normal">
                                        {{ row.workflow_label }}
                                        <span v-if="row.is_internal" class="badge badge-soft-secondary ms-1">{{
                                            t.internal_not_charged
                                        }}</span>
                                    </th>
                                    <td>US$ {{ quantity(row.raw_cost_usd, 2) }}</td>
                                    <td>{{ quantity(row.exchange_rate_brl_usd, 6) }}</td>
                                    <td>{{ quantity(row.markup_multiplier, 2) }}×</td>
                                    <td>{{ row.charge_formatted }}</td>
                                    <td>
                                        {{ moneyCents(row.minimum_charge_cents) }}
                                        <span v-if="row.minimum_applied" class="badge badge-soft-info ms-1">{{
                                            t.minimum_applied
                                        }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
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
                                    <th scope="col">{{ t.fetched_at }}</th>
                                    <th scope="col">{{ t.recorded_by }}</th>
                                    <th scope="col">{{ t.note }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in historyRows" :key="row.id" data-test="history-row">
                                    <td>{{ dateTime(row.effective_at) }}</td>
                                    <td>{{ quantity(row.rate_brl_per_usd, 6) }}</td>
                                    <td>{{ t.sources[row.source] ?? row.source }}</td>
                                    <td>{{ row.fetched_at ? dateTime(row.fetched_at) : '—' }}</td>
                                    <td>{{ row.recorded_by_name || '—' }}</td>
                                    <td class="text-break">{{ row.note || '—' }}</td>
                                </tr>
                                <tr v-if="!historyRows.length">
                                    <td colspan="6">{{ t.history_empty }}</td>
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
                ><h2 class="h5 mb-0">{{ modalTitle }}</h2></template
            >
            <p>{{ confirmation === 'fetch' ? t.fetch_hint : t.confirm_hint }}</p>
            <dl v-if="confirmation === 'settings'">
                <template v-for="field in settingsFields" :key="field.key"
                    ><dt>{{ t.fields[field.key] }}</dt>
                    <dd>{{ pendingPayload[field.key] }}</dd></template
                >
                <template v-for="(value, workflow) in pendingPayload.minimum_charge_cents_by_workflow" :key="workflow"
                    ><dt>{{ t.workflows[workflow] ?? workflow }} ({{ t.cents }})</dt>
                    <dd>{{ value }}</dd></template
                >
                <dt>{{ t.markup_workflow_title }}</dt>
                <dd>
                    <ul class="list-unstyled mb-0">
                        <li v-for="(value, workflow) in pendingPayload.markup_by_workflow" :key="workflow">
                            {{ t.workflows[workflow] ?? workflow }}: {{ value }}×
                        </li>
                        <li>{{ t.markup_global_default }}: {{ pendingPayload.markup_multiplier }}×</li>
                    </ul>
                </dd>
            </dl>
            <dl v-else-if="confirmation === 'rate'">
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

.markup-input {
    max-width: 8rem;
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
