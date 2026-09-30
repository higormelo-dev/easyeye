<script setup>
import { computed } from 'vue';
import ActionIconButton  from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup   from '@/Components/Panel/ActionIconGroup.vue';
import PayoutStatusBadge from './PayoutStatusBadge.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Fechamentos de repasse em cards — mesmos dados e ações da tabela, sem
 * rolagem horizontal no celular. Usa o MESMO paginator da tabela.
 */
const props = defineProps({
    payouts:   { type: Array,  default: () => [] },
    routes:    { type: Object, required: true },   // { show, pdf } com __ID__
    t:         { type: Object, default: () => ({}) },
    emptyText: { type: String, default: '' },
});

const { tx, money, number, date, periodText } = useDoctorPayoutFormat(() => props.t);

const url = (template, payout) => String(template ?? '').replace('__ID__', payout.id);

const rows = computed(() => props.payouts ?? []);
</script>

<template>
    <div v-if="rows.length === 0" class="text-center text-muted py-5" data-test="closings-empty">
        <i class="ti ti-lock-off fs-1 d-block mb-2" aria-hidden="true"></i>
        <p class="mb-0">{{ emptyText }}</p>
    </div>

    <ul v-else class="row g-3 list-unstyled mb-0" :aria-label="t.closings_title">
        <li v-for="payout in rows" :key="payout.id" class="col-12 col-sm-6 col-xl-4" data-test="closing-card" :data-id="payout.id">
            <div class="card card-body h-100 mb-0">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <p class="fw-semibold mb-0">{{ payout.code }}</p>
                        <span
                            v-if="payout.is_complementary"
                            class="badge badge-soft-purple border rounded fs-11 fw-medium"
                            :title="t.complementary_hint"
                            data-test="complementary"
                        >{{ t.complementary }}<span class="visually-hidden">: {{ t.complementary_hint }}</span></span>
                    </div>
                    <PayoutStatusBadge :status="payout.status" :t="t" />
                </div>

                <dl class="small text-muted mt-3 mb-2">
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_doctor }}:</dt>
                        <dd class="mb-0 text-break">{{ payout.doctor_name }}<template v-if="payout.doctor_record"> · {{ t.statement_record }} {{ payout.doctor_record }}</template></dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_period }}:</dt>
                        <dd class="mb-0">{{ periodText(payout.period_start, payout.period_end) }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_items }}:</dt>
                        <dd class="mb-0">{{ number(payout.items_count) }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_paid }}:</dt>
                        <dd class="mb-0" data-test="closing-paid">
                            <template v-if="payout.status === 'cancelled'">{{ t.none }}</template>
                            <template v-else>
                                {{ money(payout.paid_amount ?? 0) }}
                                <span v-if="Number(payout.remaining_amount ?? 0) > 0" class="text-warning-emphasis" data-test="closing-balance"> · {{ tx('balance_line', { value: money(payout.remaining_amount) }) }}</span>
                                <span v-if="payout.paid_at" class="text-muted"> · {{ tx('last_payment_on', { date: date(payout.paid_at) }) }}</span>
                            </template>
                        </dd>
                    </div>
                </dl>

                <div class="d-flex align-items-center justify-content-between gap-2 mt-auto pt-2 border-top">
                    <span class="fw-bold text-body closings-card__value">
                        <span class="visually-hidden">{{ t.col_total }}: </span>{{ money(payout.total_amount) }}
                    </span>
                    <ActionIconGroup align="end" gap="tight">
                        <ActionIconButton
                            icon="ti ti-eye"
                            :title="`${t.view}: ${payout.code}`"
                            :inertia-href="url(routes.show, payout)"
                            data-test="closing-view"
                        />
                        <ActionIconButton
                            icon="ti ti-file-type-pdf"
                            :title="`${t.download_pdf}: ${payout.code}`"
                            :href="url(routes.pdf, payout)"
                            data-test="closing-pdf"
                        />
                    </ActionIconGroup>
                </div>
            </div>
        </li>
    </ul>
</template>

<style scoped>
.closings-card__value {
    font-variant-numeric: tabular-nums;
}
</style>
