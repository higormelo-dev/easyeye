<script setup>
import { ref, watch } from 'vue';
import { router }               from '@inertiajs/vue3';
import AppLayout                from '@/Layouts/AppLayout.vue';
import PageHeader               from '@/Components/Panel/PageHeader.vue';
import SearchInput              from '@/Components/Panel/SearchInput.vue';
import SearchSelect             from '@/Components/Panel/SearchSelect.vue';
import ReportSettingTable       from './ReportSettingTable.vue';
import ReportSettingCards       from './ReportSettingCards.vue';
import ReportSettingFormModal   from './ReportSettingFormModal.vue';
import ReportSettingPreviewModal from './ReportSettingPreviewModal.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import { useConfirmationWithReason } from '@/composables/useConfirmationWithReason.js';

const props = defineProps({
    reportSettings: { type: Object, required: true },
    total:          { type: Number, default: 0 },
    categories:     { type: Array,  default: () => [] },
    statuses:       { type: Array,  default: () => [] },
    paperSizes:     { type: Array,  default: () => [] },
    fontFamilies:   { type: Array,  default: () => [] },
    filters:        { type: Object, default: () => ({}) },
    cardsUrl:       { type: String, required: true },
    t:              { type: Object, default: () => ({}) },
});

// ── View toggle ───────────────────────────────────────────────────────────────
const view = ref(localStorage.getItem('mgr_report_settings_view') ?? 'table');
function setView(v) { view.value = v; localStorage.setItem('mgr_report_settings_view', v); }

// ── Search + filters ──────────────────────────────────────────────────────────
const search     = ref(props.filters.search      ?? '');
const statusFilter   = ref(props.filters.status      ?? '');
const categoryFilter = ref(props.filters.category_id ?? '');
let searchTimer = null;

function applyFilters() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            route('manager.report-settings.index'),
            {
                search:      search.value,
                status:      statusFilter.value,
                category_id: categoryFilter.value,
                sort:        props.filters.sort,
                direction:   props.filters.direction,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 400);
}

watch(search, applyFilters);
watch(statusFilter, applyFilters);
watch(categoryFilter, applyFilters);

function onSort({ sort, direction }) {
    router.get(
        route('manager.report-settings.index'),
        {
            search:      search.value,
            status:      statusFilter.value,
            category_id: categoryFilter.value,
            sort,
            direction,
        },
        { preserveState: true, preserveScroll: true },
    );
}

// ── Form modal ────────────────────────────────────────────────────────────────
const formOpen  = ref(false);
const recordId  = ref(null);

function openCreate() { recordId.value = null; formOpen.value = true; }
function openEdit(id) { recordId.value = id;   formOpen.value = true; }
function closeForm()  { formOpen.value = false; recordId.value = null; }

// ── Preview modal ─────────────────────────────────────────────────────────────
const previewOpen = ref(false);
const previewUrl  = ref(null);

function openPreview(r) { previewUrl.value = r.preview_url; previewOpen.value = true; }
function closePreview() { previewOpen.value = false; previewUrl.value = null; }

// ── Confirmação destrutiva com reason (LGPD/CFM) ──────────────────────────────
const { state: reasonModal, open: openReasonModal, close: closeReasonModal, handle: handleReasonConfirm } = useConfirmationWithReason();

// ── Publish ───────────────────────────────────────────────────────────────────
function onPublish(r) {
    openReasonModal({
        title: props.t.confirm_publish_title ?? 'Publicar modelo',
        message: props.t.confirm_publish ?? '',
        confirmVariant: 'primary',
        async onConfirm(reason) {
            await postActionWithReason(r.publish_url, 'POST', reason);
        },
    });
}

// ── Archive ───────────────────────────────────────────────────────────────────
function onArchive(r) {
    openReasonModal({
        title: props.t.confirm_archive_title ?? 'Arquivar modelo',
        message: props.t.confirm_archive ?? '',
        confirmVariant: 'warning',
        async onConfirm(reason) {
            await postActionWithReason(r.archive_url, 'POST', reason);
        },
    });
}

