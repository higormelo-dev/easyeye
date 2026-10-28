<script setup>
import { useLocaleFormat } from '@/composables/useLocaleFormat';
const props = defineProps({
    t: { type: Object, required: true },
    form: { type: Object, required: true },
    limits: { type: Object, required: true },
    internalWorkflows: { type: Array, required: true },
    workflowExamples: { type: Object, required: true },
    workflowUsage: { type: Object, required: true },
    usageWindowDays: { type: Number, required: true },
    examplesStale: { type: Boolean, default: false },
    active: { type: Boolean, default: false },
});
const form = props.form;
const { quantity } = useLocaleFormat();
const usesDefault = (key) => !(key in form.markup_by_workflow);
const effective = (key) => (usesDefault(key) ? form.markup_multiplier : form.markup_by_workflow[key]);
const markupError = (key) => form.errors[`markup_by_workflow.${key}`] || form.errors.markup_by_workflow;
const interpolate = (text, values) =>
    Object.entries(values).reduce((result, [key, value]) => result.replaceAll(`:${key}`, value), text ?? '');
const usageCalculation = (key) => {
    const usage = props.workflowUsage[key];
    const example = props.workflowExamples[key];
    return `US$ ${quantity(usage.avg_cost_usd, 6)} × ${props.t.exchange_rate} ${quantity(example?.exchange_rate_brl_usd, 6)} × ${props.t.markup} ${quantity(example?.markup_multiplier, 2)} = ${usage.avg_price_formatted}`;
};
function toggle(key, value) {
    if (value) delete form.markup_by_workflow[key];
    else form.markup_by_workflow[key] = form.markup_multiplier;
}
</script>
<template>
    <section class="card mb-0">
        <div class="card-body">
            <h2 id="pricing-internal-title" class="h5">{{ t.internal_title }}</h2>
            <p id="internal-hint" class="text-muted">{{ t.internal_hint }}</p>
            <fieldset :disabled="!active">
                <div v-if="form.errors._form" class="alert alert-danger" role="alert">{{ form.errors._form }}</div>
                <div
                    data-test="markup-fieldset-internal"
                    :class="{ 'border border-danger rounded p-2': form.errors.markup_by_workflow }"
                >
                    <p id="markup-workflow-error-internal" class="text-danger small" role="alert">
                        {{ form.errors.markup_by_workflow }}
                    </p>
                    <div class="table-responsive">
                        <table class="table align-middle" aria-labelledby="pricing-internal-title">
                            <thead>
                                <tr>
                                    <th scope="col">{{ t.column_action }}</th>
                                    <th scope="col">{{ t.column_markup }}</th>
                                    <th scope="col">
                                        {{ interpolate(t.column_usage_price, { days: usageWindowDays }) }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in internalWorkflows"
                                    :key="row.key"
                                    :data-workflow="row.key"
                                    data-test="markup-row"
                                >
                                    <th scope="row" class="fw-normal">
                                        {{ t.workflows[row.key] }}
                                    </th>
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
                                                    aria-describedby="markup-workflow-error-internal"
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
                                                :aria-describedby="`markup-error-${row.index} markup-workflow-error-internal`"
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
                <button type="submit" class="btn btn-primary mt-3" data-test="settings-review">
                    {{ t.review_settings }}
                </button>
            </fieldset>
        </div>
    </section>
</template>
<style scoped>
.markup-input {
    min-width: 5rem;
    max-width: 8rem;
}
</style>
