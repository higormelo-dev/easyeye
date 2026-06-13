<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    closes:      { type: Array,  default: () => [] },
    preview:     { type: Object, default: () => ({}) },
    filters:     { type: Object, default: () => ({}) },
    t:           { type: Object, default: () => ({}) },
});

const from   = ref(props.filters.from ?? '');
const to     = ref(props.filters.to ?? '');
const notes  = ref('');
const saving = ref(false);
const errors = ref({});

function brl(v) {
    return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Recarrega a prévia (income/expense/balance) ao trocar o intervalo.
function refreshPreview() {
    router.get(
        route('panel.financial.cash-closing.index'),
        { from: from.value, to: to.value },
        { preserveState: true, preserveScroll: true, only: ['preview', 'filters'] },
    );
}

function close() {
    saving.value = true;
    errors.value = {};
    router.post(
        route('panel.financial.cash-closing.store'),
        { period_start: from.value, period_end: to.value, notes: notes.value },
        {
            preserveScroll: true,
            onSuccess: () => { notes.value = ''; if (window.showSuccessToast) window.showSuccessToast(props.t.cc_closed ?? 'Período fechado.'); },
            onError:   (e) => { errors.value = e; },
            onFinish:  () => { saving.value = false; },
        },
    );
}

function reopen(id) {
    if (!confirm(props.t.cc_confirm_reopen ?? 'Reabrir este período?')) return;
    router.delete(route('panel.financial.cash-closing.destroy', id), {
        preserveScroll: true,
        onSuccess: () => { if (window.showSuccessToast) window.showSuccessToast(props.t.cc_reopened ?? 'Período reaberto.'); },
    });
}
</script>

<template>
    <AppLayout :title="t.cc_title ?? 'Fechamento de Caixa'" :breadcrumbs="breadcrumbs">
        <div class="container-fluid">
            <PageHeader :title="t.cc_title ?? 'Fechamento de Caixa'" :subtitle="t.cc_subtitle ?? ''" />

            <div class="row g-3">
                <!-- Fechar novo período -->
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-6">
                                    <label class="form-label">{{ t.cc_period_start ?? 'Início' }}</label>
                                    <input v-model="from" type="date" class="form-control"
                                           :class="{ 'is-invalid': errors.period_start }" @change="refreshPreview">
                                    <div class="invalid-feedback">{{ errors.period_start }}</div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">{{ t.cc_period_end ?? 'Fim' }}</label>
                                    <input v-model="to" type="date" class="form-control"
                                           :class="{ 'is-invalid': errors.period_end }" @change="refreshPreview">
                                    <div class="invalid-feedback">{{ errors.period_end }}</div>
                                </div>

                                <div class="col-12">
                                    <div class="d-flex justify-content-between border rounded p-2 small">
                                        <span class="text-success">{{ t.cc_income ?? 'Receitas' }}: {{ brl(preview.income) }}</span>
                                        <span class="text-danger">{{ t.cc_expense ?? 'Despesas' }}: {{ brl(preview.expense) }}</span>
                                        <span class="fw-bold">{{ t.cc_balance ?? 'Saldo' }}: {{ brl(preview.balance) }}</span>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">{{ t.cc_notes ?? 'Observações' }}</label>
                                    <textarea v-model="notes" rows="2" class="form-control" maxlength="2000"></textarea>
                                </div>

                                <div class="col-12">
                                    <button type="button" class="btn btn-primary w-100" :disabled="saving" @click="close">
                                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                                        <i v-else class="ti ti-lock me-1"></i>
                                        {{ t.cc_close_btn ?? 'Fechar período' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Histórico de períodos fechados -->
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header fw-bold">{{ t.cc_history ?? 'Períodos fechados' }}</div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ t.cc_period_start ?? 'Início' }} — {{ t.cc_period_end ?? 'Fim' }}</th>
                                            <th class="text-end">{{ t.cc_balance ?? 'Saldo' }}</th>
                                            <th>{{ t.cc_closed_at ?? 'Fechado em' }}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-if="!closes.length">
                                            <td colspan="4" class="text-center text-muted py-3">{{ t.cc_empty ?? 'Nenhum período fechado.' }}</td>
                                        </tr>
                                        <tr v-for="c in closes" :key="c.id">
                                            <td>{{ c.period_start }} — {{ c.period_end }}</td>
                                            <td class="text-end">{{ brl(c.balance) }}</td>
                                            <td class="small text-muted">{{ c.closed_at }}</td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" @click="reopen(c.id)">
                                                    <i class="ti ti-lock-open me-1"></i>{{ t.cc_reopen ?? 'Reabrir' }}
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
