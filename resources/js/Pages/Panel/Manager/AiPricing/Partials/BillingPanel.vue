<script setup>
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
const props = defineProps({
    t: { type: Object, required: true },
    form: { type: Object, required: true },
    globalFields: { type: Array, required: true },
    packageFields: { type: Array, required: true },
    customStepCents: { type: Number, required: true },
    limits: { type: Object, required: true },
    billedWorkflows: { type: Array, required: true },
    workflowExamples: { type: Object, required: true },
    workflowUsage: { type: Object, required: true },
    usageWindowDays: { type: Number, required: true },
    topUsageWorkflow: { type: Object, default: null },
    examples: { type: Array, required: true },
    packages: { type: Array, required: true },
    examplesStale: { type: Boolean, default: false },
    active: { type: Boolean, default: false },
});
const form = props.form;
const { quantity, moneyCents, money } = useLocaleFormat();
const usesDefault = (key) => !(key in form.markup_by_workflow);
const effective = (key) => (usesDefault(key) ? form.markup_multiplier : form.markup_by_workflow[key]);
const markupError = (key) => form.errors[`markup_by_workflow.${key}`] || form.errors.markup_by_workflow;
const interpolate = (text, values) =>
    Object.entries(values).reduce((result, [key, value]) => result.replaceAll(`:${key}`, value), text ?? '');
const hint = (key) =>
    interpolate(props.t.hints[key], {
        min: moneyCents(props.limits.minimum_min),
        max: moneyCents(props.limits.minimum_max),
        step: moneyCents(props.customStepCents),
    });
const calculation = (row) =>
    `US$ ${quantity(row.raw_cost_usd, 6)} × ${props.t.exchange_rate} ${quantity(row.exchange_rate_brl_usd, 6)} × ${props.t.markup} ${quantity(row.markup_multiplier, 2)} = ${row.charge_formatted}`;
const usageCalculation = (key) =>
    calculation({
        ...props.workflowExamples[key],
        raw_cost_usd: props.workflowUsage[key].avg_cost_usd,
        charge_formatted: props.workflowUsage[key].avg_price_formatted,
    });
