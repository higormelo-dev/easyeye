<script setup>
import { ref, computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PatientTable    from './PatientTable.vue';
import PatientCards    from './PatientCards.vue';
import PatientFormModal from './PatientFormModal.vue';
import PatientDetailDrawer from './PatientDetailDrawer.vue';

const props = defineProps({
    patients:        { type: Object, required: true },
    totalPatients:   { type: Number, default: 0 },
    covenants:       { type: Array,  default: () => [] },
    skinTypes:       { type: Array,  default: () => [] },
    irisTypes:       { type: Array,  default: () => [] },
    genders:         { type: Object, default: () => ({}) },
    maritalStatuses: { type: Object, default: () => ({}) },
    statesOfBrazil:  { type: Object, default: () => ({}) },
    filters:         { type: Object, default: () => ({}) },
});

// ── View toggle ──────────────────────────────────────────────────────────────
const view = ref(localStorage.getItem('patients_view') ?? 'table');

function setView(v) {
    view.value = v;
    localStorage.setItem('patients_view', v);
}

// ── Search ───────────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '');
let searchTimer = null;

watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => performSearch(val), 400);
});

function performSearch(val) {
    router.get(
        route('panel.patients.index'),
        { search: val, sort: props.filters.sort, direction: props.filters.direction },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function clearSearch() {
    search.value = '';
}

// ── Sort ─────────────────────────────────────────────────────────────────────
function onSort({ sort, direction }) {
    router.get(
        route('panel.patients.index'),
        { search: search.value, sort, direction },
        { preserveState: true, preserveScroll: true },
    );
}

// ── CRUD modal ───────────────────────────────────────────────────────────────
const modalOpen    = ref(false);
const editPatientId = ref(null);

function openCreate() {
    editPatientId.value = null;
    modalOpen.value     = true;
}

function openEdit(id) {
    editPatientId.value = id;
    modalOpen.value     = true;
}

function closeModal() {
    modalOpen.value     = false;
    editPatientId.value = null;
}

// ── Detail drawer ────────────────────────────────────────────────────────────
const detailOpen      = ref(false);
const viewPatientId   = ref(null);

function onView(id) {
    viewPatientId.value = id;
    detailOpen.value    = true;
}

function closeDetail() {
    detailOpen.value    = false;
    viewPatientId.value = null;
}

function onDelete(id) {
    if (!confirm(window.translations?.messages?.delete_confirm_text ?? 'Tem certeza que deseja excluir?')) return;
    router.delete(route('panel.patients.destroy', id), {
        preserveScroll: true,
    });
}

function onToggleActive(id, currentActive) {
    router.put(
        route('panel.patients.update', id),
        { active: !currentActive, type_method: 'toggle' },
        { preserveScroll: true },
    );
}

function onRestore(id) {
    router.get(route('panel.patients.restore', id), {}, { preserveScroll: true });
}

const breadcrumbs = [
    { label: 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: 'Pacientes', url: '#', active: true },
];
</script>

<template>
    <AppLayout title="Pacientes" :breadcrumbs="breadcrumbs">
        <div class="page-patients">

            <!-- ── Page Header ───────────────────────────────────────────── -->
            <div class="d-flex align-items-center gap-2 pb-3 mb-3 border-bottom">
                <div class="d-flex align-items-center gap-2 me-auto">
                    <h4 class="mb-0 fw-bold">Pacientes</h4>
                    <span style="font-size:.78rem;font-weight:600;color:#0d6efd;background:#eff4ff;border:1.5px solid #0d6efd;border-radius:20px;padding:2px 12px;white-space:nowrap;line-height:1.6;">Total: {{ patients.total }}</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <!-- View toggle -->
                    <div class="bg-white border shadow-sm rounded px-1 d-flex align-items-center">
                        <button
                            type="button"
                            class="rounded p-1 d-flex align-items-center justify-content-center border-0"
                            :class="view === 'table' ? 'bg-light' : 'bg-white'"
                            title="Visualização em tabela"
                            @click="setView('table')"
                        >
                            <i class="ti ti-list fs-14 text-body"></i>
                        </button>
                        <button
                            type="button"
                            class="rounded p-1 d-flex align-items-center justify-content-center border-0"
                            :class="view === 'cards' ? 'bg-light' : 'bg-white'"
                            title="Visualização em cards"
                            @click="setView('cards')"
                        >
                            <i class="ti ti-layout-grid fs-14 text-body"></i>
                        </button>
                    </div>
                    <!-- New patient -->
                    <button type="button" class="btn btn-primary fs-13 btn-md" @click="openCreate">
                        <i class="ti ti-plus me-1"></i> Novo paciente
                    </button>
                </div>
            </div>
            <!-- ── /Page Header ──────────────────────────────────────────── -->

            <!-- ── Search bar ────────────────────────────────────────────── -->
            <div class="d-flex align-items-center justify-content-between flex-wrap mb-3 gap-2">
                <div class="table-search">
                    <div class="input-group input-group-sm" style="min-width:280px;">
                        <span class="input-group-text bg-white">
                            <i class="ti ti-search fs-12"></i>
                        </span>
                        <input
                            v-model="search"
                            type="text"
                            class="form-control border-start-0"
                            placeholder="Buscar por nome, código ou telefone..."
                        >
                        <button
                            v-if="search"
                            class="btn btn-outline-secondary border-start-0"
                            type="button"
                            @click="clearSearch"
                        >
                            <i class="ti ti-x fs-12"></i>
                        </button>
                    </div>
                </div>
            </div>
            <!-- ── /Search bar ───────────────────────────────────────────── -->

            <!-- ── Table view ────────────────────────────────────────────── -->
            <PatientTable
                v-if="view === 'table'"
                :patients="patients"
                :filters="filters"
                @sort="onSort"
                @edit="openEdit"
                @view="onView"
                @delete="onDelete"
                @toggle-active="onToggleActive"
                @restore="onRestore"
            />
            <!-- ── /Table view ───────────────────────────────────────────── -->

            <!-- ── Cards view ────────────────────────────────────────────── -->
            <PatientCards
                v-else
                :cards-url="route('panel.patients.cards')"
                :initial-search="search"
                @edit="openEdit"
                @view="onView"
                @delete="onDelete"
                @toggle-active="onToggleActive"
                @restore="onRestore"
            />
            <!-- ── /Cards view ───────────────────────────────────────────── -->

        </div>

        <!-- ── Patient Form Offcanvas ────────────────────────────────────── -->
        <PatientFormModal
            :open="modalOpen"
            :patient-id="editPatientId"
            :covenants="covenants"
            :skin-types="skinTypes"
            :iris-types="irisTypes"
            :genders="genders"
            :marital-statuses="maritalStatuses"
            :states-of-brazil="statesOfBrazil"
            @close="closeModal"
        />

        <!-- ── Patient Detail Drawer ─────────────────────────────────────── -->
        <PatientDetailDrawer
            :open="detailOpen"
            :patient-id="viewPatientId"
            @close="closeDetail"
        />
    </AppLayout>
</template>
