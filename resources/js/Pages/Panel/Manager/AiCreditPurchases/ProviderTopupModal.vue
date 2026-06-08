<script setup>
import { ref, watch, computed } from 'vue';

/**
 * Modal para registrar uma recarga (topup) que o time fez no painel
 * de um provedor de IA (OpenAI/Claude/Gemini).
 *
 * NÃO faz cobrança real — apenas registra para cálculo do saldo estimado.
 */
const props = defineProps({
    open:           { type: Boolean, required: true },
    presetProvider: { type: String,  default: '' },
    t:              { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'submit']);

const PROVIDERS = [
    { value: 'openai',    label: 'ChatGPT', icon: 'ti ti-brand-openai' },
    { value: 'anthropic', label: 'Claude',  icon: 'ti ti-message-chatbot' },
    { value: 'gemini',    label: 'Gemini',  icon: 'ti ti-brand-google' },
];

const form = ref({
    provider:     'openai',
    amount_brl:   null,   // o que você pagou (cartão/fatura)
    amount_usd:   100,    // o que o provedor creditou (base do saldo)
    topped_up_at: new Date().toISOString().slice(0, 16),
    reference:    '',
    note:         '',
});

const saving = ref(false);
const errorMessage = ref('');

watch(() => props.open, (val) => {
    if (val) {
        form.value = {
            provider:     props.presetProvider || 'openai',
            amount_brl:   null,
            amount_usd:   100,
            topped_up_at: new Date().toISOString().slice(0, 16),
            reference:    '',
            note:         '',
        };
        errorMessage.value = '';
    }
});

// Cotação efetiva (R$ por US$) — o que você pagou de fato por dólar.
const effectiveRate = computed(() => {
    const brl = Number(form.value.amount_brl) || 0;
    const usd = Number(form.value.amount_usd) || 0;
    return usd > 0 && brl > 0 ? brl / usd : null;
});

const isValid = computed(() =>
    form.value.provider
    && Number(form.value.amount_usd) > 0
    && Number(form.value.amount_brl) > 0
    && form.value.topped_up_at,
);

function close() {
    if (saving.value) return;
    emit('close');
}

async function submit() {
    if (!isValid.value || saving.value) return;
    emit('submit', {
        provider:     form.value.provider,
        amount_brl:   Number(form.value.amount_brl),
        amount_usd:   Number(form.value.amount_usd),
        topped_up_at: form.value.topped_up_at,
        reference:    form.value.reference || null,
        note:         form.value.note || null,
    });
}

function setSaving(value) { saving.value = value; }
function setError(msg) { errorMessage.value = msg; }

defineExpose({ setSaving, setError });
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="modal fade show d-block"
            style="background: rgba(0,0,0,0.45);"
            tabindex="-1"
            @click.self="close"
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">
                                <i class="ti ti-receipt-2 me-2 text-info"></i>
                                {{ t?.topup?.modal_title ?? 'Registrar recarga no provedor' }}
                            </h5>
                            <small class="text-muted d-block mt-1">
                                {{ t?.topup?.modal_subtitle ?? 'Registre o valor que você carregou no painel do provedor. NÃO faz cobrança real — apenas atualiza o saldo estimado.' }}
                            </small>
                        </div>
                        <button type="button" class="btn-close" :disabled="saving" @click="close"></button>
                    </div>

                    <form @submit.prevent="submit">
                        <div class="modal-body">

                            <div v-if="errorMessage" class="alert alert-danger small mb-3 py-2">
                                <i class="ti ti-alert-circle me-1"></i>{{ errorMessage }}
                            </div>

                            <div class="row g-3">
                                <!-- Provedor -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.topup?.provider ?? 'Provedor' }} <span class="text-danger">*</span>
                                    </label>
                                    <div class="provider-selector d-flex gap-2 flex-wrap" role="group">
                                        <template v-for="p in PROVIDERS" :key="p.value">
                                            <input
                                                :id="`topup-prov-${p.value}`"
                                                v-model="form.provider"
                                                type="radio"
                                                class="btn-check"
                                                :value="p.value"
                                                :disabled="saving">
                                            <label
                                                class="btn btn-outline-primary flex-fill text-nowrap"
                                                :for="`topup-prov-${p.value}`"
                                                style="min-width: 0;"
                                            >
                                                <i :class="p.icon" class="me-1"></i>
                                                {{ p.label }}
                                            </label>
                                        </template>
                                    </div>
                                </div>

                                <!-- Valor pago (R$) -->
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.topup?.amount_brl ?? 'Valor pago (R$)' }} <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text">R$</span>
                                        <input
                                            v-model.number="form.amount_brl"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            max="99999999"
                                            class="form-control"
                                            required
                                            :disabled="saving">
                                    </div>
                                    <small class="text-muted">{{ t?.topup?.amount_brl_help ?? 'O que você pagou no cartão/fatura.' }}</small>
                                </div>

                                <!-- Creditado no provedor (US$) -->
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.topup?.amount_usd ?? 'Creditado no provedor (US$)' }} <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input
                                            v-model.number="form.amount_usd"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            max="1000000"
                                            class="form-control"
                                            required
                                            :disabled="saving">
                                    </div>
                                    <small class="text-muted">{{ t?.topup?.amount_usd_help ?? 'Saldo que o provedor adicionou (alimenta o saldo estimado).' }}</small>
                                </div>

                                <!-- Cotação efetiva -->
                                <div v-if="effectiveRate" class="col-12">
                                    <div class="alert alert-light border d-flex align-items-center gap-2 small mb-0 py-2">
                                        <i class="ti ti-exchange text-info"></i>
                                        <span>
                                            {{ t?.topup?.effective_rate ?? 'Cotação efetiva:' }}
                                            <strong>R$ {{ effectiveRate.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) }}</strong>
                                            / US$
                                        </span>
                                    </div>
                                </div>

                                <!-- Data -->
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.topup?.topped_up_at ?? 'Data da recarga' }} <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        v-model="form.topped_up_at"
                                        type="datetime-local"
                                        class="form-control"
                                        required
                                        :disabled="saving">
                                </div>

                                <!-- Referência -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.topup?.reference ?? 'Referência (opcional)' }}
                                    </label>
                                    <input
                                        v-model="form.reference"
                                        type="text"
                                        maxlength="120"
                                        class="form-control"
                                        :placeholder="t?.topup?.reference_placeholder ?? 'Ex.: ch_3MtIxhXKt8, invoice #INV-001'"
                                        :disabled="saving">
                                    <small class="text-muted">{{ t?.topup?.reference_help ?? 'ID/comprovante da transação no painel do provedor — útil para auditoria.' }}</small>
                                </div>

                                <!-- Observação -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.topup?.note ?? 'Observação (opcional)' }}
                                    </label>
                                    <textarea
                                        v-model="form.note"
                                        rows="2"
                                        maxlength="500"
                                        class="form-control"
                                        :disabled="saving"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-link text-muted" :disabled="saving" @click="close">
                                {{ t?.topup?.cancel ?? 'Cancelar' }}
                            </button>
                            <button type="submit" class="btn btn-info" :disabled="!isValid || saving">
                                <i class="ti ti-check me-1"></i>
                                {{ saving ? '...' : (t?.topup?.submit ?? 'Registrar recarga') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </Teleport>
</template>
