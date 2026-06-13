<script setup>
import { ref, watch, computed } from 'vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

// Modal de entrada no caixa disparado pela chegada do paciente.
// Espelha o formulário CashierBook do smart_oftal: Fornecedor/Cliente,
// Médico, Procedimento, Serviço (convênio), Valor, Opção de pagamento (5),
// Parcela e breakdown crédito/débito/dinheiro nas opções combinadas.
const props = defineProps({
    open:            { type: Boolean, required: true },
    item:            { type: Object,  default: null },
    procedures:      { type: Array,   default: () => [] },
    procedurePrices: { type: Object,  default: () => ({}) }, // { "covenantId:procedureId": price }
    doctors:         { type: Array,   default: () => [] },
    covenants:       { type: Array,   default: () => [] },
    paymentMethods:  { type: Array,   default: () => [] },
    incomeCategories:{ type: Array,   default: () => [] },
    t:               { type: Object,  default: () => ({}) },
});

const emit   = defineEmits(['close', 'saved']);
const saving = ref(false);
const errors = ref({});

const form = ref(blank());

function blank() {
    return {
        entry_date:     new Date().toISOString().slice(0, 10),
        description:    '',
        doctor_id:      '',
        procedure_id:   '',
        covenant_id:    '',
        category_id:    '',
        payment_method: 'cash',
        amount:         null,
        amount_cash:    null,
        amount_credit:  null,
        amount_debit:   null,
        installments:   0,
        status:         'paid',
        notes:          '',
    };
}

// Pré-preenche a partir do agendamento ao abrir.
watch(() => props.open, (val) => {
    if (!val) return;
    errors.value = {};
    const it = props.item ?? {};
    form.value = {
        ...blank(),
        description:  it.name && it.name !== '—' ? it.name : '',
        doctor_id:    it.doctor_id  ?? '',
        covenant_id:  it.covenant_id ?? '',
        procedure_id: it.visit_procedure_id ?? '', // procedimento padrão do Tipo de consulta
    };
});

// Convênio cobrável (faturado via guia): o lançamento é co-participação do
// paciente, NÃO o valor cheio do convênio — por isso não pré-preenchemos.
const billsCovenant = computed(() => !!props.item?.bills_covenant);

// Pré-preenche o valor a partir da tabela de preços (procedimento × convênio).
// O usuário pode sobrescrever manualmente depois. Não pré-preenche em cortesia
// nem em convênio cobrável (evita registrar o valor do convênio no balcão).
watch(
    () => [form.value.procedure_id, form.value.covenant_id],
    ([procId, covId]) => {
        if (isCourtesy.value || billsCovenant.value) return;
        if (!procId || !covId) return;
        const price = props.procedurePrices[`${covId}:${procId}`];
        if (price !== undefined && price !== null) {
            form.value.amount = Number(price);
        }
    },
);

// Metadados da forma de pagamento selecionada (controla campos visíveis).
const selectedMethod = computed(() =>
    props.paymentMethods.find(m => m.value === form.value.payment_method) ?? {},
);
const showsInstallments = computed(() => !!selectedMethod.value.uses_installments);
const showsCredit       = computed(() => !!selectedMethod.value.shows_credit);
const showsDebit        = computed(() => !!selectedMethod.value.shows_debit);
const showsCash         = computed(() => !!selectedMethod.value.shows_cash);
const isCombined        = computed(() => !!selectedMethod.value.is_combined);
const isCourtesy        = computed(() => !!selectedMethod.value.is_courtesy);

// Cortesia: zera o valor (lançamento de R$ 0).
watch(isCourtesy, (courtesy) => {
    if (courtesy) form.value.amount = 0;
});

