<script setup>
import { computed, ref, useId } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout        from '@/Layouts/AppLayout.vue';
import PageHeader       from '@/Components/Panel/PageHeader.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import ReportExportMenu from '@/Pages/Panel/Financial/Reports/ReportExportMenu.vue';
import ApuracaoFilters  from './ApuracaoFilters.vue';
import ApuracaoKpis     from './ApuracaoKpis.vue';
import ApuracaoSummary  from './ApuracaoSummary.vue';
import ClosePeriodModal from './ClosePeriodModal.vue';
import FlashMessage     from './FlashMessage.vue';
import PayoutItemsTable from './PayoutItemsTable.vue';
import PayoutTabs       from './PayoutTabs.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Financeiro › Repasse médico › Apuração: médico + período → produção
 * (pendente calculada ao vivo + itens já fechados/pagos), indicadores, resumo
 * por tipo, itens paginados no servidor e "Fechar período" com a prévia do
 * servidor (close_preview). Filtros vão na URL; a paginação do servidor já
 * carrega os filtros nos links.
 */
const props = defineProps({
    breadcrumbs:     { type: Array,  default: () => [] },
    tabs:            { type: Object, default: () => ({}) },
    filters:         { type: Object, default: () => ({}) },   // { doctor, from, to, status, service_type }
    period_capped:   { type: Object, default: null },         // { requested_from, requested_to, max_days }
    today:           { type: String, default: '' },
    options:         { type: Object, default: () => ({ doctors: [] }) },
    selected_doctor: { type: Object, default: null },
    kpis:            { type: Object, default: null },
    summary:         { type: Array,  default: null },
    items:           { type: Object, default: null },         // paginator Laravel
    close_preview:   { type: Object, default: null },
    routes:          { type: Object, required: true },        // { index, export, close, rules, closing_show }
    t:               { type: Object, default: () => ({}) },
    shared:          { type: Object, default: () => ({}) },
});

const { tx, date, number } = useDoctorPayoutFormat(() => props.t);

const uid = useId();
const ids = {
    closeHint:  `dp-close-hint-${uid}`,
    itemsTitle: `dp-items-title-${uid}`,
};

// ── Filtros: toda troca visita a URL (página volta à 1ª) ────────────────────
const loading = ref(false);

function applyFilters(next) {
    router.get(props.routes.index, {
        doctor:       next.doctor ?? '',
        from:         next.from ?? '',
        to:           next.to ?? '',
        status:       next.status ?? '',
        service_type: next.service_type ?? '',
    }, {
        preserveState:  true,
        preserveScroll: true,
        onStart:        () => { loading.value = true; },
        onFinish:       () => { loading.value = false; },
    });
}

const periodCappedText = computed(() => {
    const capped = props.period_capped;
    if (!capped) return '';

    return tx('period_capped', {
        requested_from: date(capped.requested_from),
        requested_to:   date(capped.requested_to),
        days:           number(capped.max_days),
        from:           date(props.filters.from),
        to:             date(props.filters.to),
    });
});

// ── Exportação (período aplicado, o mesmo dos números na tela) ──────────────
const EXPORT_FORMATS = [
    { key: 'csv',  icon: 'ti ti-file-type-csv',    labelKey: 'export_csv' },
    { key: 'xlsx', icon: 'ti ti-file-spreadsheet', labelKey: 'export_xlsx' },
];

const exportOptions = computed(() => EXPORT_FORMATS.map((format) => ({
    key:   format.key,
    icon:  format.icon,
    label: props.t[format.labelKey] ?? format.key.toUpperCase(),
    href:  `${props.routes.export}?${new URLSearchParams({
        doctor: props.filters.doctor ?? '',
        from:   props.filters.from ?? '',
        to:     props.filters.to ?? '',
        format: format.key,
    }).toString()}`,
})));

// ── Fechar período ──────────────────────────────────────────────────────────
const closeOpen = ref(false);

const blocking = computed(() => Number(props.close_preview?.blocking ?? 0));
const canClose = computed(() => !!props.close_preview?.can_close && !loading.value);

/** Por que não dá para fechar (quando não é bloqueio por falta de regra). */
const closeHint = computed(() => {
    const preview = props.close_preview;
    if (!preview || preview.can_close || blocking.value > 0) return '';
    if (Number(preview.count ?? 0) === 0) return props.t.close_nothing ?? '';
    if (props.today && props.filters.to > props.today) return props.t.errors?.end_in_future ?? '';

    return '';
});

