<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Faturado × recebido do mês por convênio (financeiro): os convênios de maior
 * faturamento (mesma fonte do BI — BillingReportService), com a barra do que
 * já foi recebido e do que foi glosado. Só convênios e valores; nenhum
 * paciente.
 */
const props = defineProps({
    // [{ covenant_id, label, value (faturado), paid, denied }]
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const { money, number } = useLocaleFormat();
const tx = (key, params = {}) =>
    Object.entries(params).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), props.t[key] ?? '');

const max = computed(() => Math.max(1, ...props.rows.map((row) => Number(row.value) || 0)));

const items = computed(() =>
    props.rows.map((row) => {
        const billed = Number(row.value) || 0;
        const paid = Math.min(Number(row.paid) || 0, billed);
        const denied = Math.min(Number(row.denied) || 0, Math.max(0, billed - paid));

        return {
            key: row.covenant_id || row.label,
            label: row.label,
            billed,
            paid,
            denied,
            width: (billed / max.value) * 100,
            paidPct: billed > 0 ? (paid / billed) * 100 : 0,
            deniedPct: billed > 0 ? (denied / billed) * 100 : 0,
        };
    }),
);

const receiptRate = computed(() => {
    const billed = Number(props.totals.billed) || 0;

    return billed > 0 ? ((Number(props.totals.paid) || 0) / billed) * 100 : null;
});
</script>

<template>
    <div class="db-covenants">
        <div class="db-chart__totals">
            <span><strong>{{ money(totals.billed ?? 0) }}</strong> {{ t.covenants_billed }}</span>
            <span><strong>{{ money(totals.paid ?? 0) }}</strong> {{ t.covenants_paid }}</span>
            <span v-if="receiptRate !== null">{{ tx('covenants_rate', { rate: number(receiptRate, 1) }) }}</span>
        </div>

        <div v-if="!items.length" class="db-empty db-empty--inline">
            <i class="ti ti-file-invoice" aria-hidden="true"></i>
            <span>{{ t.covenants_empty }}</span>
        </div>

        <ul v-else class="db-covenants__list">
            <li v-for="item in items" :key="item.key" class="db-covenants__item">
                <div class="db-covenants__line">
                    <span class="db-covenants__name">{{ item.label }}</span>
                    <span class="db-covenants__value">{{ money(item.billed) }}</span>
                </div>
                <div
                    class="db-covenants__track"
                    role="img"
                    :aria-label="
                        tx('covenants_row_aria', {
                            name: item.label,
                            billed: money(item.billed),
                            paid: money(item.paid),
                            denied: money(item.denied),
                        })
                    "
                >
                    <div class="db-covenants__bar" :style="{ width: `${item.width}%` }">
                        <span class="db-covenants__paid" :style="{ width: `${item.paidPct}%` }"></span>
                        <span class="db-covenants__denied" :style="{ width: `${item.deniedPct}%` }"></span>
                    </div>
                </div>
                <div class="db-covenants__sub">
                    {{ tx('covenants_row', { paid: money(item.paid) }) }}
                    <template v-if="item.denied > 0"> · {{ tx('covenants_denied', { denied: money(item.denied) }) }}</template>
                </div>
            </li>
        </ul>

        <ul class="db-legend db-covenants__legend" aria-hidden="true">
            <li class="db-legend--billed">{{ t.covenants_billed }}</li>
            <li class="db-legend--paid">{{ t.covenants_paid }}</li>
            <li class="db-legend--denied">{{ t.covenants_denied_label }}</li>
        </ul>
    </div>
</template>