// Soma do breakdown deve bater com o valor total nas formas combinadas.
const breakdownSum = computed(() =>
    (Number(form.value.amount_credit) || 0)
    + (Number(form.value.amount_debit) || 0)
    + (Number(form.value.amount_cash) || 0),
);
const breakdownMismatch = computed(() =>
    isCombined.value && Number(form.value.amount || 0).toFixed(2) !== breakdownSum.value.toFixed(2),
);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function submit() {
    if (!props.item?.cash_entry_url) return;
    if (breakdownMismatch.value) {
        errors.value = { amount: [props.t.cash_breakdown_mismatch ?? 'A soma dos valores não confere com o total.'] };
        return;
    }

    saving.value = true;
    errors.value = {};

    // Zera campos de breakdown não aplicáveis à forma escolhida.
    const payload = {
        ...form.value,
        amount_credit: showsCredit.value ? form.value.amount_credit : null,
        amount_debit:  showsDebit.value  ? form.value.amount_debit  : null,
        amount_cash:   showsCash.value   ? form.value.amount_cash   : null,
        installments:  showsInstallments.value ? form.value.installments : 0,
    };

    try {
        const res = await fetch(props.item.cash_entry_url, {
            method:  'POST',
            headers: {
                Accept:         'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(payload),
        });
        const json = await res.json();

        if (res.status === 422) {
            errors.value = json.errors ?? {};
            if (!json.errors && json.message && window.showErrorToast) window.showErrorToast(json.message);
            return;
        }
        if (!res.ok) {
            if (window.showErrorToast) window.showErrorToast(json.message ?? 'Erro ao salvar.');
            return;
        }
        if (window.showSuccessToast) window.showSuccessToast(json.message ?? 'Lançamento registrado.');
        emit('saved', json.data);
    } finally {
        saving.value = false;
    }
}

function close() {
    if (saving.value) return;
    emit('close');
}

function hasError(f)   { return !!(errors.value[f] && errors.value[f].length); }
function firstError(f) { return errors.value[f]?.[0] ?? ''; }
</script>

<template>
    <div
        v-if="open"
        class="modal d-block"
        tabindex="-1"
        style="background: rgba(0,0,0,.45);"
        @click.self="close"
    >
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="ti ti-cash-register me-1 text-primary"></i>
                        {{ t.cash_modal_title ?? 'Entrada no caixa' }}
                    </h5>
                    <button type="button" class="btn-close" :disabled="saving" @click="close"></button>
                </div>

                <div class="modal-body">
                    <!-- Convênio cobrável: o que se lança aqui é a co-participação do paciente. -->
                    <div v-if="billsCovenant" class="alert alert-info py-2 small mb-3">
                        <i class="ti ti-info-circle me-1"></i>
                        {{ t.cash_copay_notice ?? 'Atendimento de convênio: registre aqui apenas a co-participação do paciente. O valor do convênio é faturado à parte via guia.' }}
                    </div>

                    <form @submit.prevent="submit" class="row g-3">
                        <!-- Fornecedor / Cliente (paciente) + Médico -->
                        <div class="col-md-8">
                            <label class="form-label">{{ t.cash_client ?? 'Fornecedor / Cliente' }} <span class="text-danger">*</span></label>
                            <input v-model="form.description" type="text" maxlength="255" class="form-control"
                                   :class="{ 'is-invalid': hasError('description') }" required>
                            <div class="invalid-feedback">{{ firstError('description') }}</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t.cash_doctor ?? 'Médico' }}</label>
                            <SearchSelect v-model="form.doctor_id" :options="doctors"
                                          :placeholder="t.cash_doctor ?? 'Médico'" :invalid="hasError('doctor_id')" />
                            <div v-if="hasError('doctor_id')" class="invalid-feedback d-block">{{ firstError('doctor_id') }}</div>
                        </div>

                        <!-- Procedimento + Serviço (convênio) + Valor -->
                        <div class="col-md-4">
                            <label class="form-label">{{ t.cash_procedure ?? 'Procedimento' }}</label>
                            <SearchSelect v-model="form.procedure_id" :options="procedures"
                                          :placeholder="t.cash_procedure ?? 'Procedimento'" :invalid="hasError('procedure_id')" />
                            <div v-if="hasError('procedure_id')" class="invalid-feedback d-block">{{ firstError('procedure_id') }}</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t.cash_service ?? 'Serviço' }}</label>
                            <SearchSelect v-model="form.covenant_id" :options="covenants"
                                          :placeholder="t.cash_service ?? 'Serviço'" :invalid="hasError('covenant_id')" />
                            <div v-if="hasError('covenant_id')" class="invalid-feedback d-block">{{ firstError('covenant_id') }}</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ t.cash_amount ?? 'Valor (R$)' }} <span class="text-danger">*</span></label>
                            <input v-model.number="form.amount" type="number" step="0.01" min="0" class="form-control"
                                   :class="{ 'is-invalid': hasError('amount') }" :disabled="isCourtesy" required>
                            <div class="invalid-feedback">{{ firstError('amount') }}</div>
                        </div>

                        <!-- Opção de pagamento -->
                        <div class="col-md-6">
                            <label class="form-label">{{ t.cash_option ?? 'Opção' }} <span class="text-danger">*</span></label>
                            <SearchSelect v-model="form.payment_method" :options="paymentMethods"
                                          :value-key="'value'" :label-key="'label'"
                                          :placeholder="t.cash_option ?? 'Opção'" :clearable="false"
                                          :invalid="hasError('payment_method')" />
                            <div v-if="hasError('payment_method')" class="invalid-feedback d-block">{{ firstError('payment_method') }}</div>
                        </div>

                        <!-- Parcela (crédito / crédito+dinheiro) -->
                        <div v-if="showsInstallments" class="col-md-6">
                            <label class="form-label">{{ t.cash_installments ?? 'Parcela' }}</label>
                            <input v-model.number="form.installments" type="number" min="0" max="12" class="form-control"
                                   :class="{ 'is-invalid': hasError('installments') }">
                            <div class="invalid-feedback">{{ firstError('installments') }}</div>
                        </div>

                        <!-- Breakdown combinado -->
                        <div v-if="showsCredit" class="col-md-4">
                            <label class="form-label">{{ t.cash_amount_credit ?? 'Valor do crédito' }} <span class="text-danger">*</span></label>
                            <input v-model.number="form.amount_credit" type="number" step="0.01" min="0" class="form-control"
                                   :class="{ 'is-invalid': hasError('amount_credit') }">
                            <div class="invalid-feedback">{{ firstError('amount_credit') }}</div>
                        </div>
                        <div v-if="showsDebit" class="col-md-4">
                            <label class="form-label">{{ t.cash_amount_debit ?? 'Valor do débito' }} <span class="text-danger">*</span></label>
                            <input v-model.number="form.amount_debit" type="number" step="0.01" min="0" class="form-control"
                                   :class="{ 'is-invalid': hasError('amount_debit') }">
                            <div class="invalid-feedback">{{ firstError('amount_debit') }}</div>
                        </div>
                        <div v-if="showsCash" class="col-md-4">
                            <label class="form-label">{{ t.cash_amount_cash ?? 'Valor em dinheiro' }} <span class="text-danger">*</span></label>
                            <input v-model.number="form.amount_cash" type="number" step="0.01" min="0" class="form-control"
                                   :class="{ 'is-invalid': hasError('amount_cash') }">
                            <div class="invalid-feedback">{{ firstError('amount_cash') }}</div>
                        </div>

                        <div v-if="breakdownMismatch" class="col-12">
                            <div class="alert alert-warning py-2 mb-0 small">
                                {{ t.cash_breakdown_mismatch ?? 'A soma dos valores não confere com o total.' }}
                                ({{ breakdownSum.toFixed(2) }} / {{ Number(form.amount || 0).toFixed(2) }})
                            </div>
                        </div>

                        <!-- Categoria + Status -->
                        <div class="col-md-6">
                            <label class="form-label">{{ t.cash_category ?? 'Categoria' }}</label>
                            <SearchSelect v-model="form.category_id" :options="incomeCategories"
                                          :placeholder="t.cash_category ?? 'Categoria'" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ t.cash_status ?? 'Status' }}</label>
                            <SearchSelect v-model="form.status"
                                          :options="[{ value: 'paid', label: t.cash_status_paid ?? 'Pago' }, { value: 'pending', label: t.cash_status_pending ?? 'Pendente' }]"
                                          :value-key="'value'" :label-key="'label'" :clearable="false" />
                        </div>

                        <div class="col-12">
                            <label class="form-label">{{ t.cash_notes ?? 'Observações' }}</label>
                            <textarea v-model="form.notes" rows="2" class="form-control" maxlength="2000"></textarea>
                        </div>
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="close">
                        {{ t.cash_cancel ?? 'Cancelar' }}
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="submit">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ti ti-check me-1"></i>
                        {{ t.cash_save ?? 'Registrar entrada' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
