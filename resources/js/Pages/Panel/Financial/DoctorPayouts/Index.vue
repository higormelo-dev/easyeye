<script setup>
import { computed, ref, useId, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout        from '@/Layouts/AppLayout.vue';
import PageHeader       from '@/Components/Panel/PageHeader.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import ReportExportMenu from '@/Pages/Panel/Financial/Reports/ReportExportMenu.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import AllocateReceiptModal from './AllocateReceiptModal.vue';
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
    filters:         { type: Object, default: () => ({}) },   // { doctor, from, to, status, service_type, receipt, warning }
    period_capped:   { type: Object, default: null },         // { requested_from, requested_to, max_days }
    today:           { type: String, default: '' },
    options:         { type: Object, default: () => ({ doctors: [] }) },
    selected_doctor: { type: Object, default: null },
    kpis:            { type: Object, default: null },
    summary:         { type: Array,  default: null },
    items:           { type: Object, default: null },         // paginator Laravel
    close_preview:   { type: Object, default: null },
    unassigned_exams: { type: Number, default: 0 },        // exames do equipamento sem médico no período
    manual_receipts: { type: Array,  default: null },         // receitas avulsas com saldo (carregadas sob demanda)
    reason_limits:   { type: Object, default: () => ({ min: 10, max: 1000 }) },
    routes:          { type: Object, required: true },        // { index, export, close, rules, closing_show, allocate, allocation }
    t:               { type: Object, default: () => ({}) },
    shared:          { type: Object, default: () => ({}) },
});

const { tx, date, number, countText } = useDoctorPayoutFormat(() => props.t);

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
        receipt:      next.receipt ?? '',
        warning:      next.warning ?? '',
    }, {
        preserveState:  true,
        preserveScroll: true,
        onStart:        () => { loading.value = true; },
        onFinish:       () => { loading.value = false; },
    });
}

/** Lista filtrada pelos mesmos filtros de período/médico + os informados. */
function showItems(patch) {
    applyFilters({ ...props.filters, status: '', service_type: '', receipt: '', warning: '', ...patch });
}

/** Itens que bloqueiam o fechamento (sem regra): o KPI e o alerta levam a eles. */
const noRuleUrl = computed(() => `${props.routes.index}?${new URLSearchParams({
    doctor:  props.filters.doctor ?? '',
    from:    props.filters.from ?? '',
    to:      props.filters.to ?? '',
    status:  'pending',
    warning: 'no_rule',
}).toString()}`);

/** Período anterior ao último fechamento: só consulta (nada a liberar aqui). */
const historicalText = computed(() => (
    props.close_preview?.historical ? tx('historical_period', { date: date(props.close_preview.last_closed_until) }) : ''
));

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
    if (preview.last_closed_until && props.filters.to < preview.last_closed_until) {
        return tx('close_after_last', { date: date(preview.last_closed_until) });
    }
    if (Number(preview.payout_cents ?? 0) < 0) return props.t.release_negative_hint ?? '';

    return '';
});

const closeDescribedBy = computed(() => (blocking.value > 0 || closeHint.value ? ids.closeHint : undefined));

function openClose() {
    if (!canClose.value) return;

    closeOpen.value = true;
}

const rows = computed(() => props.items?.data ?? []);

// ── Recebimento manual: selecionar itens → alocar receita avulsa do caixa ───
const selected        = ref([]);
const allocateOpen    = ref(false);
const receiptsLoading = ref(false);

const selectedRows = computed(() => rows.value.filter((row) => selected.value.includes(row.row_id ?? row.key)));

// Seleção vale para a lista que está na tela: trocar página, médico, período
// ou filtro limpa (a recarga parcial das receitas não mexe nesses valores).
watch(
    () => [props.items?.current_page, props.filters.doctor, props.filters.from, props.filters.to, props.filters.status, props.filters.service_type, props.filters.receipt, props.filters.warning].join('|'),
    () => { selected.value = []; },
);

function loadReceipts() {
    router.reload({
        only:           ['manual_receipts'],
        preserveScroll: true,
        onStart:        () => { receiptsLoading.value = true; },
        onFinish:       () => { receiptsLoading.value = false; },
    });
}

function closeAllocate(done = false) {
    allocateOpen.value = false;

    if (done) selected.value = [];
}

// Estorno de alocação (admin ou financeiro, com motivo).
const reversing    = ref(null);
const reverseBusy  = ref(false);
const reverseError = ref('');

