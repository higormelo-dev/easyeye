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
 * - Origem do valor e alertas: rótulo visível + explicação no title e para o
 *   leitor de tela. A coluna Alertas só aparece se algum item tiver alerta.
 * - Rola na horizontal no celular (table-responsive).
 */
const props = defineProps({
    rows:       { type: Array,   default: () => [] },
    t:          { type: Object,  default: () => ({}) },
    caption:    { type: String,  default: '' },
    emptyText:  { type: String,  default: '' },
    showStatus: { type: Boolean, default: false },
    closingUrl: { type: String,  default: '' },
});

const {
    tx, date, money, serviceTypeLabel, serviceTypeIcon, payerLabel, ruleLabel,
    baseSourceLabel, baseSourceHint, baseSourceBadge, warningLabel, warningHint, warningIcon, warningTone,
} = useDoctorPayoutFormat(() => props.t);

const hasWarnings = computed(() => props.rows.some((row) => (row.warnings ?? []).length > 0));
const columnCount = computed(() => 7 + (props.showStatus ? 1 : 0) + (hasWarnings.value ? 1 : 0));

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
                    <th scope="col">{{ t.col_date }}</th>
                    <th scope="col">{{ t.col_patient }}</th>
                    <th scope="col">{{ t.col_service }}</th>
                    <th scope="col">{{ t.col_payer }}</th>
                    <th scope="col" class="text-end">{{ t.col_charged }}</th>
                    <th scope="col">{{ t.col_rule }}</th>
                    <th scope="col" class="text-end">{{ t.col_payout }}</th>
                    <th v-if="showStatus" scope="col">{{ t.col_status }}</th>
                    <th v-if="hasWarnings" scope="col">{{ t.col_warnings }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="columnCount" class="text-center text-muted py-4" data-test="items-empty">{{ emptyText }}</td>
                </tr>
                <tr v-for="row in rows" :key="row.key" data-test="item-row" :data-key="row.key">
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
                    </td>
                    <td class="small" :class="{ 'text-danger fw-medium': !row.rule }" data-test="item-rule">{{ ruleLabel(row.rule) }}</td>
                    <td class="text-end text-nowrap fw-semibold payout-items__value" data-test="item-payout">{{ money(row.payout) }}</td>
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
</style>