// ── Delete ────────────────────────────────────────────────────────────────────
function onDelete(r) {
    openReasonModal({
        title: props.t.confirm_delete_title ?? 'Excluir modelo',
        message: props.t.confirm_delete ?? '',
        confirmVariant: 'danger',
        async onConfirm(reason) {
            await postActionWithReason(route('manager.report-settings.destroy', r.id), 'DELETE', reason);
        },
    });
}

async function postActionWithReason(url, method, reason) {
    const res = await fetch(url, {
        method,
        headers: {
            'Accept':       'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ reason }),
    });

    const json = await res.json();
    if (res.ok) {
        showToast(json.message, 'success');
        router.reload({ only: ['reportSettings', 'total'] });
    } else {
        showToast(json.message ?? 'Erro', 'error');
    }
}

function showToast(msg, type = 'success') {
    if (type === 'success' && window.showSuccessToast) return window.showSuccessToast(msg);
    if (type === 'error'   && window.showErrorToast)   return window.showErrorToast(msg);
    alert(msg);
}

// ── Breadcrumbs ───────────────────────────────────────────────────────────────
const breadcrumbs = [
    { label: props.t.breadcrumb_home    ?? 'Dashboard',           url: route('panel.dashboard'), active: false },
    { label: props.t.breadcrumb_current ?? 'Modelos de Documento', url: '#', active: true },
];
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <div>

            <!-- ── Page Header ─────────────────────────────────────────────── -->
            <PageHeader
                :title="t.page_title"
                :total="total"
                :view="view"
                :view-table-title="t.view_table"
                :view-cards-title="t.view_cards"
                :show-view-toggle="true"
                @set-view="setView"
            >
                <template #actions>
                    <button type="button" class="btn btn-primary btn-md fs-13" @click="openCreate">
                        <i class="ti ti-plus me-1"></i> {{ t.new }}
                    </button>
                </template>
            </PageHeader>

            <!-- ── Search + Filters ────────────────────────────────────────── -->
            <div class="d-flex flex-wrap gap-2 mb-3">
                <SearchInput
                    v-model="search"
                    :placeholder="t.search_placeholder"
                    max-width="320px"
                />

                <SearchSelect
                    v-model="statusFilter"
                    :options="statuses"
                    :value-key="'value'"
                    :label-key="'label'"
                    :placeholder="t.all_statuses"
                    style="max-width:180px;"
                />

                <SearchSelect
                    v-model="categoryFilter"
                    :options="categories"
                    :placeholder="t.all_categories"
                    style="max-width:200px;"
                />
            </div>

            <!-- ── Table / Cards ─────────────────────────────────────────────── -->
            <ReportSettingTable
                v-if="view === 'table'"
                :report-settings="reportSettings"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @edit="openEdit"
                @preview="openPreview"
                @publish="onPublish"
                @archive="onArchive"
                @delete="onDelete"
            />

            <ReportSettingCards
                v-else
                :cards-url="cardsUrl"
                :initial-search="search"
                :initial-status="statusFilter"
                :initial-category="categoryFilter"
                :t="t"
                @edit="openEdit"
                @preview="openPreview"
                @publish="onPublish"
                @archive="onArchive"
                @delete="onDelete"
            />

        </div>

        <!-- Form offcanvas -->
        <ReportSettingFormModal
            :open="formOpen"
            :record-id="recordId"
            :categories="categories"
            :paper-sizes="paperSizes"
            :font-families="fontFamilies"
            :t="t"
            @close="closeForm"
            @saved="closeForm"
        />

        <!-- Preview modal -->
        <ReportSettingPreviewModal
            :open="previewOpen"
            :preview-url="previewUrl"
            :t="t"
            @close="closePreview"
        />

        <!-- Confirmação destrutiva com justificativa (LGPD/CFM) -->
        <ConfirmationWithReasonModal
            :open="reasonModal.open"
            :title="reasonModal.title"
            :message="reasonModal.message"
            :confirm-variant="reasonModal.confirmVariant"
            :saving="reasonModal.saving"
            @close="closeReasonModal"
            @confirm="handleReasonConfirm"
        />

    </AppLayout>
</template>
