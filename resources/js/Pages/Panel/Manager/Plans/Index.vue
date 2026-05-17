<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout        from '@/Layouts/AppLayout.vue';
import PageHeader       from '@/Components/Panel/PageHeader.vue';
import SearchInput      from '@/Components/Panel/SearchInput.vue';
import PlanTable        from './PlanTable.vue';
import PlanCards        from './PlanCards.vue';
import PlanFormModal    from './PlanFormModal.vue';
import PlanDetailDrawer from './PlanDetailDrawer.vue';

const props = defineProps({
    plans:         { type: Object, required: true },
    total:         { type: Number, default: 0 },
    filters:       { type: Object, default: () => ({}) },
    features:      { type: Array,  default: () => [] },
    billingCycles: { type: Array,  default: () => [] },
    t:             { type: Object, default: () => ({}) },
});

// ── View toggle ──────────────────────────────────────────────────────────────
const view = ref(localStorage.getItem('mgr_plans_view') ?? 'table');
function setView(v) { view.value = v; localStorage.setItem('mgr_plans_view', v); }

// ── Search / sort ─────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '');
let searchTimer = null;

watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            route('manager.plans.index'),
            { search: val, sort: props.filters.sort, direction: props.filters.direction },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 400);
});

function onSort({ sort, direction }) {
    router.get(
        route('manager.plans.index'),
        { search: search.value, sort, direction },
        { preserveState: true, preserveScroll: true },
    );
}

// ── Form modal ────────────────────────────────────────────────────────────────
const formOpen   = ref(false);
const editPlanId = ref(null);

function openCreate() { editPlanId.value = null; formOpen.value = true; }
function openEdit(id) { editPlanId.value = id;   formOpen.value = true; }
function closeForm()  { formOpen.value = false; editPlanId.value = null; }

// ── Detail drawer ─────────────────────────────────────────────────────────────
const detailOpen = ref(false);
const detailId   = ref(null);

function openDetail(id) { detailId.value = id; detailOpen.value = true; }
function closeDetail()  { detailOpen.value = false; detailId.value = null; }

// ── Actions ───────────────────────────────────────────────────────────────────
function onDelete(id) {
    if (!confirm(props.t.confirm_delete ?? 'Tem certeza?')) return;
    router.delete(route('manager.plans.destroy', id), { preserveScroll: true });
}

function onToggleActive(id, currentActive) {
    router.put(
        route('manager.plans.update', id),
        { active: !currentActive },
        { preserveScroll: true },
    );
}

const breadcrumbs = [
    { label: props.t.breadcrumb_home    ?? 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: props.t.breadcrumb_current ?? 'Planos',    url: '#', active: true },
];
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <div>

            <!-- ── Page Header ──────────────────────────────────────────── -->
            <PageHeader
                :title="t.page_title"
                :total="total"
                :total-label="t.total_label"
                :view="view"
                :view-table-title="t.view_table"
                :view-cards-title="t.view_cards"
                @set-view="setView"
            >
                <template #actions>
                    <button type="button" class="btn btn-primary fs-13" @click="openCreate">
                        <i class="ti ti-plus me-1"></i> {{ t.btn_new }}
                    </button>
                </template>
            </PageHeader>

            <!-- ── Search ───────────────────────────────────────────────── -->
            <SearchInput
                v-model="search"
                :placeholder="t.search_placeholder"
                max-width="320px"
            />

            <!-- ── Table / Cards ─────────────────────────────────────────── -->
            <PlanTable
                v-if="view === 'table'"
                :plans="plans"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @view="openDetail"
                @edit="openEdit"
                @delete="onDelete"
                @toggle-active="onToggleActive"
            />
            <PlanCards
                v-else
                :cards-url="route('manager.plans.cards')"
                :initial-search="search"
                :t="t"
                @view="openDetail"
                @edit="openEdit"
                @delete="onDelete"
                @toggle-active="onToggleActive"
            />
        </div>

        <!-- Form offcanvas -->
        <PlanFormModal
            :open="formOpen"
            :plan-id="editPlanId"
            :features="features"
            :billing-cycles="billingCycles"
            :t="t"
            @close="closeForm"
        />

        <!-- Detail drawer -->
        <PlanDetailDrawer
            :open="detailOpen"
            :plan-id="detailId"
            :t="t"
            @close="closeDetail"
            @edit="(id) => { closeDetail(); openEdit(id); }"
        />
    </AppLayout>
</template>
