<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout                   from '@/Layouts/AppLayout.vue';
import PageHeader                  from '@/Components/Panel/PageHeader.vue';
import ActionIconButton            from '@/Components/Panel/ActionIconButton.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import ReportExportMenu            from '@/Pages/Panel/Financial/Reports/ReportExportMenu.vue';
import AdjustmentForm from './AdjustmentForm.vue';
import FlashMessage   from './FlashMessage.vue';
import PaymentPanel   from './PaymentPanel.vue';
import PayoutTabs     from './PayoutTabs.vue';
import StatementView  from './StatementView.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Financeiro › Repasse médico › Demonstrativo (clínica): o fechamento com
 * ajustes manuais (enquanto não pago), registro do pagamento e, só para
 * admin, estornar o pagamento ou reabrir (cancelar) o fechamento — ambos com
 * motivo (ConfirmationWithReasonModal; limites de reason_limits). O servidor
 * decide as permissões (`permissions`) e revalida tudo.
 */
const props = defineProps({
    breadcrumbs:     { type: Array,  default: () => [] },
    tabs:            { type: Object, default: () => ({}) },
    statement:       { type: Object, required: true },   // { payout, groups, adjustments }
    permissions:     { type: Object, default: () => ({}) },
    payment_methods: { type: Array,  default: () => [] },
    today:           { type: String, default: '' },
    reason_limits:   { type: Object, default: () => ({ min: 10, max: 1000 }) },
    routes:          { type: Object, required: true },
    t:               { type: Object, default: () => ({}) },
    shared:          { type: Object, default: () => ({}) },
});

const { periodText } = useDoctorPayoutFormat(() => props.t);

const payout      = computed(() => props.statement?.payout ?? {});
const can         = computed(() => props.permissions ?? {});
const showPayment = computed(() => payout.value.status === 'paid' || !!can.value.can_pay);
const adminOnly   = computed(() => !can.value.is_admin && ['closed', 'paid'].includes(payout.value.status));

function firstMessage(errors) {
    const first = errors && typeof errors === 'object' ? Object.values(errors)[0] : null;

    return Array.isArray(first) ? String(first[0] ?? '') : String(first ?? '');
}

// ── Exportação do demonstrativo ─────────────────────────────────────────────
const exportOptions = computed(() => [
    { key: 'csv',  icon: 'ti ti-file-type-csv',    label: props.t.export_csv },
    { key: 'xlsx', icon: 'ti ti-file-spreadsheet', label: props.t.export_xlsx },
].map((option) => ({ ...option, href: `${props.routes.export}?format=${option.key}` })));

// ── Remover ajuste (confirmação simples; o servidor recusa se já pago) ──────
const removingId  = ref(null);
const actionError = ref('');

function removeAdjustment(adjustment) {
    if (!can.value.can_adjust || removingId.value) return;
    if (!window.confirm(props.t.adjustment_remove_title)) return;

    removingId.value  = adjustment.id;
    actionError.value = '';

    router.delete(props.routes.adjustments_destroy.replace('__ID__', adjustment.id), {
        preserveScroll: true,
        onError:  (errors) => { actionError.value = firstMessage(errors); },
        onFinish: () => { removingId.value = null; },
    });
}

// ── Estornar pagamento / reabrir: só admin, com motivo ──────────────────────
const REASON_ACTIONS = {
    reverse: { route: 'reverse', permission: 'can_reverse', title: 'reverse_payment_title', message: 'reverse_payment_hint', confirm: 'reverse_payment' },
    reopen:  { route: 'reopen',  permission: 'can_reopen',  title: 'reopen_title',          message: 'reopen_hint',          confirm: 'reopen' },
};

const reasonAction = ref(null);
const reasonBusy   = ref(false);
const reasonError  = ref('');

const reasonConfig = computed(() => REASON_ACTIONS[reasonAction.value] ?? null);

function askReason(action) {
    const config = REASON_ACTIONS[action];
    if (!config || !can.value[config.permission]) return;

    reasonError.value  = '';
    reasonAction.value = action;
}

function cancelReason() {
    if (reasonBusy.value) return;

    reasonAction.value = null;
}

