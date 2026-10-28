<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import RatePanel from './Partials/RatePanel.vue';
import BillingPanel from './Partials/BillingPanel.vue';
import InternalPanel from './Partials/InternalPanel.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

const props = defineProps({
    rate: { type: Object, required: true },
    settings: { type: Object, required: true },
    packages: { type: Array, required: true },
    examples: { type: Array, required: true },
    workflow_examples: { type: Array, default: () => [] },
    workflow_usage: { type: Object, default: () => ({ window_days: 30, rows: {} }) },
    history: { type: Object, required: true },
    t: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { dateTime, moneyCents } = useLocaleFormat();
const currentRate = ref({ ...props.rate });
const tab = ref(props.rate.is_fallback ? 'rate' : 'billing');
const currentSettings = ref({ ...props.settings });
const historyRows = ref([...props.history.data]);
// Editing uses reais; server settings, limits and pendingPayload remain integer cents.
const MONEY_KEYS = ['minimum_charge_cents_default', 'custom_min_cents', 'custom_max_cents'];
const toCents = (reais) => Math.round(Number(reais ?? 0) * 100);
const toReais = (cents) => (cents == null ? null : cents / 100);
const mapMoney = (values, convert) =>
    Object.fromEntries(Object.entries(values).map(([key, value]) => [key, convert(value)]));
const settingsForm = useForm({
    markup_multiplier: props.settings.markup_multiplier,
    markup_by_workflow: { ...props.settings.markup_by_workflow },
    minimum_charge_cents_default: toReais(props.settings.minimum_charge_cents_default),
    minimum_charge_cents_by_workflow: mapMoney(props.settings.minimum_charge_cents_by_workflow, toReais),
    custom_min_cents: toReais(props.settings.custom_min_cents),
    custom_max_cents: toReais(props.settings.custom_max_cents),
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
const workflowIndexes = computed(() => Object.fromEntries(workflows.value.map((key, index) => [key, index])));
const internalWorkflowKeys = computed(() => currentSettings.value.internal_workflows ?? []);
const billedWorkflows = computed(() =>
    workflows.value
        .filter((key) => !internalWorkflowKeys.value.includes(key))
        .map((key) => ({ key, index: workflowIndexes.value[key] })),
);
const internalWorkflows = computed(() =>
    workflows.value
        .filter((key) => internalWorkflowKeys.value.includes(key))
        .map((key) => ({ key, index: workflowIndexes.value[key] })),
);
const workflowExamplesByWorkflow = computed(() =>
    Object.fromEntries(props.workflow_examples.map((row) => [row.workflow, row])),
);
const usageByWorkflow = computed(() => props.workflow_usage.rows ?? {});
const topUsageWorkflow = computed(() =>
    workflows.value.reduce((top, key) => {
        const usage = usageByWorkflow.value[key];
        return usage?.runs > (top?.runs ?? 0) ? { key, ...usage } : top;
    }, null),
);
const settingsFields = computed(() => [
    {
        key: 'markup_multiplier',
        group: 'global',
        min: limits.value.markup_min,
        max: limits.value.markup_max,
        step: '0.01',
    },
    {
        key: 'minimum_charge_cents_default',
        group: 'global',
        min: limits.value.minimum_min,
        max: limits.value.minimum_max,
        step: 1,
    },
    {
        key: 'custom_min_cents',
        group: 'package',
        min: currentSettings.value.custom_step_cents,
        max: limits.value.custom_hard_max_cents,
        step: currentSettings.value.custom_step_cents,
    },
    {
        key: 'custom_max_cents',
        group: 'package',
        min: currentSettings.value.custom_step_cents,
        max: limits.value.custom_hard_max_cents,
        step: currentSettings.value.custom_step_cents,
    },
]);
const globalFields = computed(() => settingsFields.value.filter((field) => field.group === 'global'));
const packageFields = computed(() => settingsFields.value.filter((field) => field.group === 'package'));
const modalSettingsRows = computed(() =>
    settingsFields.value.map(({ key }) => ({
        key,
        label: props.t.fields[key],
        text: MONEY_KEYS.includes(key) ? moneyCents(pendingPayload.value?.[key]) : pendingPayload.value?.[key],
    })),
);
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

function prepare(kind) {
    if (saving.value) return;
    const form = forms[kind];
    form.clearErrors();
    message.value = '';
    const payload = form.data();
    if (kind === 'settings') {
        payload.minimum_charge_cents_by_workflow = mapMoney(payload.minimum_charge_cents_by_workflow, toCents);
        MONEY_KEYS.forEach((key) => {
            payload[key] = toCents(payload[key]);
        });
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

function tabForSettingsError(form) {
    const fields = Object.keys(form.errors);
    return fields.some(
        (key) =>
            !key.startsWith('markup_by_workflow.') ||
            !internalWorkflowKeys.value.includes(key.slice('markup_by_workflow.'.length)),
    )
        ? 'billing'
        : 'internal';
}

async function focusError(kind) {
    tab.value = kind === 'settings' ? tabForSettingsError(settingsForm) : 'rate';
    await nextTick();
    const activePanel = pageRef.value?.querySelector(`[data-test="tab-panel-${tab.value}"]`);
    (
        activePanel?.querySelector('input[type="number"][aria-invalid="true"]:not(:disabled)') ??
        activePanel?.querySelector('input[inputmode="decimal"][aria-invalid="true"]:not(:disabled)') ??
        activePanel?.querySelector('[aria-invalid="true"]:not(:disabled)')
    )?.focus();
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

function applySettings(settings) {
    currentSettings.value = settings;
    Object.keys(settingsForm.data())
        .filter((key) => key !== 'reason')
        .forEach((key) => {
            if (MONEY_KEYS.includes(key)) settingsForm[key] = toReais(settings[key]);
            else if (key === 'minimum_charge_cents_by_workflow') settingsForm[key] = mapMoney(settings[key], toReais);
            else settingsForm[key] = typeof settings[key] === 'object' ? { ...settings[key] } : settings[key];
        });
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
            applySettings(json.settings);
        } else {
            applyRate(json);
            if (kind === 'rate') {
                rateForm.note = '';
                rateForm.effective_at = '';
            }
        }
        form.reason = '';
        confirmation.value = null;
        message.value = json.message;
        examplesStale.value = true;
        router.reload({
            only: ['examples', 'workflow_examples', 'workflow_usage', 'packages', 'history', 'rate'],
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
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li v-for="name in ['rate', 'billing', 'internal']" :key="name" class="nav-item" role="presentation">
                    <button
                        :id="`pricing-tab-${name}`"
                        type="button"
                        class="nav-link"
                        :class="{ active: tab === name }"
                        role="tab"
                        :aria-selected="tab === name"
                        :aria-controls="`pricing-panel-${name}`"
                        :data-tab="name"
                        @click="tab = name"
                    >
                        {{ t[`tab_${name}`] }}
                    </button>
                </li>
            </ul>
            <div
                v-show="tab === 'rate'"
                id="pricing-panel-rate"
                role="tabpanel"
                aria-labelledby="pricing-tab-rate"
                data-test="tab-panel-rate"
            >
                <RatePanel
                    :t="t"
                    :current-rate="currentRate"
                    :rate-form="rateForm"
                    :fetch-form="fetchForm"
                    :history="history"
                    :history-rows="historyRows"
                    :saving="saving"
                    :active="tab === 'rate'"
                    @review="prepare"
                />
            </div>
            <form data-test="settings-form" :aria-label="t.settings_title" @submit.prevent="prepare('settings')">
                <fieldset :disabled="saving">
                    <div
                        v-show="tab === 'billing'"
                        id="pricing-panel-billing"
                        role="tabpanel"
                        aria-labelledby="pricing-tab-billing"
                        data-test="tab-panel-billing"
                    >
                        <BillingPanel
                            :t="t"
                            :form="settingsForm"
                            :global-fields="globalFields"
                            :package-fields="packageFields"
                            :custom-step-cents="currentSettings.custom_step_cents"
                            :limits="limits"
                            :billed-workflows="billedWorkflows"
                            :workflow-examples="workflowExamplesByWorkflow"
                            :workflow-usage="usageByWorkflow"
                            :usage-window-days="workflow_usage.window_days"
                            :top-usage-workflow="topUsageWorkflow"
                            :examples="examples"
                            :packages="packages"
                            :examples-stale="examplesStale"
                            :active="tab === 'billing'"
                        />
                    </div>
                    <div
                        v-show="tab === 'internal'"
                        id="pricing-panel-internal"
                        role="tabpanel"
                        aria-labelledby="pricing-tab-internal"
                        data-test="tab-panel-internal"
                    >
                        <InternalPanel
                            :t="t"
                            :form="settingsForm"
                            :limits="limits"
                            :internal-workflows="internalWorkflows"
                            :workflow-examples="workflowExamplesByWorkflow"
                            :workflow-usage="usageByWorkflow"
                            :usage-window-days="workflow_usage.window_days"
                            :examples-stale="examplesStale"
                            :active="tab === 'internal'"
                        />
                    </div>
                </fieldset>
            </form>
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
                <template v-for="field in modalSettingsRows" :key="field.key"
                    ><dt>{{ field.label }}</dt>
                    <dd>{{ field.text }}</dd></template
                >
                <template v-for="(value, workflow) in pendingPayload.minimum_charge_cents_by_workflow" :key="workflow"
                    ><dt>{{ t.workflows[workflow] ?? workflow }}</dt>
                    <dd>{{ moneyCents(value) }}</dd></template
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
.ai-pricing :deep(:is(input, textarea, button, a):focus-visible),
#pricing-reason:focus-visible,
[data-test='confirm-save']:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 3px;
}
</style>