function toggle(key, value) {
    if (value) delete form.markup_by_workflow[key];
    else form.markup_by_workflow[key] = form.markup_multiplier;
}
</script>
<template>
    <section class="card mb-0">
        <div class="card-body">
            <h2 id="pricing-billing-title" class="h5">{{ t.billing_title }}</h2>
            <p id="billing-hint" class="text-muted">{{ t.billing_hint }}</p>
            <div data-test="formula" class="border rounded p-3 mb-3">
                <h3 class="h6">{{ t.formula_title }}</h3>
                <p class="mb-1">{{ t.formula_sentence }}</p>
                <p v-if="examplesStale" role="status" class="mb-0">{{ t.examples_refresh }}</p>
                <p v-else-if="topUsageWorkflow" class="mb-0">
                    {{
                        interpolate(t.formula_example, {
                            usd: quantity(topUsageWorkflow.avg_cost_usd, 6),
                            price: topUsageWorkflow.avg_price_formatted,
                        })
                    }}
                </p>
                <p v-else-if="workflowExamples.exam_assistant" class="mb-0">
                    {{
                        interpolate(t.formula_example, {
                            usd: quantity(workflowExamples.exam_assistant.raw_cost_usd, 2),
                            price: workflowExamples.exam_assistant.charge_formatted,
                        })
                    }}
                </p>
            </div>
            <fieldset :disabled="!active">
                <div v-if="form.errors._form" class="alert alert-danger" role="alert">{{ form.errors._form }}</div>
                <div class="row g-3 mb-3">
                    <div v-for="field in globalFields" :key="field.key" class="col-12 col-md-6">
                        <label :for="`pricing-${field.key}`" class="form-label">{{ t.fields[field.key] }}</label>
                        <MoneyInput
                            v-if="field.key !== 'markup_multiplier'"
                            :id="`pricing-${field.key}`"
                            v-model="form[field.key]"
                            :invalid="!!form.errors[field.key]"
                            :required="active"
                            :disabled="!active"
                            :aria-describedby="`pricing-${field.key}-hint pricing-${field.key}-error`"
                        />
                        <input
                            v-else
                            :id="`pricing-${field.key}`"
                            v-model.number="form[field.key]"
                            type="number"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors[field.key] }"
                            :min="field.min"
                            :max="field.max"
                            :step="field.step"
                            :required="active"
                            :disabled="!active"
                            :aria-invalid="!!form.errors[field.key]"
                            :aria-describedby="`pricing-${field.key}-hint pricing-${field.key}-error`"
                        />
                        <div :id="`pricing-${field.key}-hint`" class="form-text">{{ hint(field.key) }}</div>
                        <div :id="`pricing-${field.key}-error`" class="text-danger small" role="alert">
                            {{ form.errors[field.key] }}
                        </div>
                    </div>
                </div>
                <div
                    data-test="markup-fieldset"
                    :class="{ 'border border-danger rounded p-2': form.errors.markup_by_workflow }"
                >
                    <div id="markup-workflow-error" class="text-danger small" role="alert">
                        {{ form.errors.markup_by_workflow }}
                    </div>
                    <div id="workflow-error" class="text-danger small" role="alert">
                        {{ form.errors.minimum_charge_cents_by_workflow }}
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle billing-table" aria-labelledby="pricing-billing-title">
                            <thead>
                                <tr>
                                    <th scope="col">{{ t.column_action }}</th>
                                    <th scope="col">{{ t.column_markup }}</th>
                                    <th scope="col" :title="t.minimum_note">{{ t.column_minimum }}</th>
                                    <th scope="col">
                                        {{ interpolate(t.column_usage_price, { days: usageWindowDays }) }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in billedWorkflows"
                                    :key="row.key"
                                    :data-workflow="row.key"
                                    data-test="markup-row"
                                >
                                    <th scope="row" class="fw-normal">{{ t.workflows[row.key] }}</th>
                                    <td>
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <div class="form-check mb-0">
                                                <input
                                                    :id="`pricing-markup-default-${row.index}`"
                                                    type="checkbox"
                                                    class="form-check-input"
                                                    :aria-label="`${t.markup_use_default}: ${t.workflows[row.key]}`"
                                                    :checked="usesDefault(row.key)"
                                                    :aria-invalid="!!form.errors.markup_by_workflow"
                                                    aria-describedby="markup-workflow-error"
                                                    @change="toggle(row.key, $event.target.checked)"
                                                />
                                                <label
                                                    :for="`pricing-markup-default-${row.index}`"
                                                    class="form-check-label"
                                                    >{{ t.markup_use_default }}</label
                                                >
                                            </div>
                                            <input
                                                :id="`pricing-markup-${row.index}`"
                                                type="number"
                                                class="form-control markup-input"
                                                :aria-label="`${t.column_markup}: ${t.workflows[row.key]}`"
                                                :value="effective(row.key)"
                                                :disabled="!active || usesDefault(row.key)"
                                                :min="limits.markup_min"
                                                :max="limits.markup_max"
                                                step="0.01"
                                                :required="active && !usesDefault(row.key)"
                                                :aria-invalid="!!markupError(row.key)"
                                                :aria-describedby="`markup-error-${row.index} markup-workflow-error`"
                                                :class="{ 'is-invalid': markupError(row.key) }"
                                                @input="form.markup_by_workflow[row.key] = Number($event.target.value)"
                                            />
                                            <span class="small text-muted"
                                                >{{ t.markup_effective }}: {{ quantity(effective(row.key), 2) }}×</span
                                            >
                                        </div>
                                        <div :id="`markup-error-${row.index}`" class="text-danger small" role="alert">
                                            {{ form.errors[`markup_by_workflow.${row.key}`] }}
                                        </div>
                                    </td>
                                    <td>
                                        <template v-if="row.key in form.minimum_charge_cents_by_workflow">
                                            <label :for="`pricing-workflow-${row.index}`" class="visually-hidden"
                                                >{{ t.column_minimum }}: {{ t.workflows[row.key] }}</label
                                            >
                                            <MoneyInput
                                                :id="`pricing-workflow-${row.index}`"
                                                v-model="form.minimum_charge_cents_by_workflow[row.key]"
                                                data-test="minimum-input"
                                                :aria-label="`${t.column_minimum}: ${t.workflows[row.key]}`"
                                                :required="active"
                                                :disabled="!active"
                                                :invalid="
                                                    !!(
                                                        form.errors.minimum_charge_cents_by_workflow ||
                                                        form.errors[`minimum_charge_cents_by_workflow.${row.key}`]
                                                    )
                                                "
                                                :aria-describedby="`billing-hint workflow-error pricing-workflow-${row.index}-error`"
                                            />
                                            <div
                                                :id="`pricing-workflow-${row.index}-error`"
                                                class="text-danger small"
                                                role="alert"
                                            >
                                                {{ form.errors[`minimum_charge_cents_by_workflow.${row.key}`] }}
                                            </div> </template
                                        ><span v-else>{{ t.default_minimum }}</span>
                                    </td>
                                    <td data-test="workflow-example">
                                        <span v-if="examplesStale" role="status">{{ t.examples_refresh }}</span>
                                        <template v-else>
                                            <span
                                                data-test="usage-price"
                                                :title="
                                                    workflowUsage[row.key]?.runs > 0
                                                        ? usageCalculation(row.key)
                                                        : undefined
                                                "
                                            >
                                                {{
                                                    workflowUsage[row.key]?.runs > 0
                                                        ? workflowUsage[row.key].avg_price_formatted
                                                        : '—'
                                                }}
                                                <span
                                                    v-if="
                                                        workflowUsage[row.key]?.runs > 0 &&
                                                        workflowUsage[row.key].minimum_applied
                                                    "
                                                    class="badge badge-soft-info"
                                                    >{{ t.minimum_applied }}</span
                                                >
                                            </span>
                                            <span data-test="usage-runs" class="d-block small text-muted">{{
                                                workflowUsage[row.key]?.runs > 0
                                                    ? interpolate(t.usage_runs, { count: workflowUsage[row.key].runs })
                                                    : interpolate(t.usage_none, { days: usageWindowDays })
                                            }}</span>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="mt-3">
                    <h3 id="pricing-examples-title" class="h6">{{ t.examples_title }}</h3>
                    <p class="form-text">{{ t.examples_hint }}</p>
                    <p v-if="examplesStale" role="status">{{ t.examples_refresh }}</p>
                    <div v-else class="table-responsive">
                        <table class="table table-sm align-middle" aria-labelledby="pricing-examples-title">
                            <thead>
                                <tr>
                                    <th scope="col">{{ t.column_cost_usd }}</th>
                                    <th scope="col">{{ t.column_price_brl }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="example in examples" :key="example.raw_cost_usd" data-test="example">
                                    <td>US$ {{ quantity(example.raw_cost_usd, 6) }}</td>
                                    <td :title="calculation(example)">
                                        {{ example.charge_formatted }}
                                        <span v-if="example.minimum_applied" class="badge badge-soft-info">{{
                                            t.minimum_applied
                                        }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="mt-3">
                    <h3 class="h6">{{ t.packages_title }}</h3>
                    <ul class="list-inline small mb-0">
                        <li v-for="pack in packages" :key="pack.code" class="list-inline-item me-3">
                            {{ pack.name ?? pack.label ?? pack.code }}:
                            <template v-if="pack.custom"
                                >{{ moneyCents(pack.custom_min_cents) }} –
                                {{ moneyCents(pack.custom_max_cents) }}</template
                            >
                            <template v-else>{{ moneyCents(pack.value_cents) }}</template
                            ><span v-if="pack.featured" class="badge badge-soft-primary ms-1">{{ t.featured }}</span>
                        </li>
                    </ul>
                    <div data-test="custom-package" class="mt-3">
                        <h4 class="h6">{{ t.custom_package_title }}</h4>
                        <p>
                            {{
                                interpolate(t.custom_package_hint, {
                                    min: money(form.custom_min_cents),
                                    max: money(form.custom_max_cents),
                                    step: moneyCents(customStepCents),
                                })
                            }}
                        </p>
                        <div class="row g-3">
                            <div v-for="field in packageFields" :key="field.key" class="col-12 col-md-4 col-lg-3">
                                <label :for="`pricing-${field.key}`" class="form-label">{{
                                    t.fields[field.key]
                                }}</label>
                                <MoneyInput
                                    :id="`pricing-${field.key}`"
                                    v-model="form[field.key]"
                                    :invalid="!!form.errors[field.key]"
                                    :required="active"
                                    :disabled="!active"
                                    :aria-describedby="`pricing-${field.key}-hint pricing-${field.key}-error`"
                                />
                                <div :id="`pricing-${field.key}-hint`" class="form-text">{{ hint(field.key) }}</div>
                                <div :id="`pricing-${field.key}-error`" class="text-danger small" role="alert">
                                    {{ form.errors[field.key] }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3" data-test="settings-review">
                    {{ t.review_settings }}
                </button>
            </fieldset>
        </div>
    </section>
</template>
<style scoped>
.billing-table :deep(.money-input) {
    max-width: 10rem;
}

.markup-input {
    min-width: 5rem;
    max-width: 8rem;
}
</style>
