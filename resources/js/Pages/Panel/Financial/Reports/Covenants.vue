<script setup>
import { computed } from 'vue';
import AppLayout    from '@/Layouts/AppLayout.vue';
import PageHeader   from '@/Components/Panel/PageHeader.vue';
import PeriodFilter from '@/Components/Panel/PeriodFilter.vue';
import KpiCard      from '@/Components/Panel/KpiCard.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans';
import CovenantsTable   from './CovenantsTable.vue';
import ReportExportMenu from './ReportExportMenu.vue';
import { usePercent, useReportPage } from './useReportPage.js';

/**
 * Relatório de faturamento por convênio (FinancialReportsController::covenants).
 *
 * Mesma regra do Dashboard gerencial: período pela data de atendimento, guias
 * em rascunho/canceladas fora dos totais e "Recebido" = valor pago das guias
 * com status pago. Consolidado agregado no servidor (GROUP BY convênio); a
 * linha expandida busca as guias do convênio (JSON paginado, paciente só por
 * código + iniciais). Exportação (CSV/Excel) sempre do período aplicado.
 */
const props = defineProps({
    breadcrumbs:           { type: Array,  default: () => [] },
    filters:               { type: Object, required: true },    // { from, to } normalizados no servidor
    today:                 { type: String, default: '' },       // Y-m-d no fuso da clínica
    summary:               { type: Object, default: () => ({}) }, // { total_claims, total_amount, total_paid, total_denied, total_open, glosa_rate, received_rate, glosa_alert }
    byCovenant:            { type: Array,  default: () => [] },   // [{ covenant_id, covenant, inactive, claims, amount, paid, denied, open, glosa_rate, received_rate, glosa_alert }]
    glosa_alert_threshold: { type: Number, default: 10 },
    routes:                { type: Object, default: () => ({}) },
    export_formats:        { type: Array,  default: () => ['csv', 'xlsx'] },
    t:                     { type: Object, default: () => ({}) },
});

const { money, number } = useLocaleFormat();
const { percent } = usePercent();
const { from, to, loading, loadError, applyPeriod, exportOptions, exportTitle } = useReportPage(props, 'panel.financial.reports.covenants');

const c         = computed(() => props.t.covenants ?? {});
const pageTitle = computed(() => c.value.title ?? '');
const { tx }    = useTrans(() => props.t.covenants ?? {});

// Glosa em alerta só quando existe valor glosado (antes: alarme com R$ 0,00).
const hasGlosa = computed(() => Number(props.summary.total_denied ?? 0) > 0);

function rateOfBilled(rate) {
    return rate === null || rate === undefined ? '' : tx('rate_of_billed', { percent: percent(rate) });
}

const kpis = computed(() => {
    const s = props.summary ?? {};

    return [
        { key: 'claims', icon: 'ti ti-files', tone: 'primary', value: number(s.total_claims ?? 0) },
        { key: 'billed', icon: 'ti ti-file-invoice', tone: 'primary', value: money(s.total_amount ?? 0) },
        { key: 'received', icon: 'ti ti-cash', tone: 'success', value: money(s.total_paid ?? 0), subtitle: rateOfBilled(s.received_rate) },
        {
            key:      'glosa',
            icon:     'ti ti-alert-triangle',
            tone:     hasGlosa.value ? 'danger' : 'secondary',
            value:    money(s.total_denied ?? 0),
            subtitle: rateOfBilled(s.glosa_rate),
        },
        { key: 'open', icon: 'ti ti-hourglass', tone: 'warning', value: money(s.total_open ?? 0) },
    ].map((kpi) => ({ ...kpi, label: c.value[`kpi_${kpi.key}`] ?? kpi.key, hint: c.value[`kpi_${kpi.key}_hint`] ?? '' }));
});
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="pageTitle" :total="Number(summary.total_claims ?? 0)" :total-label="c.total_label">
                <template #actions>
                    <ReportExportMenu :options="exportOptions" :title="exportTitle" :label="t.export" />
                </template>
            </PageHeader>

            <!-- Período: atalhos + De/Até, aplicado na URL -->
            <div class="d-flex flex-wrap align-items-end gap-2 mb-1" data-test="period-bar">
                <PeriodFilter
                    v-model:from="from"
                    v-model:to="to"
                    :today="today"
                    :labels="t.shared?.period"
                    compact
                    :disabled="loading"
                    @change="applyPeriod"
                />
                <span class="small text-body-secondary align-self-center" role="status" aria-live="polite" data-test="loading-status">
                    <template v-if="loading">
                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ t.loading }}
                    </template>
                </span>
            </div>
            <p class="small text-body-secondary mb-3" data-test="period-basis">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ c.period_basis }}
            </p>

            <div v-if="loadError" class="alert alert-danger d-flex align-items-center gap-2" role="alert" data-test="load-error">
                <i class="ti ti-alert-circle" aria-hidden="true"></i>{{ loadError }}
            </div>

            <div :aria-busy="loading ? 'true' : 'false'">
                <!-- KPIs -->
                <section class="mb-3" :aria-label="c.kpis_label" data-test="kpis">
                    <div class="row g-3 row-cols-2 row-cols-md-3 row-cols-xl-5">
                        <div v-for="kpi in kpis" :key="kpi.key" class="col" :data-kpi="kpi.key">
                            <KpiCard
                                :label="kpi.label"
                                :value="kpi.value"
                                :icon="kpi.icon"
                                :tone="kpi.tone"
                                :hint="kpi.hint"
                                :subtitle="kpi.subtitle ?? ''"
                                :loading="loading"
                                :test-id="kpi.key"
                            />
                        </div>
                    </div>
                </section>

                <!-- Consolidado por convênio (ordenável, linha expansível com as guias) -->
                <CovenantsTable
                    :rows="byCovenant"
                    :summary="summary"
                    :filters="filters"
                    :threshold="glosa_alert_threshold"
                    :claims-url="routes.claims ?? ''"
                    :t="t"
                />
            </div>
        </div>
    </AppLayout>
</template>
