<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import FlashMessage from '@/Pages/Panel/Financial/DoctorPayouts/FlashMessage.vue';
import { useDoctorPayoutFormat } from '@/Pages/Panel/Financial/DoctorPayouts/useDoctorPayoutFormat.js';

/**
 * "Meus repasses" (médico): só os PRÓPRIOS fechamentos (fechados, pagos em
 * parte e pagos) — a produção pendente nunca aparece aqui. Tabela a partir do
 * md; cards no celular. Demonstrativo na tela e em PDF.
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    payouts: { type: Object, required: true }, // paginator Laravel
    routes: { type: Object, required: true }, // { index, show, pdf } — show/pdf com __ID__
    t: { type: Object, default: () => ({}) },
});

const { tx, money, date, periodText, grossLabel, countText } = useDoctorPayoutFormat(() => props.t);

const rows = computed(() => props.payouts?.data ?? []);

const url = (template, payout) => String(template ?? '').replace('__ID__', payout.id);

/** Pago → "Pago em dd/mm/aaaa"; em parte → "Pago em parte: X de Y"; fechado → "Aguardando pagamento". */
function statusText(payout) {
    if (payout.status === 'paid') return tx('payment_paid_on', { date: date(payout.paid_at) });
    if (payout.status === 'partially_paid')
        return tx('my_partially_paid', { paid: money(payout.paid_amount), total: money(payout.total_amount) });

    return props.t.my_awaiting;
}

const STATUS_BADGE = {
    paid: 'badge-soft-success border border-success',
    partially_paid: 'badge-soft-primary border border-primary',
};

const STATUS_ICON = { paid: 'ti ti-circle-check', partially_paid: 'ti ti-progress-check' };

const statusBadge = (payout) => STATUS_BADGE[payout.status] ?? 'badge-soft-warning border border-warning';
const statusIcon = (payout) => STATUS_ICON[payout.status] ?? 'ti ti-clock';

/** "Recebido (base das parcelas) · 14 atos no período" — o que é a produção do fechamento. */
const productionHint = (payout) => `${grossLabel(payout)} · ${countText('kpi_production_hint', payout.items_count)}`;
</script>

<template>
    <AppLayout :title="t.my_title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.my_title" :total="payouts.total ?? 0" :total-label="t.total_label" />
            <FlashMessage />

            <p class="small text-muted mb-3" data-test="my-intro">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.my_intro }}
            </p>

            <div v-if="rows.length === 0" class="card" data-test="my-empty">
                <div class="card-body text-center text-muted py-5">
                    <i class="ti ti-receipt-2 fs-1 d-block mb-2" aria-hidden="true"></i>
                    <p class="mb-0">{{ t.my_empty }}</p>
                </div>
            </div>

            <template v-else>
                <!-- md+: tabela -->
                <div class="d-none d-md-block card mb-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <caption class="visually-hidden">
                                {{
                                    t.my_title
                                }}
                            </caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">{{ t.col_period }}</th>
                                    <th scope="col">{{ t.col_code }}</th>
                                    <th scope="col" class="text-end">{{ t.my_production }}</th>
                                    <th scope="col" class="text-end">{{ t.my_payout }}</th>
                                    <th scope="col">{{ t.col_status }}</th>
                                    <th scope="col" class="text-end">{{ t.col_actions }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="payout in rows" :key="payout.id" data-test="my-row" :data-id="payout.id">
                                    <td class="text-nowrap fw-medium">
                                        {{ periodText(payout.period_start, payout.period_end) }}
                                    </td>
                                    <td class="text-nowrap small">{{ payout.code }}</td>
                                    <td class="text-end text-nowrap">
                                        <div class="my-payouts__value">{{ money(payout.gross_amount) }}</div>
                                        <div class="small text-muted" data-test="my-production-hint">
                                            {{ productionHint(payout) }}
                                        </div>
                                    </td>
                                    <td class="text-end text-nowrap fw-bold my-payouts__value">
                                        {{ money(payout.total_amount) }}
                                    </td>
                                    <td>
                                        <span
                                            class="badge rounded fs-11 fw-medium text-nowrap"
                                            :class="statusBadge(payout)"
                                            data-test="my-status"
                                        >
                                            <i :class="statusIcon(payout)" class="me-1" aria-hidden="true"></i
                                            >{{ statusText(payout) }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <ActionIconGroup align="end" gap="tight">
                                            <ActionIconButton
                                                icon="ti ti-eye"
                                                :title="`${t.view}: ${payout.code}`"
                                                :inertia-href="url(routes.show, payout)"
                                                data-test="my-view"
                                            />
                                            <ActionIconButton
                                                icon="ti ti-file-type-pdf"
                                                :title="`${t.download_pdf}: ${payout.code}`"
                                                :href="url(routes.pdf, payout)"
                                                data-test="my-pdf"
                                            />
                                        </ActionIconGroup>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- celular: cards -->
                <ul class="d-md-none list-unstyled d-grid gap-2 mb-0" :aria-label="t.my_title">
                    <li
                        v-for="payout in rows"
                        :key="payout.id"
                        class="card mb-0"
                        data-test="my-card"
                        :data-id="payout.id"
                    >
                        <div class="card-body p-3">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div>
                                    <p class="fw-semibold mb-0">
                                        {{ periodText(payout.period_start, payout.period_end) }}
                                    </p>
                                    <p class="small text-muted mb-0">{{ payout.code }}</p>
                                </div>
                                <span class="fw-bold text-body text-nowrap my-payouts__value">
                                    <span class="visually-hidden">{{ t.my_payout }}: </span
                                    >{{ money(payout.total_amount) }}
                                </span>
                            </div>
                            <p class="small text-muted mt-2 mb-2" data-test="my-card-production">
                                {{ t.my_production }}: {{ money(payout.gross_amount) }} · {{ productionHint(payout) }}
                            </p>
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="badge rounded fs-11 fw-medium" :class="statusBadge(payout)">
                                    <i :class="statusIcon(payout)" class="me-1" aria-hidden="true"></i
                                    >{{ statusText(payout) }}
                                </span>
                                <ActionIconGroup align="end" gap="tight">
                                    <ActionIconButton
                                        icon="ti ti-eye"
                                        :title="`${t.view}: ${payout.code}`"
                                        :inertia-href="url(routes.show, payout)"
                                    />
                                    <ActionIconButton
                                        icon="ti ti-file-type-pdf"
                                        :title="`${t.download_pdf}: ${payout.code}`"
                                        :href="url(routes.pdf, payout)"
                                    />
                                </ActionIconGroup>
                            </div>
                        </div>
                    </li>
                </ul>
            </template>

            <TablePagination
                :data="payouts"
                :showing-from="t.pagination_showing"
                :showing-of="t.pagination_of"
                :showing-suffix="t.pagination_suffix"
                :aria-label="t.pagination_label"
                :previous-label="t.pagination_previous"
                :next-label="t.pagination_next"
            />
        </div>
    </AppLayout>
</template>

<style scoped>
.my-payouts__value {
    font-variant-numeric: tabular-nums;
}
</style>
