<script setup>
import { computed } from 'vue';
import ActionDropdown   from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import SearchInput      from '@/Components/Panel/SearchInput.vue';
import SortableTh       from '@/Components/Panel/SortableTh.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { hasAction, isPaginator, pageRows } from './billingHelpers.js';

/**
 * Aba "Lotes": código, período, guias incluídas × pendentes, total e data de
 * envio. Lote TISS é enviado à operadora; lote particular só é "marcado como
 * cobrado" (sem XML). O envio passa por uma confirmação com resumo
 * (Index → SubmitBatchModal); "Ver guias" filtra a aba Guias pelo lote.
 * Paginada no servidor (batches_page), com busca (LOT, nº do lote TISS ou
 * uma guia do lote) e ordenação pela whitelist do servidor. Lote enviado com
 * guia a receber: "Registrar recebimento" (prévia no modal). No menu "Mais
 * ações": "Adicionar guias", "Reprocessar pendentes" e "Cancelar lote"
 * (perigosa) — tudo conforme `allowed_actions`.
 */
const props = defineProps({
    /** Paginator do Laravel ({ data, links, total... }); array simples também serve. */
    batches:           { type: [Object, Array], default: () => ({ data: [] }) },
    search:            { type: String, default: '' },
    sort:              { type: String, default: 'created' },
    direction:         { type: String, default: 'desc' },
    submittingBatchId: { type: String, default: null },
    t:                 { type: Object, default: () => ({}) },
});

const emit = defineEmits(['submit', 'view-claims', 'cancel', 'receive', 'add-claims', 'reprocess', 'search', 'sort']);

const { tx } = useTrans(() => props.t);
const { money, date, dateTime } = useLocaleFormat();

const rows     = computed(() => pageRows(props.batches));
const hasPages = computed(() => isPaginator(props.batches) && props.batches.last_page > 1);

function submitLabel(batch) {
    return batch.is_particular ? props.t.action_mark_charged : props.t.action_submit_tiss;
}

function hasMenu(batch) {
    return hasAction(batch, 'add_claims') || hasAction(batch, 'reprocess_pending') || hasAction(batch, 'cancel');
}

function cancelledInfo(batch) {
    const when = date(batch.cancelled_at);

    return batch.cancelled_by_name
        ? tx('batch_cancelled_on_by', { date: when, user: batch.cancelled_by_name })
        : tx('batch_cancelled_on', { date: when });
}
</script>

