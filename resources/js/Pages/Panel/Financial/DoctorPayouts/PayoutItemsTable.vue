<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PayoutStatusBadge from './PayoutStatusBadge.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Atendimentos que compõem um repasse — a mesma linha na apuração (pendentes
 * calculados ao vivo + itens já fechados/pagos) e no demonstrativo (retrato
 * do fechamento).
 *
 * - `showStatus` (apuração): coluna Status; item fechado/pago leva ao
 *   fechamento (`closingUrl` com __ID__ = payout_id).
 * - `showReceipt` (apuração): coluna Recebimento — rastreio da clínica
 *   (row.receipt): recebido, situação, glosa e o que falta, com os
 *   recebimentos manuais do item (row.manual_allocations) e, com
 *   `canReverse`, o botão de estornar cada um (emite reverse-allocation).
 * - `selectable` (apuração): checkbox por linha (v-model:selected = row_id)
 *   para alocar recebimento manual aos itens escolhidos.
 * - Regra aplicada: cálculo + QUAL regra venceu (row.rule_scope: médico ·
 *   tipo/item · pagador); regra fixa paga em parte mostra a proporção.
 * - Divisão (E4): sob a regra, o papel e a % do grupo quando o item é
 *   dividido e o líquido após as deduções, com o detalhe por tipo
 *   (cartão/imposto/taxa adm., acumulado do atendimento).
 * - Base: parcela, recebido acumulado e o esperado do atendimento (cobrado −
 *   glosa) quando difere do recebido; Recebimento: também o valor cobrado.
 * - Origem do valor e alertas: rótulo visível + explicação no title e para o
 *   leitor de tela. A coluna Alertas só aparece se algum item tiver alerta.
 * - Rola na horizontal no celular (table-responsive).
 */
const props = defineProps({
    rows:        { type: Array,   default: () => [] },
    t:           { type: Object,  default: () => ({}) },
    caption:     { type: String,  default: '' },
    emptyText:   { type: String,  default: '' },
    showStatus:  { type: Boolean, default: false },
    showReceipt: { type: Boolean, default: false },
    closingUrl:  { type: String,  default: '' },
    selectable:  { type: Boolean, default: false },
    selected:    { type: Array,   default: () => [] },   // row_id das linhas marcadas
    canReverse:  { type: Boolean, default: false },
});

const emit = defineEmits(['update:selected', 'reverse-allocation']);

const rowId = (row) => row.row_id ?? row.key;
const isSelected = (row) => props.selected.includes(rowId(row));
const allSelected = computed(() => props.rows.length > 0 && props.rows.every(isSelected));

function toggleRow(row) {
    const id = rowId(row);

    emit('update:selected', isSelected(row) ? props.selected.filter((value) => value !== id) : [...props.selected, id]);
}

function toggleAll() {
    const pageIds = props.rows.map(rowId);

    emit('update:selected', allSelected.value
        ? props.selected.filter((value) => !pageIds.includes(value))
        : [...new Set([...props.selected, ...pageIds])]);
}

const {
    tx, date, money, serviceTypeLabel, serviceTypeIcon, payerLabel, ruleLabel,
    baseSourceLabel, baseSourceHint, baseSourceBadge, warningLabel, warningHint, warningIcon, warningTone,
    receiptStatusLabel, receiptStatusHint, receiptStatusBadge, receiptStatusIcon,
    quantity, beneficiaryRoleLabel, deductionsBreakdownText,
} = useDoctorPayoutFormat(() => props.t);

const hasWarnings = computed(() => props.rows.some((row) => (row.warnings ?? []).length > 0));
const columnCount = computed(() => 7 + (props.selectable ? 1 : 0) + (props.showStatus ? 1 : 0) + (props.showReceipt ? 1 : 0) + (hasWarnings.value ? 1 : 0));

/** Situação do recebimento; linha sem rastreio conta como "sem cobrança própria". */
const receiptStatus = (row) => row.receipt?.status ?? 'not_linked';
const receiptLinked = (row) => receiptStatus(row) !== 'not_linked';
const positive = (value) => Number(value ?? 0) > 0;

/** Papel só aparece quando há divisão de fato (executor com 100% do grupo = sem divisão). */
const hasShare = (row) => !!row.beneficiary_role
    && row.share_percentage !== null && row.share_percentage !== undefined
    && (row.beneficiary_role !== 'executor' || Number(row.share_percentage) < 100);
const hasNet = (row) => row.net !== null && row.net !== undefined && positive(row.deductions);

const cents = (value) => Math.round(Number(value ?? 0) * 100);

/** Regime por recebimento: esperado (cobrado − glosa) diferente do recebido acumulado. */
const hasExpectedGap = (row) => row.basis === 'receipt' && row.status !== 'awaiting'
    && positive(row.expected) && row.received !== null && row.received !== undefined
    && cents(row.expected) !== cents(row.received);

/** Regra de valor fixo paga em parte: proporcional ao recebido sobre o esperado. */
const isFixedProportional = (row) => row.rule?.calculation === 'fixed' && row.basis === 'receipt'
    && positive(row.received) && cents(row.received) < cents(row.expected);

