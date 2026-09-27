<script setup>
import { computed, ref, watchEffect } from 'vue';
import ActionDropdown   from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import SearchInput      from '@/Components/Panel/SearchInput.vue';
import SortableTh       from '@/Components/Panel/SortableTh.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { hasAction, isPaginator, pageRows } from './billingHelpers.js';

/**
 * Aba "Guias": código GUI, nº da guia TISS, atendimento, lote (o código do
 * lote filtra a aba Lotes), valor, glosa e pago. Guia TISS em aberto fora de
 * lote mostra "Pendência" (a pré-validação barrou) ou "Fora de lote"
 * (individual). Paginada no servidor (claims_page), com busca (paciente, GUI,
 * LOT, nº TISS) e ordenação pela whitelist do servidor — a tela só emite
 * `search`/`sort`. As ações vêm de `allowed_actions` (mesma regra das rotas):
 * recebimento/glosa como botões; "Incluir em lote", "Corrigir pendência" e
 * "Cancelar guia" (perigosa) no menu "Mais ações". Guias com recebimento
 * permitido têm checkbox (rotulado por linha) para o recebimento em lote; o
 * cabeçalho marca as da página e a seleção (no Index) atravessa as páginas.
 * Colunas secundárias somem abaixo do desktop.
 */
const props = defineProps({
    /** Paginator do Laravel ({ data, links, total... }); array simples também serve. */
    claims:          { type: [Object, Array], default: () => ({ data: [] }) },
    search:          { type: String, default: '' },
    sort:            { type: String, default: 'created' },
    direction:       { type: String, default: 'desc' },
    checkingClaimId: { type: String, default: null },
    /** Ids marcados para o recebimento em lote (de qualquer página). */
    selectedIds:     { type: Array, default: () => [] },
    t:               { type: Object, default: () => ({}) },
});

const emit = defineEmits(['check-pending', 'receive', 'deny', 'filter-batch', 'fix-pending', 'cancel', 'attach', 'toggle-select', 'toggle-select-page', 'search', 'sort']);

const { tx } = useTrans(() => props.t);
const { money, date } = useLocaleFormat();

const selectPageRef = ref(null);

const rows          = computed(() => pageRows(props.claims));
const hasPages      = computed(() => isPaginator(props.claims) && props.claims.last_page > 1);
const hasOutOfBatch = computed(() => rows.value.some((c) => c.out_of_batch));
const selectedSet   = computed(() => new Set(props.selectedIds));
const payableRows   = computed(() => rows.value.filter((c) => hasAction(c, 'pay')));
const allSelected   = computed(() => payableRows.value.length > 0 && payableRows.value.every((c) => selectedSet.value.has(c.id)));
const someSelected  = computed(() => !allSelected.value && payableRows.value.some((c) => selectedSet.value.has(c.id)));

// Estado "parcial" do checkbox do cabeçalho (só existe via JS).
watchEffect(() => {
    if (selectPageRef.value) selectPageRef.value.indeterminate = someSelected.value;
});

function positiveMoney(value) {
    return Number(value) > 0 ? money(value) : '—';
}

function hasMenu(claim) {
    return hasAction(claim, 'attach') || hasAction(claim, 'fix_pending') || hasAction(claim, 'cancel');
}

function cancelledInfo(claim) {
    const when = date(claim.cancelled_at);

    return claim.cancelled_by_name
        ? tx('cancelled_on_by', { date: when, user: claim.cancelled_by_name })
        : tx('cancelled_on', { date: when });
}
</script>

