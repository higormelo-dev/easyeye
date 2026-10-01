<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import DoctorTable from './DoctorTable.vue';
import DoctorCards from './DoctorCards.vue';
import DoctorFormModal from './DoctorFormModal.vue';
import DoctorInvitationsPending from './DoctorInvitationsPending.vue';
import DoctorDetailDrawer from './DoctorDetailDrawer.vue';

/**
 * Listagem de médicos — mesmo layout de Panel/Patients/Index: cabeçalho com
 * total, alternância tabela/cards (persistida no navegador), importar, novo,
 * busca que preserva a ordenação e tabela/cards com as mesmas ações.
 * Textos vêm de lang/{locale}/doctors.php (prop `t`).
 */
const props = defineProps({
    doctors: { type: Object, required: true },
    totalDoctors: { type: Number, default: 0 },
    genders: { type: Object, default: () => ({}) },
    maritalStatuses: { type: Object, default: () => ({}) },
    statesOfBrazil: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) }, // { search, sort, direction }
    t: { type: Object, default: () => ({}) },
    // Convites a médicos que já têm login no EasyEye, aguardando aceite.
    pendingInvitations: { type: Array, default: () => [] },
});

// ── View toggle (preferência no navegador) ───────────────────────────────────
const VIEW_KEY = 'doctors_view';

function readView() {
    try {
        return window.localStorage.getItem(VIEW_KEY) === 'cards' ? 'cards' : 'table';
    } catch {
        return 'table';
    }
}

const view = ref(typeof window === 'undefined' ? 'table' : readView());

function setView(v) {
    view.value = v;
    try {
        window.localStorage.setItem(VIEW_KEY, v);
    } catch {
        // Storage bloqueado/privado — a preferência só não persiste.
    }
}

// ── Busca (debounce) e ordenação — uma preserva a outra ─────────────────────
const search = ref(props.filters.search ?? '');
let searchTimer = null;

function visit(params, options = {}) {
    router.get(route('panel.doctors.index'), params, { preserveState: true, preserveScroll: true, ...options });
}

watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        visit({ search: val, sort: props.filters.sort, direction: props.filters.direction }, { replace: true });
    }, 400);
});

onBeforeUnmount(() => clearTimeout(searchTimer));

function onSort({ sort, direction }) {
    visit({ search: search.value, sort, direction });
}

// ── CRUD modal ───────────────────────────────────────────────────────────────
const modalOpen = ref(false);
const editDoctorId = ref(null);

function openCreate() {
    editDoctorId.value = null;
    modalOpen.value = true;
}
function openEdit(id) {
    editDoctorId.value = id;
    modalOpen.value = true;
}
function closeModal() {
    modalOpen.value = false;
    editDoctorId.value = null;
}

// ── Detail drawer ────────────────────────────────────────────────────────────
const detailOpen = ref(false);
const viewDoctorId = ref(null);

function onView(id) {
    viewDoctorId.value = id;
    detailOpen.value = true;
}

function closeDetail() {
    detailOpen.value = false;
    viewDoctorId.value = null;
}

// ── Actions ──────────────────────────────────────────────────────────────────
function onDelete(id) {
    if (!confirm(props.t.confirm_delete ?? 'Tem certeza que deseja excluir este médico?')) return;
    router.delete(route('panel.doctors.destroy', id), { preserveScroll: true });
}

function onToggleActive(id, currentActive) {
    router.put(
        route('panel.doctors.update', id),
        { active: !currentActive, type_method: 'toggle' },
        { preserveScroll: true },
    );
}

const pageTitle = computed(() => props.t.page_title ?? 'Médicos');

const breadcrumbs = computed(() => [
    { label: props.t.breadcrumb_dashboard ?? 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: pageTitle.value, url: '#', active: true },
]);
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="page-doctors">
            <PageHeader
                :title="pageTitle"
                :total="doctors.total ?? 0"
                :total-label="t.total_label ?? 'Total:'"
                show-view-toggle
                :view="view"
                :view-table-title="t.view_table ?? 'Tabela'"
                :view-cards-title="t.view_cards ?? 'Cards'"
                @set-view="setView"
            >
                <template #actions>
                    <div class="d-flex align-items-center gap-2">
                        <Link
                            :href="route('panel.doctors.import.index')"
                            class="btn btn-outline-secondary fs-13 btn-md"
                        >
                            <i class="ti ti-upload me-1" aria-hidden="true"></i> {{ t.btn_import ?? 'Importar' }}
                        </Link>
                        <button type="button" class="btn btn-primary fs-13 btn-md" @click="openCreate">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i> {{ t.btn_new ?? 'Novo médico' }}
                        </button>
                    </div>
                </template>
            </PageHeader>

            <SearchInput
                v-model="search"
                :placeholder="t.search_placeholder ?? 'Buscar...'"
                :clear-label="t.search_clear ?? 'Limpar busca'"
                max-width="280px"
            />

            <DoctorTable
                v-if="view === 'table'"
                :doctors="doctors"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @view="onView"
                @edit="openEdit"
                @delete="onDelete"
                @toggle-active="onToggleActive"
            />
            <DoctorCards
                v-else
                :cards-url="route('panel.doctors.cards')"
                :search="filters.search ?? ''"
                :t="t"
                @view="onView"
                @edit="openEdit"
                @delete="onDelete"
                @toggle-active="onToggleActive"
            />
        </div>

        <DoctorInvitationsPending v-if="pendingInvitations.length" :invitations="pendingInvitations" :t="t" />

        <DoctorFormModal
            :open="modalOpen"
            :doctor-id="editDoctorId"
            :genders="genders"
            :marital-statuses="maritalStatuses"
            :states-of-brazil="statesOfBrazil"
            :t="t"
            @close="closeModal"
        />

        <DoctorDetailDrawer :open="detailOpen" :doctor-id="viewDoctorId" @close="closeDetail" />
    </AppLayout>
</template>
