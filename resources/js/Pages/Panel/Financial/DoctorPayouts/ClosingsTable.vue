<script setup>
import { computed } from 'vue';
import ActionIconButton  from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup   from '@/Components/Panel/ActionIconGroup.vue';
import PayoutStatusBadge from './PayoutStatusBadge.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Fechamentos de repasse em tabela: código (+ selo "Complementar"), médico,
 * período, itens, total, status, data do pagamento e ações (demonstrativo e
 * PDF). O PDF é link comum — o navegador baixa o anexo.
 */
const props = defineProps({
    payouts:   { type: Array,  default: () => [] },
    routes:    { type: Object, required: true },   // { show, pdf } com __ID__
    t:         { type: Object, default: () => ({}) },
    emptyText: { type: String, default: '' },
});

const { money, number, date, periodText } = useDoctorPayoutFormat(() => props.t);

const url = (template, payout) => String(template ?? '').replace('__ID__', payout.id);

const rows = computed(() => props.payouts ?? []);
</script>

<template>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden">{{ t.closings_title }}</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ t.col_code }}</th>
                    <th scope="col">{{ t.col_doctor }}</th>
                    <th scope="col">{{ t.col_period }}</th>
                    <th scope="col" class="text-end">{{ t.col_items }}</th>
                    <th scope="col" class="text-end">{{ t.col_total }}</th>
                    <th scope="col">{{ t.col_status }}</th>
                    <th scope="col">{{ t.col_paid_at }}</th>
                    <th scope="col" class="text-end">{{ t.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td colspan="8" class="text-center text-muted py-5" data-test="closings-empty">
                        <i class="ti ti-lock-off fs-1 d-block mb-2" aria-hidden="true"></i>{{ emptyText }}
                    </td>
                </tr>
                <tr v-for="payout in rows" :key="payout.id" data-test="closing-row" :data-id="payout.id">
                    <td class="text-nowrap">
                        <span class="fw-semibold">{{ payout.code }}</span>
                        <span
                            v-if="payout.is_complementary"
                            class="badge badge-soft-purple border rounded fs-11 fw-medium ms-1"
                            :title="t.complementary_hint"
                            data-test="complementary"
                        >{{ t.complementary }}<span class="visually-hidden">: {{ t.complementary_hint }}</span></span>
                    </td>
                    <td>
                        <div class="fw-medium">{{ payout.doctor_name }}</div>
                        <div v-if="payout.doctor_record" class="small text-muted">{{ t.statement_record }} {{ payout.doctor_record }}</div>
                    </td>
                    <td class="small text-nowrap">{{ periodText(payout.period_start, payout.period_end) }}</td>
                    <td class="text-end closings__value">{{ number(payout.items_count) }}</td>
                    <td class="text-end text-nowrap fw-semibold closings__value">{{ money(payout.total_amount) }}</td>
                    <td><PayoutStatusBadge :status="payout.status" :t="t" /></td>
                    <td class="small text-nowrap">{{ payout.paid_at ? date(payout.paid_at) : t.none }}</td>
                    <td class="text-end">
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
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.closings__value {
    font-variant-numeric: tabular-nums;
}
</style>