<template>
    <div class="card mb-0">
        <div class="card-header bg-transparent border-bottom d-flex align-items-center flex-wrap gap-2">
            <SearchInput
                :model-value="search"
                :placeholder="t.search_claims"
                :clear-label="t.search_clear"
                max-width="420px"
                wrapper-class="flex-grow-1"
                data-test="claims-search"
                @update:model-value="emit('search', $event)"
            />
        </div>

        <div v-if="hasOutOfBatch" class="alert alert-warning small d-flex gap-2 rounded-0 border-0 border-bottom mb-0 py-2" data-test="out-of-batch-notice">
            <i class="ti ti-package-off mt-1" aria-hidden="true"></i>
            <span>{{ t.out_of_batch_notice }}</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="billing-claims__check">
                            <input
                                ref="selectPageRef"
                                type="checkbox"
                                class="form-check-input"
                                data-test="claims-select-page"
                                :checked="allSelected"
                                :disabled="payableRows.length === 0"
                                :aria-label="t.claims_select_page"
                                :title="t.claims_select_page"
                                @change="emit('toggle-select-page')"
                            >
                        </th>
                        <SortableTh col-key="created" scope="col" :current-sort="sort" :current-dir="direction" :title="tx('sort_by', { column: t.col_guide })" @sort="emit('sort', $event)">{{ t.col_guide }}</SortableTh>
                        <th scope="col" class="d-none d-lg-table-cell">{{ t.col_tiss_number }}</th>
                        <SortableTh col-key="attendance" scope="col" :current-sort="sort" :current-dir="direction" :title="tx('sort_by', { column: t.col_attendance })" @sort="emit('sort', $event)">{{ t.col_attendance }}</SortableTh>
                        <SortableTh col-key="patient" scope="col" :current-sort="sort" :current-dir="direction" :title="tx('sort_by', { column: t.col_patient })" @sort="emit('sort', $event)">{{ t.col_patient }}</SortableTh>
                        <th scope="col" class="d-none d-xl-table-cell">{{ t.col_doctor }}</th>
                        <th scope="col" class="d-none d-lg-table-cell">{{ t.col_covenant }}</th>
                        <th scope="col" class="d-none d-lg-table-cell">{{ t.col_batch }}</th>
                        <th scope="col">{{ t.col_status }}</th>
                        <SortableTh col-key="amount" scope="col" class="text-end" :current-sort="sort" :current-dir="direction" :title="tx('sort_by', { column: t.col_amount })" @sort="emit('sort', $event)">{{ t.col_amount }}</SortableTh>
                        <th scope="col" class="text-end d-none d-lg-table-cell">{{ t.col_glosa }}</th>
                        <th scope="col" class="text-end d-none d-lg-table-cell">{{ t.col_received }}</th>
                        <th scope="col" class="text-end"><span class="visually-hidden">{{ t.col_actions }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td colspan="13" class="text-center text-muted py-5">
                            <i class="ti ti-file-off fs-1 d-block mb-2" aria-hidden="true"></i>
                            <p v-if="search" class="fw-medium mb-0" data-test="claims-no-results">{{ tx('search_no_results', { term: search }) }}</p>
                            <template v-else>
                                <p class="fw-medium mb-1">{{ t.no_claims }}</p>
                                <p class="small mb-0">{{ t.no_claims_hint }}</p>
                            </template>
                        </td>
                    </tr>
                    <tr v-for="c in rows" :key="c.id" :class="{ 'table-active': selectedSet.has(c.id) }" data-test="claim-row">
                        <td>
                            <input
                                v-if="hasAction(c, 'pay')"
                                type="checkbox"
                                class="form-check-input"
                                data-test="claim-select"
                                :checked="selectedSet.has(c.id)"
                                :aria-label="tx('claims_select_row', { code: c.code || '—', patient: c.patient_name || '—' })"
                                @change="emit('toggle-select', c)"
                            >
                        </td>
                        <td class="text-nowrap fw-semibold" data-test="claim-code">{{ c.code || '—' }}</td>
                        <td class="d-none d-lg-table-cell text-nowrap small" data-test="claim-tiss-number">
                            <span v-if="c.guide_number && c.guide_number !== c.code">{{ c.guide_number }}</span>
                            <span v-else class="text-muted">—</span>
                        </td>
                        <td class="text-nowrap small text-muted">{{ date(c.attendance_date) }}</td>
                        <td class="fw-medium">{{ c.patient_name || '—' }}</td>
                        <td class="d-none d-xl-table-cell text-muted">{{ c.doctor_name || '—' }}</td>
                        <td class="d-none d-lg-table-cell">{{ c.covenant_name || t.no_covenant }}</td>
                        <td class="d-none d-lg-table-cell small">
                            <button
                                v-if="c.batch_code && c.batch_id"
                                type="button"
                                class="btn btn-link btn-sm p-0 text-nowrap align-baseline"
                                :aria-label="tx('batch_link_label', { code: c.batch_code })"
                                :title="tx('batch_link_label', { code: c.batch_code })"
                                data-test="claim-batch"
                                @click="emit('filter-batch', c)"
                            >{{ c.batch_code }}</button>
                            <span v-else class="text-muted">—</span>
                        </td>
                        <td>
                            <span class="text-nowrap">
                                <span :class="['badge', c.status_badge]" data-test="claim-status">{{ c.status_label }}</span>
                                <span
                                    v-if="c.has_pending_guide"
                                    class="badge badge-soft-warning ms-1"
                                    :title="t.pending_badge_hint"
                                    data-test="claim-pending"
                                >
                                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.pending_badge }}
                                    <span class="visually-hidden">— {{ t.pending_badge_hint }}</span>
                                </span>
                                <span
                                    v-else-if="c.out_of_batch"
                                    class="badge badge-soft-secondary ms-1"
                                    :title="t.out_of_batch_hint"
                                    data-test="claim-out-of-batch"
                                >
                                    <i class="ti ti-package-off me-1" aria-hidden="true"></i>{{ t.out_of_batch_badge }}
                                    <span class="visually-hidden">— {{ t.out_of_batch_hint }}</span>
                                </span>
                            </span>
                            <template v-if="c.cancelled_at">
                                <small class="d-block text-muted mt-1" data-test="claim-cancelled-info">{{ cancelledInfo(c) }}</small>
                                <small
                                    v-if="c.cancel_reason"
                                    class="d-block text-muted fst-italic text-truncate billing-claims__reason"
                                    :title="c.cancel_reason"
                                    data-test="claim-cancel-reason"
                                >{{ tx('cancel_reason_value', { reason: c.cancel_reason }) }}</small>
                            </template>
                        </td>
                        <td class="text-end fw-semibold text-nowrap">{{ money(c.amount) }}</td>
                        <td class="text-end text-nowrap d-none d-lg-table-cell" :class="{ 'text-danger-emphasis': Number(c.glosa_amount) > 0 }">
                            {{ positiveMoney(c.glosa_amount) }}
                        </td>
                        <td class="text-end text-nowrap d-none d-lg-table-cell">{{ positiveMoney(c.paid_amount) }}</td>
                        <td class="text-end">
                            <div class="d-inline-flex align-items-center gap-1">
                                <ActionIconButton
                                    v-if="c.pre_validate_url"
                                    :icon="checkingClaimId === c.id ? 'spinner-border spinner-border-sm' : 'ti ti-checklist'"
                                    :title="t.action_check_pending"
                                    :disabled="checkingClaimId === c.id"
                                    data-test="check-pending"
                                    @click="emit('check-pending', c)"
                                />
                                <ActionIconButton
                                    v-if="hasAction(c, 'pay')"
                                    icon="ti ti-cash"
                                    variant="success"
                                    :title="t.action_receive"
                                    data-test="receive"
                                    @click="emit('receive', c)"
                                />
                                <ActionIconButton
                                    v-if="hasAction(c, 'deny')"
                                    icon="ti ti-receipt-off"
                                    variant="danger"
                                    :title="t.action_deny"
                                    data-test="deny"
                                    @click="emit('deny', c)"
                                />
                                <!-- data-row-actions: o Index devolve o foco a este botão ao fechar o modal. -->
                                <span v-if="hasMenu(c)" class="d-inline-flex" :data-row-actions="`claim-${c.id}`" data-test="claim-menu">
                                    <ActionDropdown
                                        :title="tx('row_actions_claim', { code: c.code })"
                                        btn-class="ee-action-icon ee-action-icon--default"
                                        :min-width="200"
                                    >
                                        <li v-if="hasAction(c, 'attach')">
                                            <button type="button" class="dropdown-item rounded-1" data-test="attach-claim" @click="emit('attach', c)">
                                                <i class="ti ti-package-import me-1" aria-hidden="true"></i>{{ t.action_attach_claim }}
                                            </button>
                                        </li>
                                        <li v-if="hasAction(c, 'fix_pending')">
                                            <button type="button" class="dropdown-item rounded-1" data-test="fix-pending" @click="emit('fix-pending', c)">
                                                <i class="ti ti-tool me-1" aria-hidden="true"></i>{{ t.action_fix_pending }}
                                            </button>
                                        </li>
                                        <li v-if="(hasAction(c, 'fix_pending') || hasAction(c, 'attach')) && hasAction(c, 'cancel')" aria-hidden="true">
                                            <hr class="dropdown-divider">
                                        </li>
                                        <li v-if="hasAction(c, 'cancel')">
                                            <button type="button" class="dropdown-item rounded-1 text-danger" data-test="cancel-claim" @click="emit('cancel', c)">
                                                <i class="ti ti-ban me-1" aria-hidden="true"></i>{{ t.action_cancel_claim }}
                                            </button>
                                        </li>
                                    </ActionDropdown>
                                </span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="hasPages" class="px-3 pb-3" data-test="claims-pagination">
            <TablePagination
                :data="claims"
                :showing-from="t.pagination_showing"
                :showing-of="t.pagination_of"
                :showing-suffix="t.pagination_suffix?.claims"
                :aria-label="tx('pagination_label', { tab: t.tab_claims })"
                :previous-label="t.pagination_previous"
                :next-label="t.pagination_next"
            />
        </div>
    </div>
</template>

<style scoped>
/* Motivo longo não estica a coluna: corta numa linha e mostra inteiro no title (e para leitor de tela). */
.billing-claims__reason {
    max-width: 16rem;
}

.billing-claims__check {
    width: 36px;
}
</style>