function closingHref(row) {
    return props.closingUrl && row.payout_id ? props.closingUrl.replace('__ID__', row.payout_id) : '';
}
</script>

<template>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0 payout-items">
            <caption v-if="caption" class="visually-hidden">{{ caption }}</caption>
            <thead class="table-light">
                <tr>
                    <th v-if="selectable" scope="col" class="payout-items__check">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            :checked="allSelected"
                            :aria-label="t.allocate_select_all"
                            data-test="select-all"
                            @change="toggleAll"
                        >
                    </th>
                    <th scope="col">{{ t.col_date }}</th>
                    <th scope="col">{{ t.col_patient }}</th>
                    <th scope="col">{{ t.col_service }}</th>
                    <th scope="col">{{ t.col_payer }}</th>
                    <th scope="col" class="text-end">{{ t.col_charged }}</th>
                    <th scope="col">{{ t.col_rule }}</th>
                    <th scope="col" class="text-end">{{ t.col_payout }}</th>
                    <th v-if="showReceipt" scope="col" class="text-end">{{ t.col_receipt }}</th>
                    <th v-if="showStatus" scope="col">{{ t.col_status }}</th>
                    <th v-if="hasWarnings" scope="col">{{ t.col_warnings }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="columnCount" class="text-center text-muted py-4" data-test="items-empty">{{ emptyText }}</td>
                </tr>
                <tr v-for="row in rows" :key="row.row_id ?? row.key" data-test="item-row" :data-key="row.key">
                    <td v-if="selectable" class="payout-items__check">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            :checked="isSelected(row)"
                            :aria-label="tx('allocate_select_row', { item: `${date(row.date)} ${row.description}` })"
                            data-test="select-row"
                            @change="toggleRow(row)"
                        >
                    </td>
                    <td class="text-nowrap small">{{ date(row.date) }}</td>
                    <td>
                        <div class="fw-medium text-truncate payout-items__clip" :title="row.patient_name || undefined">{{ row.patient_name || t.none }}</div>
                        <div v-if="row.patient_code" class="small text-muted">{{ row.patient_code }}</div>
                    </td>
                    <td>
                        <div class="small text-muted text-nowrap">
                            <i :class="serviceTypeIcon(row.service_type)" class="me-1" aria-hidden="true"></i>{{ serviceTypeLabel(row.service_type) }}
                        </div>
                        <div class="text-truncate payout-items__clip" :title="row.description">{{ row.description }}</div>
                    </td>
                    <td class="small">{{ payerLabel(row) }}</td>
                    <td class="text-end text-nowrap">
                        <div class="payout-items__value">{{ money(row.charged) }}</div>
                        <span
                            class="badge rounded fs-11 fw-medium"
                            :class="baseSourceBadge(row.base_source)"
                            :title="baseSourceHint(row.base_source)"
                            :data-base-source="row.base_source"
                        >{{ baseSourceLabel(row.base_source) }}<span class="visually-hidden">: {{ baseSourceHint(row.base_source) }}</span></span>
                        <div v-if="Number(row.tranche ?? 1) > 1" class="small text-muted" data-test="item-tranche">
                            {{ tx('tranche_label', { number: row.tranche }) }} · {{ tx('received_total', { value: money(row.received) }) }}
                        </div>
                        <div v-if="hasExpectedGap(row)" class="small text-muted" data-test="item-expected">
                            {{ tx('expected_line', { value: money(row.expected) }) }}
                        </div>
                    </td>
                    <td class="small" data-test="item-rule">
                        <span :class="{ 'text-danger fw-medium': !row.rule }">{{ ruleLabel(row.rule, row.basis) }}</span>
                        <div v-if="row.rule_scope" class="text-muted text-truncate payout-items__clip" :title="row.rule_scope" data-test="item-rule-scope">
                            {{ row.rule_scope }}
                        </div>
                        <div v-if="isFixedProportional(row)" class="text-muted" data-test="item-fixed-proportional">
                            {{ tx('rule_fixed_proportional', { received: money(row.received), expected: money(row.expected) }) }}
                        </div>
                        <div v-if="hasShare(row)" class="text-muted" data-test="item-share">
                            {{ tx('split_role_line', { role: beneficiaryRoleLabel(row.beneficiary_role), value: quantity(row.share_percentage, 2) }) }}
                        </div>
                        <div v-if="hasNet(row)" class="text-muted" data-test="item-net">
                            {{ tx('split_net_line', { net: money(row.net), deductions: money(row.deductions) }) }}
                        </div>
                        <div
                            v-if="hasNet(row) && row.deductions_breakdown"
                            class="text-muted"
                            :title="t.deductions_breakdown_hint"
                            data-test="item-deductions"
                        >
                            {{ deductionsBreakdownText(row.deductions_breakdown) }}
                        </div>
                    </td>
                    <td class="text-end text-nowrap fw-semibold payout-items__value" data-test="item-payout">
                        <template v-if="row.status === 'awaiting'">
                            <span class="text-muted fw-normal">{{ t.none }}</span>
                            <div v-if="row.forecast !== null && row.forecast !== undefined" class="small text-muted fw-normal" data-test="item-forecast">
                                {{ tx('forecast_label', { value: money(row.forecast) }) }}
                            </div>
                        </template>
                        <template v-else>
                            {{ money(row.payout) }}
                            <div v-if="Number(row.released_before ?? 0) !== 0" class="small text-muted fw-normal" data-test="item-released-before">
                                {{ tx('released_before', { value: money(row.released_before) }) }}
                            </div>
                        </template>
                    </td>
                    <td v-if="showReceipt" class="text-end text-nowrap" data-test="item-receipt" :data-receipt="receiptStatus(row)">
                        <div v-if="receiptLinked(row)" class="payout-items__value">{{ money(row.receipt.received) }}</div>
                        <span
                            class="badge rounded fs-11 fw-medium"
                            :class="receiptStatusBadge(receiptStatus(row))"
                            :title="receiptStatusHint(receiptStatus(row))"
                        >
                            <i :class="receiptStatusIcon(receiptStatus(row))" class="me-1" aria-hidden="true"></i>{{ receiptStatusLabel(receiptStatus(row)) }}<span class="visually-hidden">: {{ receiptStatusHint(receiptStatus(row)) }}</span>
                        </span>
                        <div v-if="receiptLinked(row) && positive(row.receipt.billed)" class="small text-muted" data-test="item-billed">{{ tx('receipt_billed', { value: money(row.receipt.billed) }) }}</div>
                        <div v-if="receiptLinked(row) && positive(row.receipt.glosa)" class="small text-danger-emphasis">{{ tx('receipt_glosa', { value: money(row.receipt.glosa) }) }}</div>
                        <div v-if="receiptLinked(row) && positive(row.receipt.open)" class="small text-muted">{{ tx('receipt_open', { value: money(row.receipt.open) }) }}</div>
                        <div v-if="receiptLinked(row) && Number(row.receipt.difference ?? 0) !== 0" class="small text-warning-emphasis">{{ tx('receipt_diff', { value: money(row.receipt.difference) }) }}</div>
                        <div v-if="Number(row.receipt?.shared_by ?? 1) > 1" class="small text-muted" data-test="item-receipt-shared">{{ tx('receipt_shared', { count: row.receipt.shared_by }) }}</div>
                        <div v-if="row.manual_allocations?.length" class="mt-1" data-test="item-manual">
                            <div class="small text-muted">{{ t.manual_receipts }}</div>
                            <ul class="list-unstyled small mb-0">
                                <li v-for="allocation in row.manual_allocations" :key="allocation.id" class="d-flex align-items-center justify-content-end gap-1">
                                    <span :title="allocation.description">{{ tx('manual_receipt_line', { date: date(allocation.date), amount: money(allocation.amount) }) }}</span>
                                    <button
                                        v-if="canReverse"
                                        type="button"
                                        class="btn btn-link btn-sm p-0 text-danger lh-1"
                                        :title="t.manual_reverse"
                                        :aria-label="`${t.manual_reverse}: ${tx('manual_receipt_line', { date: date(allocation.date), amount: money(allocation.amount) })}`"
                                        data-test="manual-reverse"
                                        @click="emit('reverse-allocation', allocation)"
                                    >
                                        <i class="ti ti-arrow-back-up" aria-hidden="true"></i>
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </td>
                    <td v-if="showStatus" class="text-nowrap">
                        <Link
                            v-if="closingHref(row)"
                            :href="closingHref(row)"
                            class="d-inline-flex flex-column align-items-start gap-1 text-decoration-none"
                            data-test="item-closing-link"
                        >
                            <PayoutStatusBadge :status="row.status" :t="t" />
                            <span class="small">{{ tx('closed_in', { code: row.payout_code }) }}</span>
                        </Link>
                        <PayoutStatusBadge v-else :status="row.status" :t="t" />
                    </td>
                    <td v-if="hasWarnings">
                        <ul v-if="row.warnings?.length" class="list-unstyled d-flex flex-wrap gap-1 mb-0">
                            <li v-for="code in row.warnings" :key="code">
                                <span
                                    class="badge rounded fs-11 fw-medium text-nowrap"
                                    :class="`badge-soft-${warningTone(code)} border border-${warningTone(code)}`"
                                    :title="warningHint(code)"
                                    :data-warning="code"
                                >
                                    <i :class="warningIcon(code)" class="me-1" aria-hidden="true"></i>{{ warningLabel(code) }}<span class="visually-hidden">: {{ warningHint(code) }}</span>
                                </span>
                            </li>
                        </ul>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
/* Nome do paciente e descrição longos não empurram as colunas de valores
   (max-width no bloco: em <td> com table-layout automático ele é ignorado). */
.payout-items__clip {
    max-width: 16rem;
}

.payout-items__value {
    font-variant-numeric: tabular-nums;
}

.payout-items__check {
    width: 2rem;
}
</style>
