<script setup>
import { ref, computed, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { fill } from '@/Support/clinicalDocuments.js';

/**
 * Lista de rascunhos de laudo (Fase 5 — fluxo secretária-rascunha /
 * médico-emite). Sem granularidade de "dono" (camada leve pedida pelo
 * dono): qualquer admin/doctor/secretary da clínica vê e edita o rascunho
 * de um colega — ver ExamManagerReportDraftController.
 *
 * "Abrir" reaproveita o MESMO ExamManagerReportModal.vue (prop draft) — este
 * componente só lista e dispara abrir/descartar, nunca edita conteúdo.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    urls: { type: Object, required: true }, // { index, destroy } — destroy com __ID__
    isDoctor: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'open-draft', 'draft-discarded']);

function tt(key, fallback = '') {
    return props.t?.[key] ?? fallback;
}

const drafts = ref([]);
const loading = ref(false);
const loadingMore = ref(false);
const error = ref('');
const page = ref(1);
const hasMore = ref(false);
// Médico quer ver primeiro o que está esperando revisão; secretária/admin
// acompanham o próprio trabalho em qualquer estágio.
const statusFilter = ref('');
// "Só os meus": médico vê o que está endereçado a ele; quem prepara, o que
// preparou (o servidor decide pelo perfil). Desligado = a clínica inteira.
const onlyMine = ref(false);

const EDITABLE_STATUSES = ['draft', 'submitted', 'returned'];
const isEditableStatus = (status) => EDITABLE_STATUSES.includes(status);

// Incrementado a cada chamada — identifica a MAIS NOVA. Trocar o filtro de
// status rápido (ou abrir o modal e trocar antes da 1ª resposta chegar)
// dispara 2 GETs; se a rede devolver fora de ordem, a resposta velha
// sobrescrevia drafts.value com a lista de um filtro ULTRAPASSADO, mesmo
// com o <select> já mostrando o filtro novo — mesmo princípio do
// previewRequestId em ExamManagerReportModal.vue (achado via revisão Codex).
let draftsRequestId = 0;

async function fetchDrafts({ reset = true, page: requestedPage = 1 } = {}) {
    if (!props.urls.index) return;
    const requestId = ++draftsRequestId;
    if (reset) loading.value = true;
    else loadingMore.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(props.urls.index, {
            params: {
                ...(statusFilter.value ? { status: statusFilter.value } : {}),
                ...(onlyMine.value ? { mine: 1 } : {}),
                page: requestedPage,
            },
        });
        if (requestId !== draftsRequestId) return; // resposta desatualizada — um filtro/página mais nova já venceu
        const rows = data?.data ?? [];
        drafts.value = reset ? rows : [...drafts.value, ...rows];
        page.value = data?.meta?.current_page ?? requestedPage;
        hasMore.value = !!data?.meta?.has_more;
    } catch {
        if (requestId !== draftsRequestId) return; // idem, erro de uma chamada já superada
        if (reset) drafts.value = []; // "carregar mais" que falha preserva o que já estava na tela
        error.value = tt('drafts_load_failed', 'Não foi possível carregar os rascunhos.');
    } finally {
        // Só a chamada AINDA atual pode desligar o loading — senão o
        // finally de uma chamada velha resolvendo depois da nova reabilitava
        // a tela com a lista desatualizada ainda em voo.
        if (requestId === draftsRequestId) {
            loading.value = false;
            loadingMore.value = false;
        }
    }
}

function loadMoreDrafts() {
    if (!hasMore.value || loadingMore.value || loading.value) return;
    fetchDrafts({ reset: false, page: page.value + 1 });
}

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;
        statusFilter.value = props.isDoctor ? 'submitted' : '';
        onlyMine.value = false;
        fetchDrafts();
    },
);

watch([statusFilter, onlyMine], () => {
    if (props.open) fetchDrafts();
});

function openDraft(draft) {
    emit('open-draft', draft);
}

async function discardDraft(draft) {
    const message = tt('draft_discard_text', 'O rascunho será descartado e não poderá mais ser editado ou emitido.');
    let confirmed;
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon: 'warning',
            title: tt('draft_discard_title', 'Descartar este rascunho?'),
            text: message,
            showCancelButton: true,
            confirmButtonText: tt('draft_discard_confirm', 'Descartar rascunho'),
            cancelButtonText: tt('cancel', 'Cancelar'),
            confirmButtonColor: '#dc3545',
        });
        confirmed = result.isConfirmed;
    } else {
        confirmed = window.confirm(message);
    }
    if (!confirmed) return;

    try {
        // draft_revision: backend agora rejeita descarte obsoleto (409) —
        // mesma proteção de update()/submit(), achado via revisão
        // adversarial Codex (destroy() só checava isEditable(), nunca
        // comparava revision).
        await window.axios.delete(props.urls.destroy.replace('__ID__', draft.id), {
            data: { draft_revision: draft.revision },
        });
        drafts.value = drafts.value.filter((d) => d.id !== draft.id);
        if (window.showSuccessToast) window.showSuccessToast(tt('draft_discarded', 'Rascunho descartado.'));
        emit('draft-discarded', { id: draft.id, status: draft.status });
    } catch (e) {
        if (window.showErrorToast) {
            window.showErrorToast(
                e?.response?.data?.message ?? tt('report_save_failed', 'Não foi possível descartar.'),
            );
        }
    }
}

const statusBadgeClass = (status) =>
    ({
        draft: 'bg-secondary-subtle text-secondary-emphasis',
        submitted: 'bg-warning-subtle text-warning-emphasis',
        returned: 'bg-info-subtle text-info-emphasis',
        issued: 'bg-success-subtle text-success-emphasis',
        discarded: 'bg-danger-subtle text-danger-emphasis',
    })[status] ?? 'bg-secondary-subtle text-secondary-emphasis';

const statusOptions = computed(() => [
    { value: '', label: tt('drafts_filter_all', 'Todos') },
    { value: 'draft', label: tt('draft_status_draft', 'Rascunho') },
    { value: 'submitted', label: tt('draft_status_submitted', 'Enviado para o médico') },
    { value: 'returned', label: tt('draft_status_returned', 'Devolvido ao técnico') },
    { value: 'issued', label: tt('draft_status_issued', 'Emitido') },
    { value: 'discarded', label: tt('draft_status_discarded', 'Descartado') },
]);
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-file-text me-2 text-primary"></i>{{ tt('drafts_modal_title', 'Rascunhos de laudo') }}
            </h5>
        </template>

        <template #tabs>
            <select
                v-model="statusFilter"
                class="form-select form-select-sm"
                style="max-width: 220px"
                data-testid="drafts-status-filter"
            >
                <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
            <div class="form-check form-check-inline ms-2 mb-0">
                <input
                    id="drafts-only-mine"
                    v-model="onlyMine"
                    type="checkbox"
                    class="form-check-input"
                    data-testid="drafts-only-mine"
                />
                <label class="form-check-label small" for="drafts-only-mine">{{
                    isDoctor
                        ? tt('drafts_only_mine_doctor', 'Só os meus')
                        : tt('drafts_only_mine_preparer', 'Só os que preparei')
                }}</label>
            </div>
        </template>

        <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>

        <div v-if="loading" class="text-center py-4">
            <span class="spinner-border spinner-border-sm"></span>
        </div>

        <p v-else-if="!drafts.length" class="text-muted text-center py-4 mb-0">
            {{ tt('drafts_empty', 'Nenhum rascunho por aqui.') }}
        </p>

        <ul v-else class="list-unstyled mb-0 d-flex flex-column gap-2">
            <li
                v-for="draft in drafts"
                :key="draft.id"
                class="d-flex align-items-center gap-2 border rounded px-2 py-2"
                data-testid="draft-row"
            >
                <div class="flex-grow-1 min-width-0">
                    <div class="d-flex align-items-center gap-2">
                        <span class="fw-semibold text-truncate">{{ draft.patient?.name || '—' }}</span>
                        <span class="badge rounded-pill" :class="statusBadgeClass(draft.status)">{{
                            draft.status_label
                        }}</span>
                    </div>
                    <div class="text-muted text-truncate" style="font-size: 0.74rem">
                        {{ draft.title || tt('report_template_blank', 'Em branco') }}
                        <span class="mx-1">·</span>{{ draft.updated_at }}
                        <template v-if="draft.resolved_doctor_name">
                            <span class="mx-1">·</span
                            >{{ fill(tt('draft_for_doctor', 'Para :doctor'), { doctor: draft.resolved_doctor_name }) }}
                        </template>
                    </div>
                    <div
                        v-if="draft.status === 'returned' && draft.return_reason"
                        class="text-truncate"
                        style="font-size: 0.74rem"
                        :title="draft.return_reason"
                        data-testid="draft-row-return-reason"
                    >
                        <i class="ti ti-arrow-back-up me-1"></i>{{ draft.return_reason }}
                    </div>
                </div>
                <button
                    v-if="isEditableStatus(draft.status)"
                    type="button"
                    class="btn btn-outline-primary btn-sm flex-shrink-0"
                    data-testid="draft-open-button"
                    @click="openDraft(draft)"
                >
                    {{ tt('draft_review_issue_open', 'Abrir') }}
                </button>
                <button
                    v-if="isEditableStatus(draft.status)"
                    type="button"
                    class="btn btn-outline-danger btn-sm flex-shrink-0"
                    :title="tt('draft_discard', 'Descartar rascunho')"
                    @click="discardDraft(draft)"
                >
                    <i class="ti ti-trash"></i>
                </button>
            </li>
        </ul>

        <div v-if="hasMore" class="text-center mt-2">
            <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                :disabled="loadingMore"
                @click="loadMoreDrafts"
            >
                <span v-if="loadingMore" class="spinner-border spinner-border-sm me-1"></span>
                {{ tt('drafts_load_more', 'Carregar mais') }}
            </button>
        </div>

        <template #footer>
            <button type="button" class="btn btn-secondary btn-sm" @click="$emit('close')">
                {{ tt('close', 'Fechar') }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.min-width-0 {
    min-width: 0;
}
</style>