const closeDescribedBy = computed(() => (blocking.value > 0 || closeHint.value ? ids.closeHint : undefined));

function openClose() {
    if (!canClose.value) return;

    closeOpen.value = true;
}

const rows = computed(() => props.items?.data ?? []);
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.title">
                <template #actions>
                    <div v-if="selected_doctor" class="d-flex flex-wrap gap-2">
                        <ReportExportMenu :options="exportOptions" :title="t.export" :label="t.export" />
                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            :disabled="!canClose"
                            :aria-describedby="closeDescribedBy"
                            data-test="close-open"
                            @click="openClose"
                        >
                            <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ t.close_action }}
                        </button>
                    </div>
                </template>
            </PageHeader>

            <PayoutTabs :tabs="tabs" current="apuracao" :t="t" />
            <FlashMessage />

            <ApuracaoFilters
                :filters="filters"
                :doctors="options?.doctors ?? []"
                :today="today"
                :t="t"
                :shared="shared"
                :disabled="loading"
                @change="applyFilters"
            />

            <!-- Sem médico: orientação, nada calculado -->
            <div v-if="!selected_doctor" class="card" data-test="select-doctor">
                <div class="card-body text-center py-5">
                    <i class="ti ti-user-search fs-1 text-muted d-block mb-2" aria-hidden="true"></i>
                    <h2 class="h5 fw-semibold">{{ t.select_doctor_title }}</h2>
                    <p class="text-muted mb-0 mx-auto dp-empty-hint">{{ t.select_doctor_hint }}</p>
                </div>
            </div>

            <div v-else class="d-grid gap-3" :aria-busy="loading ? 'true' : 'false'">
                <div v-if="period_capped" class="alert alert-warning d-flex align-items-start gap-2 mb-0" role="status" data-test="period-capped">
                    <i class="ti ti-alert-triangle mt-1" aria-hidden="true"></i><span>{{ periodCappedText }}</span>
                </div>

                <div
                    v-if="blocking > 0"
                    :id="ids.closeHint"
                    class="alert alert-danger d-flex flex-wrap align-items-center gap-2 mb-0"
                    role="status"
                    data-test="close-blocked"
                >
                    <i class="ti ti-alert-octagon" aria-hidden="true"></i>
                    <span class="me-auto">{{ tx('close_blocked', { count: number(blocking) }) }}</span>
                    <Link :href="routes.rules" class="btn btn-sm btn-outline-danger" data-test="go-to-rules">
                        <i class="ti ti-adjustments-dollar me-1" aria-hidden="true"></i>{{ t.go_to_rules }}
                    </Link>
                </div>
                <p v-else-if="closeHint" :id="ids.closeHint" class="small text-muted mb-0" data-test="close-hint">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ closeHint }}
                </p>

                <ApuracaoKpis :kpis="kpis ?? {}" :rules-url="routes.rules" :loading="loading" :t="t" />

                <ApuracaoSummary :summary="summary ?? []" :t="t" />

                <section class="card mb-0" :aria-labelledby="ids.itemsTitle" data-test="items">
                    <div class="card-header">
                        <h2 :id="ids.itemsTitle" class="h6 fw-bold mb-0">{{ t.items_title }}</h2>
                    </div>
                    <PayoutItemsTable
                        :rows="rows"
                        :t="t"
                        :caption="t.items_title"
                        :empty-text="t.items_empty"
                        show-status
                        :closing-url="routes.closing_show"
                    />
                    <TablePagination
                        v-if="items"
                        :data="items"
                        class="px-3 pb-3"
                        :showing-from="t.pagination_showing"
                        :showing-of="t.pagination_of"
                        :showing-suffix="t.pagination_suffix"
                        :aria-label="t.pagination_label"
                        :previous-label="t.pagination_previous"
                        :next-label="t.pagination_next"
                    />
                </section>
            </div>

            <ClosePeriodModal
                v-if="close_preview && selected_doctor"
                :open="closeOpen"
                :preview="close_preview"
                :doctor-id="filters.doctor ?? ''"
                :doctor="selected_doctor"
                :action="routes.close"
                :t="t"
                @close="closeOpen = false"
            />
        </div>
    </AppLayout>
</template>

<style scoped>
.dp-empty-hint {
    max-width: 36rem;
}
</style>
