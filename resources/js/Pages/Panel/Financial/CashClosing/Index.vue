<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import PeriodFilter from '@/Components/Panel/PeriodFilter.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans';
import ClosePreview from './ClosePreview.vue';
import CloseHistory from './CloseHistory.vue';

/**
 * Fechamento de caixa: período (PeriodFilter, sem datas futuras) com prévia só
 * leitura atualizada com debounce, confirmação com resumo antes de fechar e
 * histórico. Reabrir: só admin e com motivo (ConfirmationWithReasonModal) —
 * a rota exige entity.role:admin e o ReopenCashCloseRequest exige o motivo.
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    closes: { type: Object, required: true },
    // CashClosingService::preview(): summary() + contagens, pendentes, por forma e sobreposição.
    preview: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    last_close_end: { type: String, default: null },
    today: { type: String, default: '' },
    can_reopen: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const { money, signedMoney, number, date } = useLocaleFormat();
const { tx } = useTrans(() => props.t);

const uid = useId();
const ids = {
    notes: `cash-close-notes-${uid}`,
    confirmTitle: `cash-close-confirm-title-${uid}`,
};

const PREVIEW_DEBOUNCE_MS = 400;
/** Mesmo mínimo/máximo do ReopenCashCloseRequest. */
const REOPEN_REASON_MIN = 10;
const REOPEN_REASON_MAX = 1000;

const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const notes = ref('');
const errors = ref({});

function firstMessage(errs) {
    const first = errs && typeof errs === 'object' ? Object.values(errs)[0] : null;

    return Array.isArray(first) ? String(first[0] ?? '') : String(first ?? '');
}

// ── Período: o PeriodFilter só emite intervalo válido (≤ hoje) ──────────────
/**
 * Data digitada inválida (ex.: futura) não vira `change`: o PeriodFilter emite
 * `invalid` e mostra o erro. Sem isto, o botão seguiria liberado para o último
 * intervalo válido enquanto a tela exibe outro.
 */
const periodInvalid = ref(false);

function onPeriodInvalid() {
    periodInvalid.value = true;
}

// ── Prévia: recarrega com debounce ──────────────────────────────────────────
const previewLoading = ref(false);
let previewTimer = null;

/** A prévia exibida corresponde às datas escolhidas? (senão, não deixa fechar) */
const previewMatches = computed(() => from.value === props.filters.from && to.value === props.filters.to);

function refreshPreview() {
    previewTimer = null;
    router.get(
        route('panel.financial.cash-closing.index'),
        { from: from.value, to: to.value },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['preview', 'filters'],
            onStart: () => {
                previewLoading.value = true;
            },
            onFinish: () => {
                previewLoading.value = false;
            },
        },
    );
}

function onPeriodChange(range) {
    periodInvalid.value = false;
    from.value = range.from;
    to.value = range.to;
    errors.value = {};

    clearTimeout(previewTimer);
    previewTimer = null;

    if (previewMatches.value) return;

    previewTimer = setTimeout(refreshPreview, PREVIEW_DEBOUNCE_MS);
}

// Servidor normaliza (ex.: limita a hoje): a barra acompanha, sem nova visita.
watch(
    () => [props.filters.from, props.filters.to],
    ([nextFrom, nextTo]) => {
        if (previewTimer) return;

        from.value = nextFrom ?? '';
        to.value = nextTo ?? '';
    },
);

onBeforeUnmount(() => clearTimeout(previewTimer));

const hasPending = computed(
    () =>
        Number(props.preview.pending_count ?? 0) > 0 ||
        Number(props.preview.pending ?? 0) > 0 ||
        Number(props.preview.pending_expense ?? 0) > 0,
);

const pendingHref = computed(() =>
    route('panel.financial.cash-flow.index', {
        from: props.filters.from,
        to: props.filters.to,
        status: 'pending',
    }),
);

const overlapText = computed(() =>
    (props.preview.overlapping_periods ?? []).map((p) => periodText(p.period_start, p.period_end)).join(', '),
);

const canClose = computed(
    () =>
        !periodInvalid.value &&
        !!from.value &&
        !!to.value &&
        previewMatches.value &&
        !previewLoading.value &&
        !props.preview.overlaps,
);

// ── Fechamento: confirmação com resumo antes do POST ────────────────────────
const confirmOpen = ref(false);
const saving = ref(false);
const closeError = ref('');
const confirmCancel = ref(null);

function askClose() {
    if (!canClose.value) return;

    closeError.value = '';
    confirmOpen.value = true;
}

function cancelClose() {
    if (saving.value) return;

    confirmOpen.value = false;
}

