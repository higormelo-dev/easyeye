<script setup>
import { computed } from 'vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
const props = defineProps({
    t: { type: Object, required: true },
    currentRate: { type: Object, required: true },
    rateForm: { type: Object, required: true },
    fetchForm: { type: Object, required: true },
    history: { type: Object, required: true },
    historyRows: { type: Array, required: true },
    saving: { type: Boolean, default: false },
    active: { type: Boolean, default: false },
});
defineEmits(['review']);
const rateForm = props.rateForm;
const { quantity, dateTime } = useLocaleFormat();
const fetchDisabled = computed(() => props.currentRate.fetched_today && !props.currentRate.is_fallback);
const rateAge = computed(() => {
    const days = props.currentRate.age_days;
    if (days === null || days === undefined) return '—';
    if (days === 0) return props.t.today;
    if (days === 1) return props.t.age_one_day;
    return props.t.age_days.replace(':count', days);
});
</script>
<template>
    <div>
        <section class="mb-3" aria-labelledby="pricing-rate-title">
            <div class="card h-100 mb-0">
                <div class="card-header">
                    <h2 id="pricing-rate-title" class="h5 mb-0">{{ t.tab_rate }}</h2>
                </div>
                <div class="card-body" data-test="rate-card">
                    <div class="row g-3">
                        <div class="col-12 col-lg-5">
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
                                <template v-if="currentRate.note">
                                    <dt class="col-sm-5">{{ t.note }}</dt>
                                    <dd class="col-sm-7 text-break">{{ currentRate.note }}</dd>
                                </template>
                            </dl>
                        </div>
                        <div class="col-12 col-lg-7">
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
                                    @click="$emit('review', 'fetch')"
                                >
                                    {{ t.fetch_now }}
                                </button>
                                <p v-if="fetchDisabled" class="form-text mb-0">{{ t.fetch_unavailable }}</p>
                                <p v-else class="form-text mb-0">{{ t.fetch_hint }}</p>
                            </div>
                            <form data-test="rate-form" @submit.prevent="$emit('review', 'rate')">
                                <div v-if="rateForm.errors._form" class="alert alert-danger" role="alert">
                                    {{ rateForm.errors._form }}
                                </div>
                                <fieldset :disabled="saving || !active">
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
                                            :required="active"
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
                </div>
            </div>
        </section>

        <section class="card mb-0" aria-labelledby="pricing-history-title">
            <div class="card-header">
                <h3 id="pricing-history-title" class="h6 mb-0">{{ t.history_title }}</h3>
            </div>
            <div class="card-body">
                <p>{{ t.history_hint }}</p>
                <div class="table-responsive">
                    <table class="table align-middle" aria-labelledby="pricing-history-title">
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
</template>