function askReverse(allocation) {
    reverseError.value = '';
    reversing.value    = allocation;
}

function cancelReverse() {
    if (reverseBusy.value) return;

    reversing.value = null;
}

function confirmReverse(reason) {
    if (!reversing.value || reverseBusy.value) return;

    reverseBusy.value  = true;
    reverseError.value = '';

    router.delete(props.routes.allocation.replace('__ID__', reversing.value.id), {
        data:           { reason },
        preserveScroll: true,
        onSuccess:      (page) => {
            const denied = page?.props?.flash?.error;
            if (denied) {
                reverseError.value = String(denied);

                return;
            }

            reversing.value = null;
        },
        onError:  (errors) => { reverseError.value = Object.values(errors ?? {}).flat()[0] ?? ''; },
        onFinish: () => { reverseBusy.value = false; },
    });
}
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

            <div
                v-if="Number(unassigned_exams) > 0"
                class="alert alert-warning d-flex align-items-start gap-2 mb-3"
                role="status"
                data-test="unassigned-exams"
            >
                <i class="ti ti-user-question mt-1" aria-hidden="true"></i><span>{{ countText('unassigned_exams', unassigned_exams) }}</span>
            </div>

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

                <div v-if="historicalText" class="alert alert-info d-flex align-items-start gap-2 mb-0" role="status" data-test="historical-period">
                    <i class="ti ti-history mt-1" aria-hidden="true"></i><span>{{ historicalText }}</span>
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
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-danger"
                        :disabled="loading"
                        data-test="see-no-rule-items"
                        @click="showItems({ status: 'pending', warning: 'no_rule' })"
                    >
                        <i class="ti ti-list-search me-1" aria-hidden="true"></i>{{ t.see_items }}
                    </button>
                    <Link :href="routes.rules" class="btn btn-sm btn-outline-danger" data-test="go-to-rules">
                        <i class="ti ti-adjustments-dollar me-1" aria-hidden="true"></i>{{ t.go_to_rules }}
                    </Link>
                </div>
                <p v-else-if="closeHint" :id="ids.closeHint" class="small text-muted mb-0" data-test="close-hint">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ closeHint }}
                </p>

                <ApuracaoKpis :kpis="kpis ?? {}" :no-rule-url="noRuleUrl" :loading="loading" :t="t" />

                <ApuracaoSummary
                    :summary="summary ?? []"
                    :adjustments="Number(kpis?.adjustments ?? 0)"
                    :t="t"
                    @open-type="(type) => showItems({ service_type: type, status: 'in_payout' })"
                />

                <section class="card mb-0" :aria-labelledby="ids.itemsTitle" data-test="items">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <h2 :id="ids.itemsTitle" class="h6 fw-bold mb-0 me-auto">{{ t.items_title }}</h2>
                        <button
                            type="button"
                            class="btn btn-outline-primary btn-sm"
                            :disabled="selected.length === 0 || loading"
                            data-test="allocate-open"
                            @click="allocateOpen = true"
                        >
                            <i class="ti ti-cash-banknote me-1" aria-hidden="true"></i>{{ t.allocate_action }}
                            <span v-if="selected.length" class="badge text-bg-primary ms-1">{{ number(selectedRows.length) }}</span>
                        </button>
                    </div>
                    <PayoutItemsTable
                        :rows="rows"
                        :t="t"
                        :caption="t.items_title"
                        :empty-text="t.items_empty"
                        show-status
                        show-receipt
                        selectable
                        can-reverse
                        v-model:selected="selected"
                        :closing-url="routes.closing_show"
                        @reverse-allocation="askReverse"
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

            <AllocateReceiptModal
                v-if="selected_doctor"
                :open="allocateOpen"
                :rows="selectedRows"
                :receipts="manual_receipts"
                :loading="receiptsLoading"
                :action="routes.allocate"
                :t="t"
                @load="loadReceipts"
                @close="closeAllocate"
            />

            <ConfirmationWithReasonModal
                :open="!!reversing"
                :title="t.manual_reverse_title"
                :message="t.manual_reverse_hint"
                :confirm-label="t.manual_reverse"
                confirm-variant="danger"
                :saving="reverseBusy"
                :min-length="Number(reason_limits.min)"
                :max-length="Number(reason_limits.max)"
                :error="reverseError"
                @close="cancelReverse"
                @confirm="confirmReverse"
            />

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
