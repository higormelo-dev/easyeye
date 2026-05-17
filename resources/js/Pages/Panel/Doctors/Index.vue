<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout      from '@/Layouts/AppLayout.vue';
import DoctorTable    from './DoctorTable.vue';
import DoctorCards    from './DoctorCards.vue';
import DoctorFormModal from './DoctorFormModal.vue';

const props = defineProps({
    doctors:         { type: Object, required: true },
    totalDoctors:    { type: Number, default: 0 },
    genders:         { type: Object, default: () => ({}) },
    maritalStatuses: { type: Object, default: () => ({}) },
    statesOfBrazil:  { type: Object, default: () => ({}) },
    filters:         { type: Object, default: () => ({}) },
});

// ── View toggle ──────────────────────────────────────────────────────────────
const view = ref(localStorage.getItem('doctors_view') ?? 'table');
function setView(v) { view.value = v; localStorage.setItem('doctors_view', v); }

// ── Search ───────────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '');
let searchTimer = null;
watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            route('panel.doctors.index'),
            { search: val, sort: props.filters.sort, direction: props.filters.direction },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 400);
});

function onSort({ sort, direction }) {
    router.get(
        route('panel.doctors.index'),
        { search: search.value, sort, direction },
        { preserveState: true, preserveScroll: true },
    );
}

// ── CRUD modal ───────────────────────────────────────────────────────────────
const modalOpen   = ref(false);
const editDoctorId = ref(null);

function openCreate() { editDoctorId.value = null; modalOpen.value = true; }
function openEdit(id) { editDoctorId.value = id;   modalOpen.value = true; }
function closeModal()  { modalOpen.value = false; editDoctorId.value = null; }

// ── Actions ──────────────────────────────────────────────────────────────────
function onDelete(id) {
    if (!confirm('Tem certeza que deseja excluir este médico?')) return;
    router.delete(route('panel.doctors.destroy', id), { preserveScroll: true });
}

function onToggleActive(id, currentActive) {
    router.put(
        route('panel.doctors.update', id),
        { active: !currentActive, type_method: 'toggle' },
        { preserveScroll: true },
    );
}

const breadcrumbs = [
    { label: 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: 'Médicos', url: '#', active: true },
];
</script>

<template>
    <AppLayout title="Médicos" :breadcrumbs="breadcrumbs">
        <div>

            <!-- ── Page Header ──────────────────────────────────────────── -->
            <div class="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-3 border-bottom">
                <div class="flex-grow-1">
                    <h4 class="fw-bold mb-0">
                        Médicos
                        <span class="badge badge-soft-primary fw-medium border py-1 px-2 border-primary fs-13 ms-1">
                            Total: {{ totalDoctors }}
                        </span>
                    </h4>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="bg-white border shadow-sm rounded px-1 d-flex align-items-center">
                        <button
                            type="button" class="rounded p-1 d-flex align-items-center border-0"
                            :class="view === 'table' ? 'bg-light' : 'bg-white'"
                            @click="setView('table')" title="Tabela"
                        ><i class="ti ti-list fs-14 text-body"></i></button>
                        <button
                            type="button" class="rounded p-1 d-flex align-items-center border-0"
                            :class="view === 'cards' ? 'bg-light' : 'bg-white'"
                            @click="setView('cards')" title="Cards"
                        ><i class="ti ti-layout-grid fs-14 text-body"></i></button>
                    </div>
                    <button type="button" class="btn btn-primary fs-13 btn-md" @click="openCreate">
                        <i class="ti ti-plus me-1"></i> Novo médico
                    </button>
                </div>
            </div>

            <!-- ── Search ───────────────────────────────────────────────── -->
            <div class="mb-3">
                <div class="input-group input-group-sm" style="max-width:360px;">
                    <span class="input-group-text bg-white"><i class="ti ti-search fs-12"></i></span>
                    <input
                        v-model="search" type="text" class="form-control border-start-0"
                        placeholder="Buscar por nome, código ou CRM..."
                    >
                    <button v-if="search" class="btn btn-outline-secondary border-start-0" type="button" @click="search = ''">
                        <i class="ti ti-x fs-12"></i>
                    </button>
                </div>
            </div>

            <!-- ── Table / Cards ─────────────────────────────────────────── -->
            <DoctorTable
                v-if="view === 'table'"
                :doctors="doctors"
                :filters="filters"
                @sort="onSort"
                @edit="openEdit"
                @delete="onDelete"
                @toggle-active="onToggleActive"
            />
            <DoctorCards
                v-else
                :cards-url="route('panel.doctors.cards')"
                :initial-search="search"
                @edit="openEdit"
                @delete="onDelete"
                @toggle-active="onToggleActive"
            />
        </div>

        <DoctorFormModal
            :open="modalOpen"
            :doctor-id="editDoctorId"
            :genders="genders"
            :marital-statuses="maritalStatuses"
            :states-of-brazil="statesOfBrazil"
            @close="closeModal"
        />
    </AppLayout>
</template>
