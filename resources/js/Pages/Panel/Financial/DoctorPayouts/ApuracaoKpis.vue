<script setup>
import { computed, useId } from 'vue';
import KpiCard from '@/Components/Panel/KpiCard.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Indicadores da apuração no regime por recebimento: o que dá para liberar
 * (parcelas com recebimento até o fim do período e a base recebida), o total
 * a repassar (a liberar + fechado ainda não pago), o que já foi fechado/pago e
 * a PREVISÃO dos atos que ainda aguardam recebimento — previsão nunca soma com
 * liberado. "Sem regra" > 0 fica em destaque e abre a lista desses itens
 * (`noRuleUrl`) — eles bloqueiam o fechamento.
 *
 * "Recebimento da clínica" (kpis.receipt): o que já entrou no caixa e o que
 * ainda falta receber — o repasse é liberado sobre o recebido.
 */
const props = defineProps({
    kpis:      { type: Object,  default: () => ({}) },
    noRuleUrl: { type: String,  default: '' },
    loading:   { type: Boolean, default: false },
    t:         { type: Object,  default: () => ({}) },
});

const { tx, money, number, countText } = useDoctorPayoutFormat(() => props.t);

const noRule = computed(() => Number(props.kpis?.no_rule ?? 0));

const production = computed(() => {
    const count = number(props.kpis?.production_count ?? 0);

    return [
        { key: 'production', label: props.t.kpi_production, value: count, icon: 'ti ti-list-numbers', tone: 'primary', hint: countText('kpi_production_hint', props.kpis?.production_count ?? 0) },
        {
            key:   'to_release',
            label: props.t.kpi_to_release,
            value: money(props.kpis?.to_release ?? 0),
            icon:  'ti ti-user-dollar',
            tone:  'primary',
            hint:  tx('kpi_to_release_hint', { count: number(props.kpis?.release_count ?? 0) }),
        },
        { key: 'release_base', label: props.t.kpi_release_base, value: money(props.kpis?.release_base ?? 0), icon: 'ti ti-cash-banknote', tone: 'success', hint: props.t.kpi_release_base_hint ?? '' },
        { key: 'to_transfer', label: props.t.kpi_to_transfer, value: money(props.kpis?.to_transfer ?? 0), icon: 'ti ti-sum', tone: 'primary', hint: props.t.kpi_to_transfer_hint ?? '' },
    ];
});

const situation = computed(() => [
    { key: 'paid', label: props.t.kpi_paid, value: money(props.kpis?.paid ?? 0), icon: 'ti ti-circle-check', tone: 'success' },
    { key: 'to_pay', label: props.t.kpi_to_pay, value: money(props.kpis?.to_pay ?? 0), icon: 'ti ti-lock', tone: 'info' },
    {
        key:   'awaiting',
        label: props.t.kpi_awaiting,
        value: money(props.kpis?.awaiting_forecast ?? 0),
        icon:  'ti ti-hourglass',
        tone:  'secondary',
        hint:  tx('kpi_awaiting_hint', { count: number(props.kpis?.awaiting_count ?? 0) }),
    },
    {
        key:   'no_rule',
        label: props.t.kpi_no_rule,
        value: number(noRule.value),
        icon:  'ti ti-alert-octagon',
        tone:  noRule.value > 0 ? 'danger' : 'secondary',
        href:  noRule.value > 0 ? props.noRuleUrl : '',
        hint:  noRule.value > 0 ? (props.t.kpi_no_rule_hint ?? '') : '',
        alert: noRule.value > 0,
    },
]);

// ── Recebimento da clínica (rastreio, somente consulta) ─────────────────────
const receiptTitleId = `dp-receipt-title-${useId()}`;
const receipt = computed(() => props.kpis?.receipt ?? null);

