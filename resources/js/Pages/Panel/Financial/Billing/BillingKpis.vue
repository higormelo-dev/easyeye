<script setup>
import { computed } from 'vue';
import KpiCard from '@/Components/Panel/KpiCard.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Faixa de KPIs do Faturamento (período = data do atendimento; convênio
 * filtrado — calculados no servidor, BillingService::billingKpis):
 * A faturar (com valor estimado pela tabela de preços), Em aberto e Pendências
 * TISS (botões que filtram a aba Guias), Recebido e Glosado (link para a
 * Conciliação de glosas). Cada card explica a definição no `hint`.
 */
const props = defineProps({
    kpis: { type: Object, default: () => ({}) },
    claimStatus: { type: String, default: '' },
    glosasUrl: { type: String, default: '' },
    loading: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['toggle-status']);

const { tx } = useTrans(() => props.t);
const { money, number } = useLocaleFormat();

const toBill = computed(() => props.kpis?.to_bill ?? {});
const open = computed(() => props.kpis?.open ?? {});
const received = computed(() => props.kpis?.received ?? {});
const denied = computed(() => props.kpis?.denied ?? {});
const tissPending = computed(() => props.kpis?.tiss_pending ?? {});

const toBillSubtitle = computed(() => {
    const amount = toBill.value.estimated_amount;
    if (amount === null || amount === undefined) return props.t.kpi_to_bill_no_estimate;

    return Number(toBill.value.priced_count) < Number(toBill.value.count)
        ? tx('kpi_to_bill_estimate_partial', {
              amount: money(amount),
              priced: number(toBill.value.priced_count),
              count: number(toBill.value.count),
          })
        : tx('kpi_to_bill_estimate', { amount: money(amount) });
});

function claimsCount(bucket) {
    return tx('kpi_claims_count', { count: number(bucket?.count ?? 0) });
}
</script>

<template>
    <section class="mb-3" :aria-label="t.kpis_label" data-test="billing-kpis">
        <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-5 g-3">
            <div class="col">
                <KpiCard
                    :label="t.kpi_to_bill"
                    :value="tx('kpi_attendances', { count: number(toBill.count ?? 0) })"
                    icon="ti ti-list-check"
                    tone="primary"
                    :hint="t.kpi_to_bill_hint"
                    :subtitle="toBillSubtitle"
                    :loading="loading"
                    test-id="to-bill"
                />
            </div>
            <div class="col">
                <KpiCard
                    :label="t.kpi_open"
                    :value="money(open.amount ?? 0)"
                    icon="ti ti-hourglass-high"
                    tone="info"
                    :hint="t.kpi_open_hint"
                    :subtitle="claimsCount(open)"
                    :loading="loading"
                    toggle
                    :active="claimStatus === 'submitted'"
                    test-id="open"
                    data-test="kpi-toggle-open"
                    @click="emit('toggle-status', 'submitted')"
                />
            </div>
            <div class="col">
                <KpiCard
                    :label="t.kpi_received"
                    :value="money(received.amount ?? 0)"
                    icon="ti ti-cash"
                    tone="success"
                    :hint="t.kpi_received_hint"
                    :subtitle="claimsCount(received)"
                    :loading="loading"
                    test-id="received"
                />
            </div>
            <div class="col">
                <KpiCard
                    :label="t.kpi_denied"
                    :value="money(denied.amount ?? 0)"
                    icon="ti ti-receipt-off"
                    tone="danger"
                    :hint="t.kpi_denied_hint"
                    :subtitle="claimsCount(denied)"
                    :href="glosasUrl"
                    :loading="loading"
                    test-id="denied"
                    data-test="kpi-link-denied"
                >
                    <span v-if="glosasUrl" class="d-inline-flex align-items-center gap-1 small text-primary mt-1">
                        {{ t.kpi_denied_link }}<i class="ti ti-arrow-right" aria-hidden="true"></i>
                    </span>
                </KpiCard>
            </div>
            <div class="col">
                <KpiCard
                    :label="t.kpi_tiss_pending"
                    :value="number(tissPending.count ?? 0)"
                    icon="ti ti-alert-triangle"
                    tone="warning"
                    :hint="t.kpi_tiss_pending_hint"
                    :subtitle="claimsCount(tissPending)"
                    :loading="loading"
                    toggle
                    :active="claimStatus === 'tiss_pending'"
                    test-id="tiss-pending"
                    data-test="kpi-toggle-tiss-pending"
                    @click="emit('toggle-status', 'tiss_pending')"
                />
            </div>
        </div>
    </section>
</template>