<template>
    <div class="card mb-0">
        <div class="card-header bg-transparent border-bottom d-flex align-items-center flex-wrap gap-2">
            <SearchInput
                :model-value="search"
                :placeholder="t.search_batches"
                :clear-label="t.search_clear"
                max-width="420px"
                wrapper-class="flex-grow-1"
                data-test="batches-search"
                @update:model-value="emit('search', $event)"
            />
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <SortableTh col-key="created" scope="col" :current-sort="sort" :current-dir="direction" :title="tx('sort_by', { column: t.col_batch })" @sort="emit('sort', $event)">{{ t.col_batch }}</SortableTh>
                        <th scope="col">{{ t.col_covenant }}</th>
                        <SortableTh col-key="period" scope="col" class="d-none d-lg-table-cell" :current-sort="sort" :current-dir="direction" :title="tx('sort_by', { column: t.col_period })" @sort="emit('sort', $event)">{{ t.col_period }}</SortableTh>
                        <th scope="col">{{ t.col_guides }}</th>
                        <SortableTh col-key="total" scope="col" class="text-end" :current-sort="sort" :current-dir="direction" :title="tx('sort_by', { column: t.col_total })" @sort="emit('sort', $event)">{{ t.col_total }}</SortableTh>
                        <th scope="col" class="d-none d-lg-table-cell">{{ t.col_submitted_at }}</th>
                        <th scope="col">{{ t.col_status }}</th>
                        <th scope="col" class="text-end"><span class="visually-hidden">{{ t.col_actions }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="ti ti-package-off fs-1 d-block mb-2" aria-hidden="true"></i>
                            <p v-if="search" class="fw-medium mb-0" data-test="batches-no-results">{{ tx('search_no_results', { term: search }) }}</p>
                            <template v-else>
                                <p class="fw-medium mb-1">{{ t.no_batches }}</p>
                                <p class="small mb-0">{{ t.no_batches_hint }}</p>
                            </template>
                        </td>
                    </tr>
                    <template v-for="b in rows" :key="b.id">
                        <tr data-test="batch-row">
                            <td class="text-nowrap">
                                <span class="fw-semibold">{{ b.code || '—' }}</span>
                                <small class="d-block text-muted">{{ date(b.created_at) }}</small>
                            </td>
                            <td>
                                {{ b.covenant_name || t.no_covenant }}
                                <span v-if="b.is_particular" class="badge badge-soft-secondary ms-1">{{ t.particular_badge }}</span>
                            </td>
                            <td class="d-none d-lg-table-cell small text-muted text-nowrap">
                                {{ tx('summary_period_value', { from: date(b.period_start), to: date(b.period_end) }) }}
                            </td>
                            <td class="text-nowrap">
                                <span class="badge badge-soft-info">{{ tx('batch_guides_included', { count: b.included_count }) }}</span>
                                <span v-if="b.pending_count > 0" class="badge badge-soft-warning ms-1">
                                    {{ tx('batch_guides_pending', { count: b.pending_count }) }}
                                </span>
                            </td>
                            <td class="text-end fw-semibold text-nowrap">{{ money(b.total_amount) }}</td>
                            <td class="d-none d-lg-table-cell small text-nowrap" data-test="batch-submitted-at">
                                <span v-if="b.submitted_at">{{ dateTime(b.submitted_at) }}</span>
                                <span v-else class="text-muted">{{ t.not_submitted }}</span>
                            </td>
                            <td><span :class="['badge', b.status_badge]" data-test="batch-status">{{ b.status_label }}</span></td>
                            <td class="text-end">
                                <div class="d-inline-flex align-items-center gap-1">
                                    <button
                                        v-if="hasAction(b, 'submit')"
                                        type="button"
                                        class="btn btn-sm btn-outline-primary text-nowrap"
                                        data-test="submit-batch"
                                        :disabled="submittingBatchId === b.id"
                                        @click="emit('submit', b)"
                                    >
                                        <span v-if="submittingBatchId === b.id" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                                        <i v-else :class="['ti', b.is_particular ? 'ti-checks' : 'ti-send', 'me-1']" aria-hidden="true"></i>
                                        {{ submitLabel(b) }}
                                    </button>
                                    <button
                                        v-if="hasAction(b, 'receive')"
                                        type="button"
                                        class="btn btn-sm btn-outline-success text-nowrap"
                                        data-test="receive-batch"
                                        :aria-label="tx('action_receive_batch_label', { code: b.code })"
                                        :title="tx('action_receive_batch_label', { code: b.code })"
                                        @click="emit('receive', b)"
                                    >
                                        <i class="ti ti-cash me-1" aria-hidden="true"></i>{{ t.action_receive_batch }}
                                    </button>
                                    <ActionIconButton
                                        icon="ti ti-file-invoice"
                                        :title="tx('action_view_claims', { code: b.code })"
                                        data-test="view-claims"
                                        @click="emit('view-claims', b)"
                                    />
                                    <ActionIconButton
                                        v-if="hasAction(b, 'download_xml')"
                                        icon="ti ti-file-download"
                                        :href="b.xml_url"
                                        :title="t.action_download_xml"
                                        data-test="download-xml"
                                    />
                                    <!-- data-row-actions: o Index devolve o foco a este botão ao fechar o modal. -->
                                    <span v-if="hasMenu(b)" class="d-inline-flex" :data-row-actions="`batch-${b.id}`" data-test="batch-menu">
                                        <ActionDropdown
                                            :title="tx('row_actions_batch', { code: b.code })"
                                            btn-class="ee-action-icon ee-action-icon--default"
                                            :min-width="220"
                                        >
                                            <li v-if="hasAction(b, 'add_claims')">
                                                <button type="button" class="dropdown-item rounded-1" data-test="add-claims" @click="emit('add-claims', b)">
                                                    <i class="ti ti-package-import me-1" aria-hidden="true"></i>{{ t.action_add_claims }}
                                                </button>
                                            </li>
                                            <li v-if="hasAction(b, 'reprocess_pending')">
                                                <button type="button" class="dropdown-item rounded-1" data-test="reprocess-pending" @click="emit('reprocess', b)">
                                                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ t.action_reprocess_pending }}
                                                </button>
                                            </li>
                                            <li v-if="(hasAction(b, 'add_claims') || hasAction(b, 'reprocess_pending')) && hasAction(b, 'cancel')" aria-hidden="true">
                                                <hr class="dropdown-divider">
                                            </li>
                                            <li v-if="hasAction(b, 'cancel')">
                                                <button type="button" class="dropdown-item rounded-1 text-danger" data-test="cancel-batch" @click="emit('cancel', b)">
                                                    <i class="ti ti-ban me-1" aria-hidden="true"></i>{{ t.action_cancel_batch }}
                                                </button>
                                            </li>
                                        </ActionDropdown>
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="b.cancelled_at" data-test="batch-cancelled-info">
                            <td colspan="8" class="small text-muted bg-body-tertiary border-top-0 pt-0">
                                <i class="ti ti-ban me-1" aria-hidden="true"></i>{{ cancelledInfo(b) }}<template v-if="b.cancel_reason"> — {{ tx('cancel_reason_value', { reason: b.cancel_reason }) }}</template>
                            </td>
                        </tr>
                        <tr v-if="b.notes">
                            <td colspan="8" class="small text-muted bg-body-tertiary border-top-0 pt-0">
                                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ b.notes }}
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div v-if="hasPages" class="px-3 pb-3" data-test="batches-pagination">
            <TablePagination
                :data="batches"
                :showing-from="t.pagination_showing"
                :showing-of="t.pagination_of"
                :showing-suffix="t.pagination_suffix?.batches"
                :aria-label="tx('pagination_label', { tab: t.tab_batches })"
                :previous-label="t.pagination_previous"
                :next-label="t.pagination_next"
            />
        </div>
    </div>
</template>