const receiptCards = computed(() => {
    const totals = receipt.value ?? {};
    const glosa  = Number(totals.glosa ?? 0);

    return [
        { key: 'billed', label: props.t.kpi_billed, value: money(totals.billed ?? 0), icon: 'ti ti-file-invoice', tone: 'secondary', hint: props.t.kpi_billed_hint ?? '' },
        { key: 'glosa', label: props.t.kpi_glosa, value: money(glosa), icon: 'ti ti-ban', tone: glosa > 0 ? 'danger' : 'secondary' },
        { key: 'received', label: props.t.kpi_received, value: money(totals.received ?? 0), icon: 'ti ti-cash-banknote', tone: 'success', hint: props.t.kpi_received_hint ?? '' },
        { key: 'open', label: props.t.kpi_open, value: money(totals.open ?? 0), icon: 'ti ti-hourglass', tone: 'warning', hint: tx('kpi_open_hint', { count: number(totals.open_count ?? 0) }) },
    ];
});

/** Avisos que pedem ação do financeiro (só aparecem quando há o que conferir). */
const receiptNotes = computed(() => {
    const totals = receipt.value ?? {};
    const notes  = [];

    if (Number(totals.unconfirmed_count ?? 0) > 0) {
        notes.push({ key: 'unconfirmed', icon: 'ti ti-alert-triangle', tone: 'text-danger-emphasis', text: tx('receipt_unconfirmed', { count: number(totals.unconfirmed_count) }) });
    }
    if (Number(totals.difference ?? 0) !== 0) {
        notes.push({ key: 'difference', icon: 'ti ti-scale', tone: 'text-warning-emphasis', text: tx('receipt_difference', { value: money(totals.difference) }) });
    }
    if (Number(totals.not_linked_count ?? 0) > 0) {
        notes.push({ key: 'not_linked', icon: 'ti ti-link-off', tone: 'text-muted', text: tx('receipt_not_linked', { count: number(totals.not_linked_count) }) });
    }

    return notes;
});
</script>

<template>
    <div data-test="kpis">
        <div class="row g-2 row-cols-2 row-cols-lg-4 mb-2">
            <div v-for="kpi in production" :key="kpi.key" class="col" :data-kpi="kpi.key">
                <KpiCard
                    :label="kpi.label"
                    :value="kpi.value"
                    :icon="kpi.icon"
                    :tone="kpi.tone"
                    :hint="kpi.hint ?? ''"
                    :loading="loading"
                    :test-id="kpi.key"
                />
            </div>
        </div>
        <div class="row g-2 row-cols-2 row-cols-lg-4">
            <div v-for="kpi in situation" :key="kpi.key" class="col" :data-kpi="kpi.key">
                <KpiCard
                    :label="kpi.label"
                    :value="kpi.value"
                    :icon="kpi.icon"
                    :tone="kpi.tone"
                    :hint="kpi.hint ?? ''"
                    :href="kpi.href ?? ''"
                    :loading="loading"
                    :test-id="kpi.key"
                    :class="{ 'bg-danger-subtle': kpi.alert }"
                />
            </div>
        </div>

        <section v-if="receipt" class="mt-3" :aria-labelledby="receiptTitleId" data-test="receipt-kpis">
            <h2 :id="receiptTitleId" class="h6 fw-bold mb-1">{{ t.receipt_title }}</h2>
            <p class="small text-muted mb-2">{{ t.receipt_intro }}</p>
            <div class="row g-2 row-cols-2 row-cols-lg-4">
                <div v-for="kpi in receiptCards" :key="kpi.key" class="col" :data-kpi="`receipt_${kpi.key}`">
                    <KpiCard
                        :label="kpi.label"
                        :value="kpi.value"
                        :icon="kpi.icon"
                        :tone="kpi.tone"
                        :hint="kpi.hint ?? ''"
                        :loading="loading"
                        :test-id="`receipt_${kpi.key}`"
                    />
                </div>
            </div>
            <ul v-if="receiptNotes.length" class="list-unstyled small d-grid gap-1 mt-2 mb-0">
                <li v-for="note in receiptNotes" :key="note.key" :class="note.tone" :data-test="`receipt-note-${note.key}`">
                    <i :class="note.icon" class="me-1" aria-hidden="true"></i>{{ note.text }}
                </li>
            </ul>
        </section>
    </div>
</template>
