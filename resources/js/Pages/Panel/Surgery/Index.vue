<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import DoctorMissingAlert from '@/Components/Panel/DoctorMissingAlert.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import CaseFormModal from '@/Components/Surgery/CaseFormModal.vue';
import SurgeryStatusBadge from '@/Components/Surgery/SurgeryStatusBadge.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Casos cirúrgicos: do pedido à alta. Filtro por grupo de estado (com
 * contagem), busca por paciente/código/título e por cirurgião. O servidor
 * recorta o que cada perfil vê (o médico só os casos dele).
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    cases: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    doctorMissing: { type: Boolean, default: false },
    doctorMissingText: { type: String, default: '' },
    urls: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const { number, dateTime } = useLocaleFormat();
const ui = computed(() => props.t.ui?.index ?? {});

const GROUPS = ['all', 'draft', 'awaiting', 'authorized', 'scheduled', 'performed', 'closed'];

const search = ref(props.filters.q ?? '');
const surgeon = ref(props.filters.surgeon ?? '');
const creating = ref(false);

let timer = null;

function visit(extra = {}) {
    router.get(
        props.urls.index,
        {
            group: props.filters.group === 'all' ? undefined : props.filters.group,
            q: search.value.trim() || undefined,
            surgeon: surgeon.value || undefined,
            ...extra,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['cases', 'counts', 'filters'] },
    );
}

watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => visit({ page: undefined }), 350);
});

watch(surgeon, () => visit({ page: undefined }));

onBeforeUnmount(() => clearTimeout(timer));

function setGroup(group) {
    visit({ group: group === 'all' ? undefined : group, page: undefined });
}

const authTone = (row) => {
    if (row.authorization.expired) return 'danger';
    return (
        {
            authorized: 'success',
            partially_authorized: 'info',
            waived: 'secondary',
            denied: 'danger',
            pending: 'warning',
        }[row.authorization.status] ?? 'secondary'
    );
};
</script>

<template>
    <Head :title="t.title" />

    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.title" :subtitle="t.subtitle">
                <template #actions>
                    <button
                        v-if="can.manage && !doctorMissing"
                        type="button"
                        class="btn btn-primary btn-sm"
                        data-testid="surgery-new"
                        @click="creating = true"
                    >
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ ui.new }}
                    </button>
                </template>
            </PageHeader>

            <DoctorMissingAlert v-if="doctorMissing" :text="doctorMissingText" />

            <template v-else>
                <ul class="nav nav-pills mb-3 flex-wrap gap-1" :aria-label="ui.filter_label">
                    <li v-for="group in GROUPS" :key="group" class="nav-item">
                        <button
                            type="button"
                            class="nav-link py-1"
                            :class="{ active: (filters.group ?? 'all') === group }"
                            :aria-current="(filters.group ?? 'all') === group ? 'true' : undefined"
                            :data-testid="`surgery-group-${group}`"
                            @click="setGroup(group)"
                        >
                            {{ ui[`group_${group}`] }}
                            <span class="badge text-bg-light ms-1">{{ number(counts[group] ?? 0) }}</span>
                        </button>
                    </li>
                </ul>

                <div class="row g-2 mb-3">
                    <div class="col-md-6 col-lg-5">
                        <label class="visually-hidden" for="surgery-search">{{ ui.search_label }}</label>
                        <input
                            id="surgery-search"
                            v-model="search"
                            type="search"
                            class="form-control"
                            :placeholder="ui.search_placeholder"
                            data-testid="surgery-search"
                        />
                    </div>
                    <div v-if="options.surgeons?.length > 1" class="col-md-4 col-lg-3">
                        <label class="visually-hidden" for="surgery-surgeon-filter">{{ ui.surgeon_filter }}</label>
                        <select id="surgery-surgeon-filter" v-model="surgeon" class="form-select">
                            <option value="">{{ ui.all_surgeons }}</option>
                            <option v-for="d in options.surgeons" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="!cases.data.length" class="card" data-testid="surgery-empty">
                    <div class="card-body text-center py-5">
                        <i class="ti ti-heartbeat fs-1 text-muted d-block mb-2" aria-hidden="true"></i>
                        <p class="fw-semibold mb-1">{{ ui.empty }}</p>
                        <p class="text-muted small mb-0">{{ ui.empty_hint }}</p>
                    </div>
                </div>

                <div v-else class="card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <caption class="visually-hidden">
                                {{
                                    t.title
                                }}
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ ui.col_code }}</th>
                                    <th scope="col">{{ ui.col_patient }}</th>
                                    <th scope="col">{{ ui.col_surgery }}</th>
                                    <th scope="col">{{ ui.col_surgeon }}</th>
                                    <th scope="col">{{ ui.col_status }}</th>
                                    <th scope="col">{{ ui.col_authorization }}</th>
                                    <th scope="col">{{ ui.col_date }}</th>
                                    <th scope="col">
                                        <span class="visually-hidden">{{ ui.col_actions }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in cases.data" :key="row.id" data-testid="surgery-row">
                                    <td class="text-nowrap">{{ row.code }}</td>
                                    <td>{{ row.patient.name }}</td>
                                    <td>
                                        {{ row.title }}
                                        <span class="badge text-bg-light ms-1">{{ row.eye.short }}</span>
                                        <span v-if="row.urgent" class="badge text-bg-danger ms-1">{{ ui.urgent }}</span>
                                        <span
                                            v-if="row.severe_complication"
                                            class="badge text-bg-danger ms-1"
                                            data-testid="surgery-severe"
                                            >{{ ui.severe }}</span
                                        >
                                    </td>
                                    <td>{{ row.surgeon.name }}</td>
                                    <td><SurgeryStatusBadge :status="row.status" /></td>
                                    <td>
                                        <span class="badge" :class="`text-bg-${authTone(row)}`">{{
                                            row.authorization.label
                                        }}</span>
                                    </td>
                                    <td class="text-nowrap">
                                        <template v-if="row.scheduled_start">
                                            {{ dateTime(row.scheduled_start) }}
                                            <div v-if="row.room" class="small text-muted">
                                                {{ row.facility }} · {{ row.room }}
                                            </div>
                                        </template>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="text-end">
                                        <Link
                                            :href="route('panel.surgery.show', row.id)"
                                            class="btn btn-sm btn-outline-primary"
                                            data-testid="surgery-open"
                                        >
                                            {{ ui.open }}
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <TablePagination
                    :data="cases"
                    :showing-from="ui.showing"
                    :showing-of="ui.of"
                    :showing-suffix="ui.suffix"
                />
            </template>
        </div>

        <CaseFormModal :open="creating" :options="options" :can="can" :urls="urls" :t="t" @close="creating = false" />
    </AppLayout>
</template>
