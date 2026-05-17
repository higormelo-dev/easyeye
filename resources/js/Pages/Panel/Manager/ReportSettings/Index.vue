<script setup>
import { ref, watch } from 'vue';
import { router }               from '@inertiajs/vue3';
import AppLayout                from '@/Layouts/AppLayout.vue';
import PageHeader               from '@/Components/Panel/PageHeader.vue';
import SearchInput              from '@/Components/Panel/SearchInput.vue';
import ReportSettingTable       from './ReportSettingTable.vue';
import ReportSettingCards       from './ReportSettingCards.vue';
import ReportSettingFormModal   from './ReportSettingFormModal.vue';
import ReportSettingPreviewModal from './ReportSettingPreviewModal.vue';

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
            route('panel.manager.report-settings.index'),
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
        route('panel.manager.report-settings.index'),
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

// ── Publish ───────────────────────────────────────────────────────────────────
async function onPublish(r) {
    if (!confirm(props.t.confirm_publish)) return;
    await postAction(r.publish_url, props.t.action_publish);
}

// ── Archive ───────────────────────────────────────────────────────────────────
async function onArchive(r) {
    if (!confirm(props.t.confirm_archive)) return;
    await postAction(r.archive_url, props.t.action_archive);
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function onDelete(r) {
    if (!confirm(props.t.confirm_delete)) return;

    const res = await fetch(route('panel.manager.report-settings.destroy', r.id), {
        method: 'DELETE',
        headers: {
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
    });

    const json = await res.json();
    if (res.ok) {
        showToast(json.message, 'success');
        router.reload({ only: ['reportSettings', 'total'] });
    } else {
        showToast(json.message ?? 'Erro', 'error');
    }
}

async function postAction(url, label) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
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
                :total-label="t.total_label"
                :view="view"
                :view-table-title="t.view_table"
                :view-cards-title="t.view_cards"
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

                <select
                    v-model="statusFilter"
                    class="form-select form-select-sm"
                    style="max-width:180px;"
                >
                    <option value="">{{ t.all_statuses }}</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>

                <select
                    v-model="categoryFilter"
                    class="form-select form-select-sm"
                    style="max-width:200px;"
                >
                    <option value="">{{ t.all_categories }}</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
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

    </AppLayout>
</template>
