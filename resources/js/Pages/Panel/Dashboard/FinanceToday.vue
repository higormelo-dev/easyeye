<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Financeiro — operação de hoje: caixa do dia (entradas e saídas pagas hoje,
 * saldo e o que ainda vence hoje), contas a receber (lançamentos pendentes do
 * caixa e guias de convênio aguardando pagamento, com o vencido à parte) e
 * glosas a tratar (prazo de recurso vencido/vencendo). Só valores — nenhum
 * paciente. Cada bloco abre a lista com o mesmo recorte.
 */
const props = defineProps({
    // DashboardInsightsService::cashToday() (polling)
    cash: { type: Object, default: null },
    // insights.receivables / insights.glosas (abertura + "Atualizar")
    receivables: { type: Object, default: null },
    glosas: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const { money, signedMoney, number } = useLocaleFormat();
const tx = (key, params = {}) =>
    Object.entries(params).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), props.t[key] ?? '');

const balanceTone = computed(() => {
    const value = Number(props.cash?.realized_balance ?? 0);

    return value > 0 ? 'good' : value < 0 ? 'bad' : 'neutral';
});

const glosaTotal = computed(() => Number(props.glosas?.open_count ?? 0) + Number(props.glosas?.appealed_count ?? 0));
</script>

<template>
    <section class="db-section" :aria-label="t.section_finance">
        <div class="db-finance-grid">
            <!-- Caixa de hoje -->
            <article class="card db-card" data-tour="dashboard-finance-today">
                <div class="db-card-header">
                    <h3 class="db-card-title">
                        <i class="ti ti-cash-register" aria-hidden="true"></i>
                        {{ t.cash_today_title }}
                    </h3>
                    <a v-if="cash?.url" :href="cash.url" class="db-link">
                        {{ t.cash_today_open }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
                <div class="db-card-body">
                    <!-- Mesmo desenho dos outros dois cards: o número-chave em
                         destaque (saldo do dia) e o detalhe em linhas. -->
                    <div class="db-figure db-figure--hero">
                        <span class="db-figure__value" :class="`db-figure__value--${balanceTone}`" data-cash="balance">
                            {{ signedMoney(cash?.realized_balance ?? 0) }}
                        </span>
                        <span class="db-figure__hint">{{ t.cash_balance }}</span>
                    </div>
                    <ul class="db-rows">
                        <li>
                            <span class="db-rows__label">{{ t.cash_in }}</span>
                            <span class="db-rows__value text-success-emphasis" data-cash="received">{{
                                money(cash?.received ?? 0)
                            }}</span>
                        </li>
                        <li>
                            <span class="db-rows__label">{{ t.cash_out }}</span>
                            <span class="db-rows__value text-danger-emphasis" data-cash="paid">{{
                                money(cash?.paid ?? 0)
                            }}</span>
                        </li>
                    </ul>
                    <ul class="db-rows">
                        <li>
                            <span class="db-rows__label">{{ t.cash_receivable_today }}</span>
                            <span class="db-rows__value" data-cash="receivable">{{
                                money(cash?.receivable ?? 0)
                            }}</span>
                        </li>
                        <li>
                            <span class="db-rows__label">{{ t.cash_payable_today }}</span>
                            <span class="db-rows__value" data-cash="payable">{{ money(cash?.payable ?? 0) }}</span>
                        </li>
                        <li>
                            <span class="db-rows__label">{{ t.cash_projected }}</span>
                            <span class="db-rows__value" data-cash="projected">{{
                                signedMoney(cash?.projected_balance ?? 0)
                            }}</span>
                        </li>
                        <li>
                            <span class="db-rows__label">{{ t.cash_entries_count }}</span>
                            <span class="db-rows__value">{{ number(cash?.entries_count ?? 0) }}</span>
                        </li>
                    </ul>
                </div>
            </article>

            <!-- A receber -->
            <article class="card db-card" data-tour="dashboard-receivables">
                <div class="db-card-header">
                    <h3 class="db-card-title">
                        <i class="ti ti-receipt" aria-hidden="true"></i>
                        {{ t.receivables_title }}
                    </h3>
                    <span class="db-card-meta">{{ t.receivables_sub }}</span>
                </div>
                <div class="db-card-body">
                    <div v-if="loading" class="db-chart-skeleton db-chart-skeleton--short" aria-hidden="true"></div>
                    <template v-else>
                        <div class="db-figure db-figure--hero">
                            <span class="db-figure__value" data-receivable="total">{{
                                money(receivables?.total ?? 0)
                            }}</span>
                            <span v-if="(receivables?.overdue ?? 0) > 0" class="db-chip db-chip--failed">
                                {{ tx('kpi_overdue', { amount: money(receivables.overdue) }) }}
                            </span>
                        </div>
                        <ul class="db-rows">
                            <li>
                                <component
                                    :is="receivables?.cash?.url ? 'a' : 'span'"
                                    :href="receivables?.cash?.url ?? undefined"
                                    class="db-rows__label"
                                >
                                    {{ t.receivables_cash }}
                                </component>
                                <span class="db-rows__value">{{ money(receivables?.cash?.upcoming ?? 0) }}</span>
                            </li>
                            <li v-if="(receivables?.cash?.overdue ?? 0) > 0" class="db-rows--alert">
                                <component
                                    :is="receivables.cash.overdue_url ? 'a' : 'span'"
                                    :href="receivables.cash.overdue_url ?? undefined"
                                    class="db-rows__label"
                                >
                                    {{
                                        tx('receivables_cash_overdue', {
                                            count: number(receivables.cash.overdue_count),
                                        })
                                    }}
                                </component>
                                <span class="db-rows__value">{{ money(receivables.cash.overdue) }}</span>
                            </li>
                            <li>
                                <component
                                    :is="receivables?.claims?.url ? 'a' : 'span'"
                                    :href="receivables?.claims?.url ?? undefined"
                                    class="db-rows__label"
                                >
                                    {{
                                        tx('receivables_claims', {
                                            count: number(receivables?.claims?.open_count ?? 0),
                                        })
                                    }}
                                </component>
                                <span class="db-rows__value">{{ money(receivables?.claims?.open ?? 0) }}</span>
                            </li>
                            <li v-if="(receivables?.claims?.overdue ?? 0) > 0" class="db-rows--alert">
                                <span class="db-rows__label">
                                    {{
                                        tx('receivables_claims_overdue', {
                                            count: number(receivables.claims.overdue_count),
                                        })
                                    }}
                                </span>
                                <span class="db-rows__value">{{ money(receivables.claims.overdue) }}</span>
                            </li>
                        </ul>
                    </template>
                </div>
            </article>

            <!-- Glosas -->
            <article class="card db-card" data-tour="dashboard-glosas">
                <div class="db-card-header">
                    <h3 class="db-card-title">
                        <i class="ti ti-file-x" aria-hidden="true"></i>
                        {{ t.glosas_title }}
                        <span class="badge db-count" :class="{ 'db-count--active': glosaTotal > 0 }">{{
                            number(glosaTotal)
                        }}</span>
                    </h3>
                    <a v-if="glosas?.url" :href="glosas.url" class="db-link">
                        {{ t.glosas_open }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
                <div class="db-card-body">
                    <div v-if="loading" class="db-chart-skeleton db-chart-skeleton--short" aria-hidden="true"></div>
                    <div v-else-if="glosaTotal === 0" class="db-empty db-empty--inline db-empty--flush">
                        <i class="ti ti-circle-check" aria-hidden="true"></i>
                        <span>{{ t.glosas_empty }}</span>
                    </div>
                    <template v-else>
                        <div class="db-figure db-figure--hero">
                            <span class="db-figure__value" data-glosa="open">{{
                                money((glosas.open ?? 0) + (glosas.appealed ?? 0))
                            }}</span>
                            <span class="db-figure__hint">{{ t.glosas_amount_hint }}</span>
                        </div>
                        <ul class="db-rows">
                            <li v-if="glosas.overdue_count > 0" class="db-rows--alert">
                                <a :href="glosas.overdue_url" class="db-rows__label">
                                    {{ tx('glosas_overdue', { count: number(glosas.overdue_count) }) }}
                                </a>
                                <span class="db-rows__value">{{ money(glosas.overdue) }}</span>
                            </li>
                            <li v-if="glosas.due_soon_count > 0" class="db-rows--warn">
                                <a :href="glosas.due_soon_url" class="db-rows__label">
                                    {{
                                        tx('glosas_due_soon', {
                                            count: number(glosas.due_soon_count),
                                            days: number(glosas.due_soon_days ?? 5),
                                        })
                                    }}
                                </a>
                                <span class="db-rows__value">{{ money(glosas.due_soon) }}</span>
                            </li>
                            <li>
                                <span class="db-rows__label">{{
                                    tx('glosas_open_count', { count: number(glosas.open_count ?? 0) })
                                }}</span>
                                <span class="db-rows__value">{{ money(glosas.open ?? 0) }}</span>
                            </li>
                            <li v-if="glosas.appealed_count > 0">
                                <span class="db-rows__label">{{
                                    tx('glosas_appealed', { count: number(glosas.appealed_count) })
                                }}</span>
                                <span class="db-rows__value">{{ money(glosas.appealed) }}</span>
                            </li>
                        </ul>
                    </template>
                </div>
            </article>
        </div>
    </section>
</template>
