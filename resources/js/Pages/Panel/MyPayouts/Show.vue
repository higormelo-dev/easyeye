<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import FlashMessage from '@/Pages/Panel/Financial/DoctorPayouts/FlashMessage.vue';
import PaymentsList from '@/Pages/Panel/Financial/DoctorPayouts/PaymentsList.vue';
import StatementView from '@/Pages/Panel/Financial/DoctorPayouts/StatementView.vue';
import { useDoctorPayoutFormat } from '@/Pages/Panel/Financial/DoctorPayouts/useDoctorPayoutFormat.js';

/**
 * "Meus repasses" › demonstrativo (médico, só leitura): o mesmo
 * StatementView da clínica, sem ajustes, pagamento ou reabertura — só PDF e
 * voltar. Pagamentos: só os válidos (data, valor, forma) e o saldo a receber.
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    statement: { type: Object, required: true }, // { payout, groups, adjustments, payments }
    routes: { type: Object, required: true }, // { index, pdf }
    t: { type: Object, default: () => ({}) },
});

const { money } = useDoctorPayoutFormat(() => props.t);

const code = computed(() => props.statement?.payout?.code ?? '');
const payments = computed(() => props.statement?.payments ?? []);
const remaining = computed(() => Number(props.statement?.payout?.remaining_amount ?? 0));
</script>

<template>
    <AppLayout :title="`${t.statement_title} ${code}`" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.statement_title">
                <template #actions>
                    <a :href="routes.pdf" class="btn btn-outline-secondary btn-sm" data-test="statement-pdf">
                        <i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i>{{ t.download_pdf }}
                    </a>
                </template>
            </PageHeader>

            <p class="small mb-3">
                <Link :href="routes.index" data-test="back-my-payouts">
                    <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ t.my_title }}
                </Link>
            </p>

            <FlashMessage />

            <div class="d-grid gap-3">
                <StatementView :statement="statement" :t="t" />

                <section
                    v-if="payments.length"
                    class="card mb-0"
                    aria-labelledby="my-payments-title"
                    data-test="my-payments"
                >
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <h3 id="my-payments-title" class="h6 fw-bold mb-0">
                            <i class="ti ti-cash-banknote me-1 text-primary" aria-hidden="true"></i
                            >{{ t.payments_title }}
                        </h3>
                        <span
                            v-if="remaining > 0"
                            class="ms-auto small fw-medium text-warning-emphasis"
                            data-test="my-balance"
                        >
                            {{ t.payment_balance }}: {{ money(remaining) }}
                        </span>
                    </div>
                    <div class="card-body">
                        <PaymentsList :payments="payments" :t="t" />
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
