<script setup>
import { computed, useId } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout       from '@/Layouts/AppLayout.vue';
import PageHeader      from '@/Components/Panel/PageHeader.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useViewMode } from '@/composables/useViewMode.js';
import ClosingsCards   from './ClosingsCards.vue';
import ClosingsTable   from './ClosingsTable.vue';
import FlashMessage    from './FlashMessage.vue';
import PayoutTabs      from './PayoutTabs.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Financeiro › Repasse médico › Fechamentos: histórico paginado no servidor,
 * filtros (médico, status) na URL e alternância tabela/cards persistida no
 * navegador (mesmo paginator nas duas vistas).
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    tabs:        { type: Object, default: () => ({}) },
    payouts:     { type: Object, required: true },                // paginator Laravel
    filters:     { type: Object, default: () => ({}) },           // { doctor, status }
    options:     { type: Object, default: () => ({ doctors: [], statuses: [] }) },
    routes:      { type: Object, required: true },                // { index, show, pdf } — show/pdf com __ID__
    t:           { type: Object, default: () => ({}) },
    shared:      { type: Object, default: () => ({}) },
});

const { doctorLabel, statusLabel } = useDoctorPayoutFormat(() => props.t);
const { view, setView } = useViewMode('doctor_payout_closings_view');

const uid = useId();
const ids = { doctor: `dp-closings-doctor-${uid}`, status: `dp-closings-status-${uid}` };

const rows = computed(() => props.payouts?.data ?? []);

function applyFilters(patch) {
    router.get(props.routes.index, {
        doctor: props.filters.doctor ?? '',
        status: props.filters.status ?? '',
        ...patch,
    }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="t.closings_title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader
                :title="t.closings_title"
                :total="payouts.total ?? 0"
                :total-label="t.total_label"
                show-view-toggle
                :view="view"
                :view-table-title="t.view_table"
                :view-cards-title="t.view_cards"
                @set-view="setView"
            />

            <PayoutTabs :tabs="tabs" current="closings" :t="t" />
            <FlashMessage />

            <div class="d-flex flex-wrap align-items-end gap-3 mb-3" data-test="closings-filters">
                <div class="closings__filter">
                    <label :for="ids.doctor" class="form-label small mb-1">{{ t.filter_doctor }}</label>
                    <select
                        :id="ids.doctor"
                        class="form-select form-select-sm"
                        :value="filters.doctor ?? ''"
                        data-test="filter-doctor"
                        @change="applyFilters({ doctor: $event.target.value })"
                    >
                        <option value="">{{ t.all_doctors }}</option>
                        <option v-for="doctor in options?.doctors ?? []" :key="doctor.id" :value="doctor.id">{{ doctorLabel(doctor) }}</option>
                    </select>
                </div>
                <div class="closings__filter">
                    <label :for="ids.status" class="form-label small mb-1">{{ t.filter_status }}</label>
                    <select
                        :id="ids.status"
                        class="form-select form-select-sm"
                        :value="filters.status ?? ''"
                        data-test="filter-status"
                        @change="applyFilters({ status: $event.target.value })"
                    >
                        <option value="">{{ t.filter_status_all }}</option>
                        <option v-for="status in options?.statuses ?? []" :key="status" :value="status">{{ statusLabel(status) }}</option>
                    </select>
                </div>
                <button
                    v-if="filters.doctor || filters.status"
                    type="button"
                    class="btn btn-link btn-sm text-decoration-none px-1"
                    data-test="filters-clear"
                    @click="applyFilters({ doctor: '', status: '' })"
                >
                    <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ t.filter_clear }}
                </button>
            </div>

            <ClosingsTable
                v-if="view === 'table'"
                :payouts="rows"
                :routes="routes"
                :t="t"
                :empty-text="t.closings_empty"
            />
            <ClosingsCards
                v-else
                :payouts="rows"
                :routes="routes"
                :t="t"
                :empty-text="t.closings_empty"
            />

            <TablePagination
                :data="payouts"
                :showing-from="t.pagination_showing"
                :showing-of="t.pagination_of"
                :showing-suffix="t.pagination_suffix"
                :aria-label="t.pagination_label"
                :previous-label="t.pagination_previous"
                :next-label="t.pagination_next"
            />
        </div>
    </AppLayout>
</template>

<style scoped>
.closings__filter {
    min-width: 12rem;
}

@media (max-width: 575.98px) {
    .closings__filter {
        flex: 1 1 100%;
        min-width: 0;
    }
}
</style>
