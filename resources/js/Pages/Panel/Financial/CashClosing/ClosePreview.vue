<script setup>
import { computed, useId } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans';

/**
 * Prévia (só leitura) do período a fechar: os mesmos totais que o servidor
 * grava no snapshot (incluindo pendentes), contagem, pendentes com atalho para
 * o Fluxo filtrado e o quadro por forma de pagamento. `stale` = datas mudaram e
 * a prévia ainda não foi atualizada (fica esmaecida).
 */
const props = defineProps({
    preview: { type: Object, default: () => ({}) },
    loading: { type: Boolean, default: false },
    stale: { type: Boolean, default: false },
    pendingHref: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const { money, signedMoney, number } = useLocaleFormat();
const { tx } = useTrans(() => props.t);

const uid = useId();
const title = `cash-close-preview-title-${uid}`;

const pendingCount = computed(() => Number(props.preview.pending_count ?? 0));
const pendingIncome = computed(() => Number(props.preview.pending_income ?? props.preview.pending ?? 0));
const pendingExpense = computed(() => Number(props.preview.pending_expense ?? 0));
const hasPending = computed(() => pendingCount.value > 0 || pendingIncome.value > 0 || pendingExpense.value > 0);

const byMethod = computed(() => props.preview.by_payment_method ?? []);
</script>

<template>
    <section
        class="border rounded p-3 cash-close-preview"
        :class="{ 'cash-close-preview--stale': loading || stale }"
        :aria-labelledby="title"
        aria-live="polite"
        :aria-busy="loading ? 'true' : 'false'"
        data-test="preview"
    >
        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
            <h3 :id="title" class="h6 fw-bold mb-0">{{ t.preview }}</h3>
            <span v-if="loading" class="small text-muted" data-test="preview-loading">
                <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ t.preview_loading }}
            </span>
        </div>

        <dl class="row row-cols-2 g-2 small mb-3">
            <div class="col">
                <dt class="text-muted fw-normal">
                    <i class="ti ti-arrow-down-left text-success me-1" aria-hidden="true"></i>{{ t.income }}
                </dt>
                <dd class="fw-bold mb-0 text-body cash-close-preview__value" data-test="preview-income">
                    {{ money(preview.income ?? 0) }}
                </dd>
            </div>
            <div class="col">
                <dt class="text-muted fw-normal">
                    <i class="ti ti-arrow-up-right text-danger me-1" aria-hidden="true"></i>{{ t.expense }}
                </dt>
                <dd class="fw-bold mb-0 text-body cash-close-preview__value" data-test="preview-expense">
                    {{ money(preview.expense ?? 0) }}
                </dd>
            </div>
            <div class="col">
                <dt class="text-muted fw-normal">
                    <i class="ti ti-scale text-info me-1" aria-hidden="true"></i>{{ t.balance }}
                </dt>
                <dd class="fw-bold mb-0 text-body cash-close-preview__value" data-test="preview-balance">
                    {{ signedMoney(preview.balance ?? 0) }}
                </dd>
            </div>
            <div class="col">
                <dt class="text-muted fw-normal">
                    <i class="ti ti-list-numbers me-1" aria-hidden="true"></i>{{ t.entries_count }}
                </dt>
                <dd class="fw-bold mb-0 text-body cash-close-preview__value" data-test="preview-count">
                    {{ number(preview.entries_count ?? 0) }}
                </dd>
            </div>
        </dl>

        <!-- Pendentes (entram nos totais do fechamento) -->
        <div class="cash-close-preview__block mb-3" data-test="preview-pending">
            <h4 class="small fw-bold mb-1">
                <i class="ti ti-clock text-warning me-1" aria-hidden="true"></i>{{ t.pending_title }}
            </h4>
            <template v-if="hasPending">
                <p class="small mb-1" data-test="preview-pending-summary">
                    {{
                        tx('pending_summary', {
                            count: number(pendingCount),
                            income: money(pendingIncome),
                            expense: money(pendingExpense),
                        })
                    }}
                </p>
                <Link v-if="pendingHref" :href="pendingHref" class="small" data-test="view-pending">
                    <i class="ti ti-list-search me-1" aria-hidden="true"></i>{{ t.view_pending }}
                </Link>
            </template>
            <p v-else class="small text-muted mb-0">{{ t.pending_none }}</p>
        </div>

        <!-- Por forma de pagamento -->
        <div class="cash-close-preview__block mb-2">
            <h4 class="small fw-bold mb-1">
                <i class="ti ti-credit-card me-1" aria-hidden="true"></i>{{ t.by_payment_method }}
            </h4>
            <div v-if="byMethod.length" class="table-responsive">
                <table class="table table-sm small align-middle mb-0" data-test="by-payment-method">
                    <caption class="visually-hidden">
                        {{
                            t.by_payment_method
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ t.col_payment_method }}</th>
                            <th scope="col" class="text-end">{{ t.income }}</th>
                            <th scope="col" class="text-end">{{ t.expense }}</th>
                            <th scope="col" class="text-end">{{ t.col_count }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in byMethod"
                            :key="row.method ?? 'none'"
                            :data-test="`method-${row.method ?? 'none'}`"
                        >
                            <td>{{ row.label ?? t.payment_method_none }}</td>
                            <td class="text-end text-nowrap cash-close-preview__value">{{ money(row.income ?? 0) }}</td>
                            <td class="text-end text-nowrap cash-close-preview__value">
                                {{ money(row.expense ?? 0) }}
                            </td>
                            <td class="text-end cash-close-preview__value">{{ number(row.count ?? 0) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="small text-muted mb-0">{{ t.by_payment_method_empty }}</p>
        </div>

        <p class="small text-muted mb-0">{{ t.preview_hint }}</p>
    </section>
</template>

<style scoped>
.cash-close-preview {
    transition: opacity var(--ee-duration-fast, 150ms) ease;
}

.cash-close-preview--stale {
    opacity: 0.55;
}

.cash-close-preview__value {
    font-variant-numeric: tabular-nums;
}

@media (prefers-reduced-motion: reduce) {
    .cash-close-preview {
        transition: none;
    }
}
</style>