function confirmClose() {
    if (saving.value) return;

    saving.value = true;
    closeError.value = '';
    errors.value = {};

    router.post(
        route('panel.financial.cash-closing.store'),
        { period_start: from.value, period_end: to.value, notes: notes.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                confirmOpen.value = false;
                notes.value = '';
                window.showSuccessToast?.(props.t.closed);
            },
            onError: (errs) => {
                errors.value = errs ?? {};
                closeError.value = firstMessage(errs) || props.t.close_error;
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

// ── Reabertura: só admin, com motivo ────────────────────────────────────────
const reopening = ref(null);
const reopenBusy = ref(false);
const reopenError = ref('');

function askReopen(close) {
    if (!props.can_reopen) return;

    reopenError.value = '';
    reopening.value = close;
}

function cancelReopen() {
    if (reopenBusy.value) return;

    reopening.value = null;
}

function confirmReopen(reason) {
    const close = reopening.value;
    if (!close || reopenBusy.value) return;

    reopenBusy.value = true;
    reopenError.value = '';

    router.delete(route('panel.financial.cash-closing.destroy', close.id), {
        data: { reason },
        preserveScroll: true,
        onSuccess: (page) => {
            reopening.value = null;

            // entity.role nega com redirect + flash de erro (visita Inertia "ok").
            const denied = page?.props?.flash?.error;
            if (denied) {
                reopenError.value = String(denied);

                return;
            }

            window.showSuccessToast?.(props.t.reopened);
        },
        onError: (errs) => {
            reopening.value = null;
            reopenError.value = firstMessage(errs) || props.t.reopen_error;
        },
        onFinish: () => {
            reopenBusy.value = false;
        },
    });
}

const reopenMessage = computed(() =>
    reopening.value
        ? tx('reopen_message', { from: date(reopening.value.period_start), to: date(reopening.value.period_end) })
        : '',
);

const reopenBusyId = computed(() => (reopenBusy.value ? (reopening.value?.id ?? null) : null));

// ── Teclado: Esc fecha o modal aberto; foco inicial em "Cancelar" ───────────
// (o ConfirmationWithReasonModal compartilhado não trata Esc sozinho)
function onKeydown(event) {
    if (event.key !== 'Escape') return;

    if (confirmOpen.value) cancelClose();
    else if (reopening.value) cancelReopen();
}

watch(
    () => confirmOpen.value || !!reopening.value,
    async (anyOpen) => {
        if (!anyOpen) {
            document.removeEventListener('keydown', onKeydown);

            return;
        }

        document.addEventListener('keydown', onKeydown);

        if (confirmOpen.value) {
            await nextTick();
            confirmCancel.value?.focus();
        }
    },
);

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

// ── Links ───────────────────────────────────────────────────────────────────
const cashFlowHref = computed(() =>
    route('panel.financial.cash-flow.index', { from: props.filters.from, to: props.filters.to }),
);

function periodText(start, end) {
    return `${date(start)} – ${date(end)}`;
}
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.page_title">
                <template #actions>
                    <Link :href="cashFlowHref" class="btn btn-outline-secondary btn-sm" data-test="cash-flow-link">
                        <i class="ti ti-cash-register me-1" aria-hidden="true"></i>{{ t.back_to_cash_flow }}
                    </Link>
                </template>
            </PageHeader>
            <p class="text-muted small mb-3">{{ t.subtitle }}</p>

            <div class="row g-3">
                <!-- Fechar novo período -->
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-body">
                            <h2 class="h6 fw-bold mb-3">{{ t.form_title }}</h2>
                            <form class="d-grid gap-3" novalidate @submit.prevent="askClose">
                                <div data-test="period">
                                    <PeriodFilter
                                        :from="from"
                                        :to="to"
                                        :today="today"
                                        :max="today"
                                        :labels="t.shared?.period"
                                        :disabled="saving"
                                        @change="onPeriodChange"
                                        @invalid="onPeriodInvalid"
                                    />
                                    <p
                                        v-if="last_close_end"
                                        class="small text-muted mt-2 mb-0"
                                        data-test="last-close-hint"
                                    >
                                        <i class="ti ti-history me-1" aria-hidden="true"></i
                                        >{{ tx('last_close_hint', { date: date(last_close_end) }) }}
                                    </p>
                                    <div
                                        v-if="errors.period_start || errors.period_end"
                                        class="small text-danger mt-1"
                                        role="alert"
                                        data-test="period-server-error"
                                    >
                                        {{ errors.period_start || errors.period_end }}
                                    </div>
                                </div>

                                <ClosePreview
                                    :preview="preview"
                                    :loading="previewLoading"
                                    :stale="!previewMatches || periodInvalid"
                                    :pending-href="pendingHref"
                                    :t="t"
                                />

                                <div
                                    v-if="preview.overlaps && previewMatches"
                                    class="alert alert-warning small d-flex gap-2 mb-0"
                                    role="alert"
                                    data-test="overlap-warning"
                                >
                                    <i class="ti ti-alert-triangle mt-1" aria-hidden="true"></i>
                                    <div>
                                        <p class="mb-0">{{ t.overlap_warning }}</p>
                                        <p v-if="overlapText" class="mb-0 mt-1" data-test="overlap-periods">
                                            {{ tx('overlap_periods', { periods: overlapText }) }}
                                        </p>
                                    </div>
                                </div>

                                <div>
                                    <label :for="ids.notes" class="form-label">{{ t.notes }}</label>
                                    <textarea
                                        :id="ids.notes"
                                        v-model="notes"
                                        rows="2"
                                        class="form-control"
                                        maxlength="2000"
                                    ></textarea>
                                </div>

                                <button
                                    type="submit"
                                    class="btn btn-primary w-100"
                                    :disabled="!canClose || saving"
                                    data-test="close-btn"
                                >
                                    <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ t.close_btn }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Histórico de períodos fechados -->
                <div class="col-lg-7">
                    <CloseHistory
                        :closes="closes"
                        :can-reopen="can_reopen"
                        :busy-id="reopenBusyId"
                        :reopen-error="reopenError"
                        :t="t"
                        @reopen="askReopen"
                    />
                </div>
            </div>

            <!-- Confirmação do fechamento: período e totais antes de travar -->
            <CenteredModal :open="confirmOpen" size="md" @close="cancelClose">
                <template #header>
                    <h5 :id="ids.confirmTitle" class="modal-title mb-0">
                        <i class="ti ti-lock me-1 text-primary" aria-hidden="true"></i>{{ t.confirm_title }}
                    </h5>
                </template>

                <div data-test="confirm-summary">
                    <p class="small mb-3">{{ tx('confirm_intro', { from: date(from), to: date(to) }) }}</p>
                    <dl class="row small mb-0">
                        <dt class="col-6 fw-medium">{{ t.confirm_period }}</dt>
                        <dd class="col-6 mb-1" data-test="confirm-period">{{ periodText(from, to) }}</dd>
                        <dt class="col-6 fw-medium">{{ t.entries_count }}</dt>
                        <dd class="col-6 mb-1" data-test="confirm-count">{{ number(preview.entries_count ?? 0) }}</dd>
                        <dt class="col-6 fw-medium">{{ t.income }}</dt>
                        <dd class="col-6 mb-1" data-test="confirm-income">{{ money(preview.income) }}</dd>
                        <dt class="col-6 fw-medium">{{ t.expense }}</dt>
                        <dd class="col-6 mb-1" data-test="confirm-expense">{{ money(preview.expense) }}</dd>
                        <dt class="col-6 fw-medium">{{ t.balance }}</dt>
                        <dd class="col-6 mb-1 fw-bold" data-test="confirm-balance">
                            {{ signedMoney(preview.balance) }}
                        </dd>
                        <template v-if="notes">
                            <dt class="col-6 fw-medium">{{ t.notes }}</dt>
                            <dd class="col-6 mb-1 text-break">{{ notes }}</dd>
                        </template>
                    </dl>

                    <div
                        v-if="hasPending"
                        class="alert alert-warning small d-flex gap-2 mt-3 mb-0"
                        role="status"
                        data-test="confirm-pending"
                    >
                        <i class="ti ti-clock mt-1" aria-hidden="true"></i>
                        <span>{{
                            tx('confirm_pending_warning', {
                                count: number(preview.pending_count ?? 0),
                                income: money(preview.pending ?? 0),
                                expense: money(preview.pending_expense ?? 0),
                            })
                        }}</span>
                    </div>

                    <div
                        v-if="closeError"
                        class="alert alert-danger small d-flex gap-2 mt-3 mb-0"
                        role="alert"
                        data-test="close-error"
                    >
                        <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i>
                        <span>{{ closeError }}</span>
                    </div>
                </div>

                <template #footer>
                    <button
                        ref="confirmCancel"
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        :disabled="saving"
                        @click="cancelClose"
                    >
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="saving"
                        :aria-busy="saving ? 'true' : 'false'"
                        data-test="confirm-close"
                        @click="confirmClose"
                    >
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        <i v-else class="ti ti-lock me-1" aria-hidden="true"></i>
                        {{ t.confirm_btn }}
                    </button>
                </template>
            </CenteredModal>

            <!-- Reabertura: só admin, motivo obrigatório (vai para o fechamento e a auditoria) -->
            <ConfirmationWithReasonModal
                :open="!!reopening"
                :title="t.reopen_title"
                :message="reopenMessage"
                :confirm-label="t.reopen_confirm"
                confirm-variant="danger"
                :saving="reopenBusy"
                :min-length="REOPEN_REASON_MIN"
                :max-length="REOPEN_REASON_MAX"
                @close="cancelReopen"
                @confirm="confirmReopen"
            />
        </div>
    </AppLayout>
</template>
