<script setup>
import { computed, useId } from 'vue';
import PayoutStatusBadge from './PayoutStatusBadge.vue';
import PayoutItemsTable from './PayoutItemsTable.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Demonstrativo de um fechamento de repasse — o mesmo na tela da clínica
 * (Financeiro › Repasse médico) e em "Meus repasses" (médico, só leitura):
 * cabeçalho, avisos (cancelado / pagamento estornado), atendimentos agrupados
 * por tipo com subtotais, ajustes manuais e totais.
 *
 * Slots (só a tela da clínica usa):
 *   #adjustment-actions="{ adjustment }" – ações por ajuste (ex.: remover)
 *   #adjustments-footer                  – formulário de novo ajuste
 */
const props = defineProps({
    statement: { type: Object, required: true }, // { payout, groups, adjustments }
    t: { type: Object, default: () => ({}) },
});

const { tx, money, signedMoney, number, dateTime, periodText, serviceTypePlural, serviceTypeIcon, grossLabel } =
    useDoctorPayoutFormat(() => props.t);

const uid = useId();
const ids = {
    header: `statement-header-${uid}`,
    adjustments: `statement-adjustments-${uid}`,
    totals: `statement-totals-${uid}`,
};

const payout = computed(() => props.statement?.payout ?? {});
const groups = computed(() => props.statement?.groups ?? []);
const adjustments = computed(() => props.statement?.adjustments ?? []);

function notice(key, at, user, reason) {
    return tx(key, { date: dateTime(at), user: user || props.t.none, reason: reason || props.t.none });
}

const cancelledText = computed(() =>
    payout.value.cancelled_at
        ? notice(
              'statement_cancelled',
              payout.value.cancelled_at,
              payout.value.cancelled_by_name,
              payout.value.cancel_reason,
          )
        : '',
);

const reversedText = computed(() =>
    payout.value.payment_reversed_at
        ? notice(
              'statement_reversed',
              payout.value.payment_reversed_at,
              payout.value.payment_reversed_by_name,
              payout.value.payment_reversal_reason,
          )
        : '',
);
</script>