function confirmReason(reason) {
    const config = reasonConfig.value;
    if (!config || reasonBusy.value) return;

    reasonBusy.value  = true;
    reasonError.value = '';

    router.delete(props.routes[config.route], {
        data:           { reason },
        preserveScroll: true,
        onSuccess: (page) => {
            // entity.role nega com redirect + flash de erro (visita Inertia "ok").
            const denied = page?.props?.flash?.error;
            if (denied) {
                reasonError.value = String(denied);

                return;
            }

            reasonAction.value = null;
        },
        onError:  (errors) => { reasonError.value = firstMessage(errors); },
        onFinish: () => { reasonBusy.value = false; },
    });
}
</script>

<template>
    <AppLayout :title="`${t.statement_title} ${payout.code ?? ''}`" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.statement_title">
                <template #actions>
                    <div class="d-flex flex-wrap gap-2">
                        <a :href="routes.pdf" class="btn btn-outline-secondary btn-sm" data-test="statement-pdf">
                            <i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i>{{ t.download_pdf }}
                        </a>
                        <ReportExportMenu :options="exportOptions" :title="t.export" :label="t.export" />
                    </div>
                </template>
            </PageHeader>

            <PayoutTabs :tabs="tabs" current="closings" :t="t" />

            <div class="d-flex flex-wrap gap-3 small mb-3">
                <Link :href="routes.closings" data-test="back-closings">
                    <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ t.tabs?.closings }}
                </Link>
                <Link :href="routes.apuracao" data-test="back-apuracao">
                    <i class="ti ti-calculator me-1" aria-hidden="true"></i>{{ t.tabs?.apuracao }} · {{ periodText(payout.period_start, payout.period_end) }}
                </Link>
            </div>

            <FlashMessage />

            <div v-if="actionError" class="alert alert-danger d-flex gap-2" role="alert" data-test="action-error">
                <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i><span>{{ actionError }}</span>
            </div>

            <div class="d-grid gap-3">
                <StatementView :statement="statement" :t="t">
                    <template v-if="can.can_adjust" #adjustment-actions="{ adjustment }">
                        <ActionIconButton
                            icon="ti ti-trash"
                            variant="danger"
                            :title="`${t.adjustment_remove}: ${adjustment.description}`"
                            :disabled="removingId === adjustment.id"
                            data-test="adjustment-remove"
                            @click="removeAdjustment(adjustment)"
                        />
                    </template>
                    <template v-if="can.can_adjust" #adjustments-footer>
                        <AdjustmentForm :action="routes.adjustments_store" :t="t" />
                    </template>
                </StatementView>

                <PaymentPanel
                    v-if="showPayment"
                    :payout="payout"
                    :can-pay="!!can.can_pay"
                    :payment-methods="payment_methods"
                    :today="today"
                    :pay-url="routes.pay"
                    :cash-flow-url="routes.cash_flow"
                    :t="t"
                />

                <div v-if="can.can_reverse || can.can_reopen || adminOnly" class="card mb-0" data-test="admin-actions">
                    <div class="card-body d-flex flex-wrap gap-4">
                        <div v-if="can.can_reverse" class="show__admin-action">
                            <button type="button" class="btn btn-outline-danger btn-sm" data-test="reverse-open" @click="askReason('reverse')">
                                <i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i>{{ t.reverse_payment }}
                            </button>
                            <p class="small text-muted mb-0 mt-2">{{ t.reverse_payment_hint }}</p>
                        </div>
                        <div v-if="can.can_reopen" class="show__admin-action">
                            <button type="button" class="btn btn-outline-danger btn-sm" data-test="reopen-open" @click="askReason('reopen')">
                                <i class="ti ti-lock-open me-1" aria-hidden="true"></i>{{ t.reopen }}
                            </button>
                            <p class="small text-muted mb-0 mt-2">{{ t.reopen_hint }}</p>
                        </div>
                        <p v-if="adminOnly" class="small text-muted mb-0" data-test="admin-only">
                            <i class="ti ti-shield-lock me-1" aria-hidden="true"></i>{{ t.admin_only }}
                        </p>
                    </div>
                </div>
            </div>

            <ConfirmationWithReasonModal
                :open="!!reasonConfig"
                :title="reasonConfig ? t[reasonConfig.title] : ''"
                :message="reasonConfig ? t[reasonConfig.message] : ''"
                :confirm-label="reasonConfig ? t[reasonConfig.confirm] : ''"
                confirm-variant="danger"
                :saving="reasonBusy"
                :min-length="Number(reason_limits.min)"
                :max-length="Number(reason_limits.max)"
                :error="reasonError"
                @close="cancelReason"
                @confirm="confirmReason"
            />
        </div>
    </AppLayout>
</template>

<style scoped>
.show__admin-action {
    max-width: 26rem;
}
</style>
