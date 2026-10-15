<script setup>
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import KpiCard from '@/Components/Panel/KpiCard.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Indicadores cirúrgicos (SurgeryIndicatorsController): só agregados. O médico vê
 * os próprios números (scope=doctor). Cada gráfico tem a tabela equivalente ao
 * lado (texto alternativo) e a amostra pequena de erro refracional é sinalizada.
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    indicators: { type: Object, required: true },
    filters: { type: Object, required: true },
    scope: { type: String, default: 'clinic' },
    urls: { type: Object, required: true },
    t: { type: Object, required: true },
});

const { number, date } = useLocaleFormat();
const ti = computed(() => props.t.indicators ?? {});
const filter = reactive({ from: props.filters.from, to: props.filters.to });

function tx(key, params = {}) {
    return Object.entries(params).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), key);
}

function apply() {
    router.get(props.urls.index, { from: filter.from, to: filter.to }, { preserveState: true, preserveScroll: true });
}

function preset(days) {
    const local = (d) =>
        `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    filter.to = local(new Date());
    filter.from = local(new Date(Date.now() - (days - 1) * 86_400_000));
    apply();
}

const exportUrl = computed(() => `${props.urls.export.split('?')[0]}?from=${filter.from}&to=${filter.to}`);
const a = computed(() => props.indicators.authorization);
const times = computed(() => props.indicators.times);
const refractive = computed(() => props.indicators.refractive);
const maxMonth = computed(() => Math.max(1, ...props.indicators.surgeries.by_month.map((m) => m.count)));
const dash = (value, digits = 1) => (value === null || value === undefined ? '—' : number(value, digits));
</script>

<template>
    <Head :title="ti.title" />

    <AppLayout :title="ti.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3" data-testid="surgery-indicators">
            <PageHeader :title="ti.title" :subtitle="ti.subtitle">
                <template #actions>
                    <a class="btn btn-outline-secondary btn-sm" :href="exportUrl" data-testid="ind-export">
                        <i class="ti ti-download me-1" aria-hidden="true"></i>{{ ti.export }}
                    </a>
                </template>
            </PageHeader>

            <p class="text-muted small mb-2">
                <span class="badge text-bg-light border me-2">{{
                    scope === 'doctor' ? ti.scope_doctor : ti.scope_clinic
                }}</span
                >{{ ti.only_aggregates }}
            </p>

            <form class="row g-2 align-items-end mb-3" @submit.prevent="apply">
                <div class="col-auto">
                    <label class="form-label small mb-0" for="ind-from">{{ ti.filters.from }}</label>
                    <input id="ind-from" v-model="filter.from" type="date" class="form-control form-control-sm" />
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0" for="ind-to">{{ ti.filters.to }}</label>
                    <input id="ind-to" v-model="filter.to" type="date" class="form-control form-control-sm" />
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">{{ ti.filters.apply }}</button>
                </div>
                <div class="col-auto btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary" @click="preset(30)">
                        {{ ti.filters.last_30 }}
                    </button>
                    <button type="button" class="btn btn-outline-secondary" @click="preset(90)">
                        {{ ti.filters.last_90 }}
                    </button>
                    <button type="button" class="btn btn-outline-secondary" @click="preset(365)">
                        {{ ti.filters.last_365 }}
                    </button>
                </div>
                <div class="col-auto small text-muted">
                    {{ date(indicators.period.from) }} – {{ date(indicators.period.to) }}
                </div>
            </form>

            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3">
                    <KpiCard
                        :label="ti.surgeries.total"
                        :value="number(indicators.surgeries.total)"
                        icon="ti ti-scissors"
                        tone="primary"
                        test-id="surgeries"
                    />
                </div>
                <div class="col-6 col-lg-3">
                    <KpiCard
                        :label="ti.authorization.rate"
                        :value="a.rate === null ? '—' : number(a.rate, 1) + '%'"
                        :hint="ti.authorization.rate_hint"
                        icon="ti ti-shield-check"
                        tone="success"
                        test-id="auth-rate"
                    />
                </div>
                <div class="col-6 col-lg-3">
                    <KpiCard
                        :label="ti.times.request_to_surgery"
                        :value="
                            times.request_to_surgery.mean === null
                                ? '—'
                                : tx(ti.days, { value: number(times.request_to_surgery.mean, 1) })
                        "
                        :subtitle="ti.times.mean"
                        icon="ti ti-clock"
                        tone="info"
                        test-id="req-surgery"
                    />
                </div>
                <div class="col-6 col-lg-3">
                    <KpiCard
                        :label="ti.cancellations.cancelled"
                        :value="number(indicators.cancellations.cancelled)"
                        :subtitle="`${ti.cancellations.suspended}: ${number(indicators.cancellations.suspended)}`"
                        icon="ti ti-ban"
                        tone="danger"
                        test-id="cancelled"
                    />
                </div>
            </div>

            <div class="row g-3">
                <section class="col-lg-6" :aria-label="ti.authorization.title">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6">{{ ti.authorization.title }}</h2>
                            <table class="table table-sm mb-2">
                                <tbody>
                                    <tr
                                        v-for="k in ['requested', 'authorized', 'partial', 'waived', 'denied']"
                                        :key="k"
                                    >
                                        <th scope="row" class="fw-normal">{{ ti.authorization[k] }}</th>
                                        <td class="text-end">{{ number(a[k]) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <h3 class="h6 mt-3">{{ ti.authorization.reasons }}</h3>
                            <p v-if="!a.denial_reasons.length" class="text-muted small mb-0">{{ ti.empty }}</p>
                            <ol v-else class="small ps-3 mb-0">
                                <li v-for="r in a.denial_reasons" :key="r.reason">
                                    {{ r.reason }} <span class="badge text-bg-light border">{{ r.count }}</span>
                                </li>
                            </ol>
                        </div>
                    </div>
                </section>

                <section class="col-lg-6" :aria-label="ti.times.title">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6">{{ ti.times.title }}</h2>
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col"></th>
                                        <th scope="col" class="text-end">{{ ti.times.cases }}</th>
                                        <th scope="col" class="text-end">{{ ti.times.mean }}</th>
                                        <th scope="col" class="text-end">{{ ti.times.median }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="k in [
                                            'request_to_authorization',
                                            'authorization_to_surgery',
                                            'request_to_surgery',
                                        ]"
                                        :key="k"
                                    >
                                        <th scope="row" class="fw-normal">{{ ti.times[k] }}</th>
                                        <td class="text-end">{{ number(times[k].n) }}</td>
                                        <td class="text-end">{{ dash(times[k].mean) }}</td>
                                        <td class="text-end">{{ dash(times[k].median) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="col-lg-6" :aria-label="ti.surgeries.title">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6">{{ ti.surgeries.title }} — {{ ti.surgeries.by_month }}</h2>
                            <p v-if="!indicators.surgeries.by_month.length" class="text-muted small mb-0">
                                {{ ti.empty }}
                            </p>
                            <div v-else class="row">
                                <div
                                    class="col-7"
                                    role="img"
                                    :aria-label="tx(ti.chart_alt, { title: ti.surgeries.by_month })"
                                >
                                    <div
                                        v-for="m in indicators.surgeries.by_month"
                                        :key="m.label"
                                        class="d-flex align-items-center gap-2 mb-1"
                                        aria-hidden="true"
                                    >
                                        <span class="small text-muted" style="width: 4.5rem">{{ m.label }}</span>
                                        <div
                                            class="bg-primary rounded"
                                            :style="{ width: `${(m.count / maxMonth) * 100}%`, height: '0.9rem' }"
                                        ></div>
                                    </div>
                                </div>
                                <div class="col-5">
                                    <table class="table table-sm mb-0">
                                        <tbody>
                                            <tr v-for="m in indicators.surgeries.by_month" :key="m.label">
                                                <th scope="row" class="fw-normal">{{ m.label }}</th>
                                                <td class="text-end">{{ number(m.count) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div v-if="indicators.surgeries.by_surgeon.length" class="mt-3">
                                <h3 class="h6">{{ ti.surgeries.by_surgeon }}</h3>
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <tr v-for="r in indicators.surgeries.by_surgeon" :key="r.label">
                                            <th scope="row" class="fw-normal">{{ r.label }}</th>
                                            <td class="text-end">{{ number(r.count) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="indicators.surgeries.by_facility.length" class="mt-3">
                                <h3 class="h6">{{ ti.surgeries.by_facility }}</h3>
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <tr v-for="r in indicators.surgeries.by_facility" :key="r.label">
                                            <th scope="row" class="fw-normal">{{ r.label }}</th>
                                            <td class="text-end">{{ number(r.count) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="col-lg-6" :aria-label="ti.complications.title">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6">{{ ti.complications.title }}</h2>
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ ti.complications.milestone }}</th>
                                        <th scope="col" class="text-end">{{ ti.complications.evaluations }}</th>
                                        <th scope="col" class="text-end">{{ ti.complications.with_findings }}</th>
                                        <th scope="col" class="text-end">{{ ti.complications.severe }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="m in indicators.complications.by_milestone" :key="m.milestone">
                                        <th scope="row" class="fw-normal">{{ m.label }}</th>
                                        <td class="text-end">{{ number(m.evaluations) }}</td>
                                        <td class="text-end">{{ number(m.with_findings) }}</td>
                                        <td class="text-end">{{ number(m.severe) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="col-12" :aria-label="ti.refractive.title">
                    <div class="card">
                        <div class="card-body">
                            <h2 class="h6 mb-0">{{ ti.refractive.title }}</h2>
                            <p class="text-muted small">{{ ti.refractive.subtitle }}</p>
                            <p
                                v-if="!refractive.by_surgeon.length"
                                class="text-muted mb-0"
                                data-testid="ind-refractive-empty"
                            >
                                {{ ti.refractive.no_data }}
                            </p>
                            <div v-else class="table-responsive">
                                <table class="table table-sm align-middle mb-0" data-testid="ind-refractive">
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ ti.refractive.surgeon }}</th>
                                            <th scope="col" class="text-end">{{ ti.refractive.n }}</th>
                                            <th scope="col" class="text-end">{{ ti.refractive.me }}</th>
                                            <th scope="col" class="text-end">{{ ti.refractive.mae }}</th>
                                            <th scope="col" class="text-end">{{ ti.refractive.within_050 }}</th>
                                            <th scope="col" class="text-end">{{ ti.refractive.within_100 }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="r in refractive.by_surgeon" :key="r.surgeon">
                                            <th scope="row" class="fw-normal">
                                                {{ r.surgeon
                                                }}<span
                                                    v-if="r.small_sample"
                                                    class="badge text-bg-warning ms-2"
                                                    :title="tx(ti.small_sample, { n: indicators.small_sample })"
                                                    >{{ tx(ti.small_sample, { n: indicators.small_sample }) }}</span
                                                >
                                            </th>
                                            <td class="text-end">{{ number(r.n) }}</td>
                                            <td class="text-end">{{ number(r.me, 2) }}</td>
                                            <td class="text-end">{{ number(r.mae, 2) }}</td>
                                            <td class="text-end">{{ number(r.within_050, 1) }}</td>
                                            <td class="text-end">{{ number(r.within_100, 1) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot v-if="refractive.overall && refractive.by_surgeon.length > 1">
                                        <tr class="fw-semibold">
                                            <th scope="row">{{ ti.refractive.overall }}</th>
                                            <td class="text-end">{{ number(refractive.overall.n) }}</td>
                                            <td class="text-end">{{ number(refractive.overall.me, 2) }}</td>
                                            <td class="text-end">{{ number(refractive.overall.mae, 2) }}</td>
                                            <td class="text-end">{{ number(refractive.overall.within_050, 1) }}</td>
                                            <td class="text-end">{{ number(refractive.overall.within_100, 1) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