<template>
    <div class="d-grid gap-3 statement-view">
        <!-- Cabeçalho do fechamento -->
        <section class="card mb-0" :aria-labelledby="ids.header" data-test="statement-header">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <h2 :id="ids.header" class="h5 fw-bold mb-0" data-test="statement-code">{{ payout.code }}</h2>
                    <PayoutStatusBadge v-if="payout.status" :status="payout.status" :t="t" />
                </div>
                <dl class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 small mb-0">
                    <div class="col">
                        <dt class="text-muted fw-normal">{{ t.statement_doctor }}</dt>
                        <dd class="fw-semibold mb-0 text-break" data-test="statement-doctor">
                            {{ payout.doctor_name || t.none }}
                        </dd>
                    </div>
                    <div class="col">
                        <dt class="text-muted fw-normal">{{ t.statement_record }}</dt>
                        <dd class="mb-0">{{ payout.doctor_record || t.none }}</dd>
                    </div>
                    <div class="col">
                        <dt class="text-muted fw-normal">{{ t.statement_period }}</dt>
                        <dd class="mb-0 text-nowrap" data-test="statement-period">
                            {{ periodText(payout.period_start, payout.period_end) }}
                        </dd>
                    </div>
                    <div class="col">
                        <dt class="text-muted fw-normal">{{ t.statement_closed_at }}</dt>
                        <dd class="mb-0">{{ dateTime(payout.closed_at) }}</dd>
                    </div>
                    <div v-if="payout.closed_by_name" class="col">
                        <dt class="text-muted fw-normal">{{ t.statement_closed_by }}</dt>
                        <dd class="mb-0">{{ payout.closed_by_name }}</dd>
                    </div>
                    <div v-if="payout.notes" class="col-12">
                        <dt class="text-muted fw-normal">{{ t.statement_notes }}</dt>
                        <dd class="mb-0 text-break statement-view__notes">{{ payout.notes }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <div
            v-if="cancelledText"
            class="alert alert-secondary d-flex gap-2 mb-0"
            role="note"
            data-test="statement-cancelled"
        >
            <i class="ti ti-circle-x mt-1" aria-hidden="true"></i><span class="text-break">{{ cancelledText }}</span>
        </div>
        <div
            v-if="reversedText"
            class="alert alert-warning d-flex gap-2 mb-0"
            role="note"
            data-test="statement-reversed"
        >
            <i class="ti ti-arrow-back-up mt-1" aria-hidden="true"></i
            ><span class="text-break">{{ reversedText }}</span>
        </div>

        <!-- Atendimentos por tipo, com subtotais -->
        <section
            v-for="group in groups"
            :key="group.service_type"
            class="card mb-0"
            :aria-labelledby="`${ids.header}-${group.service_type}`"
            data-test="statement-group"
            :data-type="group.service_type"
        >
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h3 :id="`${ids.header}-${group.service_type}`" class="h6 fw-bold mb-0 d-flex align-items-center gap-2">
                    <i :class="serviceTypeIcon(group.service_type)" class="text-primary" aria-hidden="true"></i>
                    {{ serviceTypePlural(group.service_type) }}
                    <span class="badge badge-soft-secondary border rounded fs-11">{{ number(group.count) }}</span>
                </h3>
                <p class="small mb-0 text-nowrap" data-test="group-subtotal">
                    <span class="text-muted">{{ t.statement_subtotal }}:</span>
                    <strong class="statement-view__value">{{ money(group.payout) }}</strong>
                    <span class="text-muted"> · {{ t.col_charged }} {{ money(group.charged) }}</span>
                </p>
            </div>
            <PayoutItemsTable :rows="group.items ?? []" :t="t" :caption="serviceTypePlural(group.service_type)" />
        </section>

        <!-- Ajustes manuais -->
        <section class="card mb-0" :aria-labelledby="ids.adjustments" data-test="statement-adjustments">
            <div class="card-header">
                <h3 :id="ids.adjustments" class="h6 fw-bold mb-0">
                    <i class="ti ti-plus-minus me-1 text-primary" aria-hidden="true"></i>{{ t.adjustments_title }}
                </h3>
            </div>
            <div class="card-body">
                <p v-if="adjustments.length === 0" class="small text-muted mb-0" data-test="adjustments-empty">
                    {{ t.adjustments_empty }}
                </p>
                <ul v-else class="list-unstyled mb-0 d-grid gap-2">
                    <li
                        v-for="adjustment in adjustments"
                        :key="adjustment.id"
                        class="d-flex align-items-center gap-2 border rounded px-3 py-2"
                        data-test="adjustment-row"
                    >
                        <div class="me-auto statement-view__min-w-0">
                            <p class="fw-medium mb-0 text-break">{{ adjustment.description }}</p>
                            <p class="small text-muted mb-0">
                                {{ dateTime(adjustment.created_at)
                                }}<template v-if="adjustment.created_by_name">
                                    · {{ adjustment.created_by_name }}</template
                                >
                            </p>
                        </div>
                        <span class="fw-semibold text-nowrap statement-view__value" data-test="adjustment-amount">
                            <i
                                class="ti me-1"
                                :class="
                                    Number(adjustment.amount) < 0
                                        ? 'ti-arrow-up-right text-danger'
                                        : 'ti-arrow-down-left text-success'
                                "
                                aria-hidden="true"
                            ></i
                            >{{ signedMoney(adjustment.amount) }}
                        </span>
                        <slot name="adjustment-actions" :adjustment="adjustment" />
                    </li>
                </ul>
                <slot name="adjustments-footer" />
            </div>
        </section>

        <!-- Totais -->
        <section class="card mb-0" :aria-labelledby="ids.totals" data-test="statement-totals">
            <div class="card-body">
                <h3 :id="ids.totals" class="visually-hidden">{{ t.statement_net_total }}</h3>
                <dl class="mb-0 statement-view__totals">
                    <div class="d-flex justify-content-between gap-3 py-1">
                        <dt class="fw-normal text-muted">{{ grossLabel(payout) }}</dt>
                        <dd class="mb-0 statement-view__value" data-test="total-gross">
                            {{ money(payout.gross_amount) }}
                        </dd>
                    </div>
                    <div class="d-flex justify-content-between gap-3 py-1">
                        <dt class="fw-normal">{{ t.statement_items_total }}</dt>
                        <dd class="mb-0 statement-view__value" data-test="total-items">
                            {{ money(payout.items_amount) }}
                        </dd>
                    </div>
                    <div class="d-flex justify-content-between gap-3 py-1">
                        <dt class="fw-normal">{{ t.statement_adjustments_total }}</dt>
                        <dd class="mb-0 statement-view__value" data-test="total-adjustments">
                            {{ signedMoney(payout.adjustments_amount) }}
                        </dd>
                    </div>
                    <div class="d-flex justify-content-between gap-3 border-top pt-2 mt-1 fs-5 fw-bold">
                        <dt>{{ t.statement_net_total }}</dt>
                        <dd class="mb-0 statement-view__value" data-test="total-net">
                            {{ money(payout.total_amount) }}
                        </dd>
                    </div>
                </dl>
            </div>
        </section>
    </div>
</template>

<style scoped>
.statement-view__value {
    font-variant-numeric: tabular-nums;
}

.statement-view__min-w-0 {
    min-width: 0;
}

.statement-view__notes {
    white-space: pre-line;
}

.statement-view__totals {
    max-width: 32rem;
    margin-left: auto;
}
</style>
