<script setup>
import { computed, ref, useId } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout         from '@/Layouts/AppLayout.vue';
import PageHeader        from '@/Components/Panel/PageHeader.vue';
import TablePagination   from '@/Components/Panel/TablePagination.vue';
import { useViewMode }   from '@/composables/useViewMode.js';
import FlashMessage      from './FlashMessage.vue';
import PayoutTabs        from './PayoutTabs.vue';
import RuleFormModal     from './RuleFormModal.vue';
import RulesCards        from './RulesCards.vue';
import RulesSettingsCard from './RulesSettingsCard.vue';
import RulesTable        from './RulesTable.vue';
import { SERVICE_TYPES } from './ruleForm.js';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Financeiro › Repasse médico › Regras: listagem paginada no servidor com
 * filtros na URL (médico — todas / gerais / de um médico —, tipo, situação),
 * alternância tabela/cards persistida, criar/editar em painel e excluir com
 * confirmação. Inclui a política "médicos veem os próprios repasses".
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    tabs:        { type: Object, default: () => ({}) },
    rules:       { type: Object, required: true },             // paginator Laravel
    filters:     { type: Object, default: () => ({}) },        // { doctor: '' | 'general' | uuid, service_type, status }
    options:     { type: Object, default: () => ({}) },        // { doctors, visit_types, procedures, exam_types, covenants }
    settings:    { type: Object, default: () => ({}) },        // { doctor_payouts_visible, can_manage }
    routes:      { type: Object, required: true },             // { index, store, update, destroy, settings }
    t:           { type: Object, default: () => ({}) },
    shared:      { type: Object, default: () => ({}) },
});

const DOCTOR_GENERAL = 'general';
const RULE_STATUSES  = ['active', 'inactive'];

const { doctorLabel, serviceTypeLabel } = useDoctorPayoutFormat(() => props.t);
const { view, setView } = useViewMode('doctor_payout_rules_view');

const uid = useId();
const ids = {
    doctor: `dp-rules-doctor-${uid}`,
    type:   `dp-rules-type-${uid}`,
    status: `dp-rules-status-${uid}`,
};

const rows       = computed(() => props.rules?.data ?? []);
const hasFilters = computed(() => !!(props.filters.doctor || props.filters.service_type || props.filters.status));

function applyFilters(patch) {
    router.get(props.routes.index, {
        doctor:       props.filters.doctor ?? '',
        service_type: props.filters.service_type ?? '',
        status:       props.filters.status ?? '',
        ...patch,
    }, { preserveState: true, preserveScroll: true });
}

// ── Painel criar/editar ─────────────────────────────────────────────────────
const modalOpen   = ref(false);
const editingRule = ref(null);

function openCreate() { editingRule.value = null; modalOpen.value = true; }
function openEdit(rule) { editingRule.value = rule; modalOpen.value = true; }
function closeModal() { modalOpen.value = false; editingRule.value = null; }

// ── Exclusão (fechamentos antigos guardam a regra aplicada) ─────────────────
function onDelete(rule) {
    if (!window.confirm(`${props.t.rules_delete_title}\n\n${props.t.rules_delete_hint}`)) return;

    router.delete(props.routes.destroy.replace('__ID__', rule.id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="t.rules_title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader
                :title="t.rules_title"
                :total="rules.total ?? 0"
                :total-label="t.total_label"
                show-view-toggle
                :view="view"
                :view-table-title="t.view_table"
                :view-cards-title="t.view_cards"
                @set-view="setView"
            >
                <template #actions>
                    <button type="button" class="btn btn-primary btn-sm" data-test="rule-new" @click="openCreate">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.rules_new }}
                    </button>
                </template>
            </PageHeader>

            <PayoutTabs :tabs="tabs" current="rules" :t="t" />
            <FlashMessage />

            <p class="small text-muted mb-3" data-test="rules-intro">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.rules_intro }}
            </p>

            <RulesSettingsCard :settings="settings" :action="routes.settings" :t="t" />

            <div class="d-flex flex-wrap align-items-end gap-3 mb-3" data-test="rules-filters">
                <div class="rules__filter">
                    <label :for="ids.doctor" class="form-label small mb-1">{{ t.filter_doctor }}</label>
                    <select
                        :id="ids.doctor"
                        class="form-select form-select-sm"
                        :value="filters.doctor ?? ''"
                        data-test="filter-doctor"
                        @change="applyFilters({ doctor: $event.target.value })"
                    >
                        <option value="">{{ t.filter_doctor_any }}</option>
                        <option :value="DOCTOR_GENERAL">{{ t.all_doctors }}</option>
                        <option v-for="doctor in options.doctors ?? []" :key="doctor.id" :value="doctor.id">{{ doctorLabel(doctor) }}</option>
                    </select>
                </div>
                <div class="rules__filter">
                    <label :for="ids.type" class="form-label small mb-1">{{ t.filter_service_type }}</label>
                    <select
                        :id="ids.type"
                        class="form-select form-select-sm"
                        :value="filters.service_type ?? ''"
                        data-test="filter-type"
                        @change="applyFilters({ service_type: $event.target.value })"
                    >
                        <option value="">{{ t.filter_service_type_all }}</option>
                        <option v-for="type in SERVICE_TYPES" :key="type" :value="type">{{ serviceTypeLabel(type) }}</option>
                    </select>
                </div>
                <div class="rules__filter">
                    <label :for="ids.status" class="form-label small mb-1">{{ t.filter_status }}</label>
                    <select
                        :id="ids.status"
                        class="form-select form-select-sm"
                        :value="filters.status ?? ''"
                        data-test="filter-status"
                        @change="applyFilters({ status: $event.target.value })"
                    >
                        <option value="">{{ t.filter_status_all }}</option>
                        <option v-for="status in RULE_STATUSES" :key="status" :value="status">{{ t[status] }}</option>
                    </select>
                </div>
                <button
                    v-if="hasFilters"
                    type="button"
                    class="btn btn-link btn-sm text-decoration-none px-1"
                    data-test="filters-clear"
                    @click="applyFilters({ doctor: '', service_type: '', status: '' })"
                >
                    <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ t.filter_clear }}
                </button>
            </div>

            <RulesTable
                v-if="view === 'table'"
                :rules="rows"
                :t="t"
                :empty-text="t.rules_empty"
                @edit="openEdit"
                @delete="onDelete"
            />
            <RulesCards
                v-else
                :rules="rows"
                :t="t"
                :empty-text="t.rules_empty"
                @edit="openEdit"
                @delete="onDelete"
            />

            <TablePagination
                :data="rules"
                :showing-from="t.pagination_showing"
                :showing-of="t.pagination_of"
                :showing-suffix="t.pagination_suffix"
                :aria-label="t.pagination_label"
                :previous-label="t.pagination_previous"
                :next-label="t.pagination_next"
            />
        </div>

        <RuleFormModal
            :open="modalOpen"
            :rule="editingRule"
            :options="options"
            :routes="routes"
            :t="t"
            @close="closeModal"
        />
    </AppLayout>
</template>

<style scoped>
.rules__filter {
    min-width: 11rem;
}

@media (max-width: 575.98px) {
    .rules__filter {
        flex: 1 1 100%;
        min-width: 0;
    }
}
</style>
