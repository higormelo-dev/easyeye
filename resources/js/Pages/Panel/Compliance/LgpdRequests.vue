<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import LgpdRequestFormModal from './LgpdRequestFormModal.vue';
import LgpdRequestRespondModal from './LgpdRequestRespondModal.vue';

/**
 * Pedidos de titulares (LGPD arts. 18–19). A clínica, controladora dos dados
 * dos pacientes, registra o pedido com a data em que chegou (o prazo de 15 dias
 * para confirmação e acesso conta dela — art. 19, II), acompanha o prazo,
 * conclui com a resposta dada ou recusa com justificativa, e baixa os dados do
 * paciente vinculado. Textos em lang/{locale}/lgpd_requests.php (prop `t`).
 */
const props = defineProps({
    requests: { type: Object, required: true },
    counts: { type: Object, default: () => ({ open: 0, overdue: 0 }) },
    types: { type: Array, default: () => [] },
    today: { type: String, required: true },
    t: { type: Object, default: () => ({}) },
});

const tx = (template, params = {}) =>
    Object.entries(params).reduce(
        (text, [key, value]) => text.replaceAll(`:${key}`, String(value ?? '')),
        template ?? '',
    );

const breadcrumbs = computed(() => [
    { label: props.t.breadcrumb_dashboard, url: route('panel.dashboard'), active: false },
    { label: props.t.breadcrumb_compliance, url: route('panel.reports.compliance'), active: false },
    { label: props.t.page_title, url: '#', active: true },
]);

// Situação: a cor reforça, o texto informa (MASTER.md §3).
const STATUS_BADGE = {
    pending: 'bg-warning text-dark',
    in_progress: 'bg-info text-dark',
    completed: 'bg-success',
    rejected: 'bg-danger',
    cancelled: 'bg-secondary',
};

// ── Registrar pedido ─────────────────────────────────────────────────────────
const createOpen = ref(false);

function openCreate() {
    createOpen.value = true;
}

// ── Andamento: iniciar, concluir, recusar ───────────────────────────────────
const respondOpen = ref(false);
const respondTarget = ref(null);
const respondAction = ref('complete');
const startingId = ref(null);

// Erro de andamento (ex.: pedido já encerrado por outra pessoa) vem em `errors.action`.
const page = usePage();
const actionError = computed(() => (respondOpen.value ? '' : (page.props?.errors?.action ?? '')));

function start(request) {
    if (startingId.value) return;
    startingId.value = request.id;
    router.patch(
        route('panel.reports.compliance.lgpd-requests.update', request.id),
        { action: 'start' },
        {
            preserveScroll: true,
            onFinish: () => {
                startingId.value = null;
            },
        },
    );
}

function openRespond(request, action) {
    respondTarget.value = request;
    respondAction.value = action;
    respondOpen.value = true;
}
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <div class="page-lgpd-requests" data-ee-density="default">
            <PageHeader title-tag="h1" :title="t.page_title" :subtitle="t.subtitle">
                <template #actions>
                    <button type="button" class="btn btn-primary" data-test="lgpd-new" @click="openCreate">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.btn_new }}
                    </button>
                </template>
            </PageHeader>

            <p class="d-flex flex-wrap gap-2 mb-3" data-test="lgpd-counts">
                <span class="badge bg-secondary">{{ tx(t.open_count, { count: counts.open }) }}</span>
                <span v-if="counts.overdue" class="badge bg-danger" data-test="lgpd-overdue-count">
                    {{ tx(t.overdue_count, { count: counts.overdue }) }}
                </span>
            </p>

            <div v-if="actionError" class="alert alert-danger" role="alert" data-test="lgpd-action-error">
                {{ actionError }}
            </div>

            <div v-if="!requests.data?.length" class="alert alert-light border" data-test="lgpd-empty">
                {{ t.empty }}
            </div>

            <div v-else class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ t.columns?.requested_at }}</th>
                            <th scope="col">{{ t.columns?.requester }}</th>
                            <th scope="col">{{ t.columns?.type }}</th>
                            <th scope="col">{{ t.columns?.deadline }}</th>
                            <th scope="col">{{ t.columns?.status }}</th>
                            <th scope="col">{{ t.columns?.actions }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in requests.data" :key="r.id" :data-test="`lgpd-row-${r.id}`">
                            <td class="text-nowrap">{{ r.requested_at }}</td>
                            <td>
                                <div class="fw-semibold">{{ r.requester_name }}</div>
                                <div class="small text-muted text-break">{{ r.requester_email }}</div>
                                <div class="small">
                                    {{ r.patient ? `${r.patient.name} (${r.patient.code})` : t.no_patient }}
                                </div>
                            </td>
                            <td>
                                <div>{{ t.types?.[r.type] ?? r.type }}</div>
                                <div class="small text-muted text-break">{{ r.description }}</div>
                                <div v-if="r.response || r.rejection_reason" class="small mt-1 text-break">
                                    <span class="fw-semibold"
                                        >{{ r.response ? t.respond?.complete_label : t.respond?.reject_label }}:</span
                                    >
                                    {{ r.response || r.rejection_reason }}
                                </div>
                                <div v-if="r.responded_at" class="small text-muted">
                                    {{ tx(t.responded, { date: r.responded_at, name: r.responded_by ?? '—' }) }}
                                </div>
                            </td>
                            <td class="text-nowrap">
                                {{ r.deadline_at }}
                                <span v-if="r.overdue" class="badge bg-danger ms-1" data-test="lgpd-overdue">
                                    {{ t.overdue }}
                                </span>
                            </td>
                            <td>
                                <span class="badge" :class="STATUS_BADGE[r.status] ?? 'bg-secondary'">
                                    {{ t.statuses?.[r.status] ?? r.status }}
                                </span>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button
                                        v-if="r.status === 'pending'"
                                        type="button"
                                        class="btn btn-outline-primary"
                                        data-test="lgpd-start"
                                        :disabled="startingId !== null"
                                        :aria-busy="startingId === r.id ? 'true' : undefined"
                                        @click="start(r)"
                                    >
                                        {{ t.actions?.start }}
                                    </button>
                                    <button
                                        v-if="r.open"
                                        type="button"
                                        class="btn btn-outline-success"
                                        data-test="lgpd-complete"
                                        @click="openRespond(r, 'complete')"
                                    >
                                        {{ t.actions?.complete }}
                                    </button>
                                    <a
                                        v-if="r.export_url"
                                        :href="r.export_url"
                                        class="btn btn-outline-secondary"
                                        data-test="lgpd-export"
                                    >
                                        <i class="ti ti-download me-1" aria-hidden="true"></i>{{ t.actions?.export }}
                                    </a>
                                    <button
                                        v-if="r.open"
                                        type="button"
                                        class="btn btn-outline-danger"
                                        data-test="lgpd-reject"
                                        @click="openRespond(r, 'reject')"
                                    >
                                        {{ t.actions?.reject }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <TablePagination
                :data="requests"
                :showing-from="t.showing_from"
                :showing-of="t.showing_of"
                :showing-suffix="t.showing_suffix"
                :aria-label="t.pagination_label"
                :previous-label="t.previous"
                :next-label="t.next"
            />
        </div>

        <LgpdRequestFormModal :open="createOpen" :types="types" :today="today" :t="t" @close="createOpen = false" />
        <LgpdRequestRespondModal
            :open="respondOpen"
            :request="respondTarget"
            :action="respondAction"
            :t="t"
            @close="respondOpen = false"
        />
    </AppLayout>
</template>
