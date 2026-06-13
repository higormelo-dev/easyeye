<script setup>
import { ref, computed, watch } from 'vue';
import SearchSelect from "@/Components/Panel/SearchSelect.vue";

/**
 * Modal para conceder crédito IA a uma clínica — cortesia (grátis) ou compra (paga).
 *
 * O crédito cai direto na carteira da clínica (saldo único, não expira). Não há
 * seletor de provedor: o provedor é decidido pelo sistema na hora do uso; aqui só
 * importa quantos créditos e se foi cortesia ou compra paga fora do app.
 *
 * RBAC (vem de props.permissions):
 *   - create_manual             : exibe o botão de submit
 *   - create_manual_unlimited   : Admin/Financial — sem limite diário
 *   - create_manual_for_internal: Admin apenas — pode escolher a entidade interna
 */
const props = defineProps({
    open:           { type: Boolean, required: true },
    entities:       { type: Array,   default: () => [] },
    permissions:    { type: Object,  default: () => ({}) },
    presetEntityId: { type: String,  default: null },
    t:              { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'submit']);

const EMPTY_FORM = () => ({
    entity_id:    '',
    kind:         'courtesy',  // 'courtesy' (grátis) | 'purchase' (paga fora do app)
    credits:      100,
    amount_reais: null,        // só usado em compra paga
    reason:       '',
});

const form = ref(EMPTY_FORM());

const saving = ref(false);
const errorMessage = ref('');

watch(() => props.open, (val) => {
    if (val) {
        form.value = { ...EMPTY_FORM(), entity_id: props.presetEntityId ?? '' };
        errorMessage.value = '';
    }
});

const isCourtesy = computed(() => form.value.kind === 'courtesy');

// Ao voltar para cortesia, limpa o valor para não enviar resíduo.
watch(() => form.value.kind, (kind) => {
    if (kind === 'courtesy') form.value.amount_reais = null;
});

const filteredEntities = computed(() => {
    if (props.permissions?.create_manual_for_internal) return props.entities;
    return props.entities.filter(e => e.is_client);
});

const entityOptions = computed(() =>
    filteredEntities.value.map(e => ({
        id: e.id,
        name: `${e.name}${!e.is_client ? ' ★' : ''}`,
    })),
);

const selectedEntityIsInternal = computed(() => {
    const e = props.entities.find(x => x.id === form.value.entity_id);
    return e ? !e.is_client : false;
});

const amountReais = computed(() => Number(form.value.amount_reais) || 0);

const isValid = computed(() =>
    form.value.entity_id
    && form.value.credits > 0
    && form.value.reason.trim().length >= 10
    && (isCourtesy.value || amountReais.value > 0),
);

function close() {
    if (saving.value) return;
    emit('close');
}

async function submit() {
    if (!isValid.value || saving.value) return;

    saving.value = true;
    errorMessage.value = '';

    try {
        await emit('submit', {
            entity_id:    form.value.entity_id,
            kind:         form.value.kind,
            credits:      form.value.credits,
            reason:       form.value.reason,
            amount_reais: isCourtesy.value ? 0 : amountReais.value,
        });
    } catch (e) {
        errorMessage.value = e?.message || 'Erro ao conceder crédito.';
    } finally {
        saving.value = false;
    }
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
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">
                                <i class="ti ti-coin-plus me-2 text-success"></i>
                                {{ t?.manual?.modal_title ?? 'Conceder crédito a uma clínica' }}
                            </h5>
                            <small class="text-muted d-block mt-1">
                                {{ t?.manual?.modal_subtitle ?? '' }}
                            </small>
                        </div>
                        <button type="button" class="btn-close" :disabled="saving" @click="close"></button>
                    </div>

                    <form @submit.prevent="submit">
                        <div class="modal-body">

                            <!-- Aviso de limite Support -->
                            <div
                                v-if="!permissions?.create_manual_unlimited"
                                class="alert alert-warning d-flex align-items-start gap-2 small mb-3 py-2"
                            >
                                <i class="ti ti-shield-half-filled mt-1"></i>
                                <div>
                                    {{ (t?.manual?.limit_warning ?? 'Limite diário: :limit créditos.')
                                        .replace(':limit', permissions?.support_daily_limit ?? '?')
                                        .replace(':used', '—') }}
                                </div>
                            </div>

                            <!-- Erro -->
                            <div v-if="errorMessage" class="alert alert-danger small mb-3 py-2">
                                <i class="ti ti-alert-circle me-1"></i>{{ errorMessage }}
                            </div>

                            <div class="row g-3">

                                <!-- Clínica destinatária -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.manual?.select_entity ?? 'Clínica destinatária' }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <SearchSelect
                                        v-model="form.entity_id"
                                        :options="entityOptions"
                                        :placeholder="'—'"
                                        :clearable="false"
                                        :disabled="saving"
                                    />
                                    <small class="text-muted">{{ t?.manual?.select_entity_help ?? '' }}</small>
                                    <div v-if="selectedEntityIsInternal" class="mt-2">
                                        <span class="badge bg-primary-subtle text-primary border border-primary border-opacity-25">
                                            <i class="ti ti-building me-1"></i>
                                            {{ t?.manual?.badge_internal ?? 'Sua empresa' }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Como conceder: Cortesia (grátis) x Compra (paga) -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.manual?.kind ?? 'Como conceder' }} <span class="text-danger">*</span>
                                    </label>
                                    <div class="d-flex gap-2" role="group">
                                        <input id="kind-courtesy" v-model="form.kind" type="radio" class="btn-check" value="courtesy" :disabled="saving">
                                        <label class="btn btn-outline-info flex-fill" for="kind-courtesy">
                                            <i class="ti ti-gift me-1"></i>{{ t?.manual?.kind_courtesy ?? 'Cortesia (grátis)' }}
                                        </label>
                                        <input id="kind-purchase" v-model="form.kind" type="radio" class="btn-check" value="purchase" :disabled="saving">
                                        <label class="btn btn-outline-success flex-fill" for="kind-purchase">
                                            <i class="ti ti-cash me-1"></i>{{ t?.manual?.kind_purchase ?? 'Compra (paga)' }}
                                        </label>
                                    </div>
                                    <small class="text-muted d-block mt-1">
                                        {{ isCourtesy
                                            ? (t?.manual?.kind_courtesy_help ?? 'Crédito gratuito — sem valor financeiro.')
                                            : (t?.manual?.kind_purchase_help ?? 'A clínica pagou fora do app — informe o valor recebido.') }}
                                    </small>
                                </div>

                                <!-- Quantidade de créditos -->
                                <div class="col-12" :class="{ 'col-md-6': !isCourtesy }">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.manual?.credits ?? 'Quantidade de créditos' }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        v-model.number="form.credits"
                                        type="number"
                                        min="1"
                                        max="1000000"
                                        class="form-control"
                                        required
                                        :disabled="saving">
                                    <small class="text-muted">{{ t?.manual?.credits_help ?? '' }}</small>
                                </div>

                                <!-- Valor recebido (apenas em compra paga) -->
                                <div v-if="!isCourtesy" class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.manual?.amount_reais ?? 'Valor recebido (R$)' }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text">R$</span>
                                        <input
                                            v-model.number="form.amount_reais"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            max="99999.99"
                                            class="form-control"
                                            placeholder="0,00"
                                            :disabled="saving">
                                    </div>
                                    <small class="text-muted">{{ t?.manual?.amount_reais_help ?? 'Quanto a clínica pagou por estes créditos.' }}</small>
                                </div>

                                <!-- Motivo -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.manual?.reason ?? 'Motivo' }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <textarea
                                        v-model="form.reason"
                                        rows="3"
                                        minlength="10"
                                        maxlength="500"
                                        class="form-control"
                                        required
                                        :disabled="saving"></textarea>
                                    <small class="text-muted d-flex justify-content-between">
                                        <span>{{ t?.manual?.reason_help ?? '' }}</span>
                                        <span>{{ form.reason.length }}/500</span>
                                    </small>
                                </div>

                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-link text-muted" :disabled="saving" @click="close">
                                {{ t?.manual?.cancel ?? 'Cancelar' }}
                            </button>
                            <button type="submit" class="btn btn-success" :disabled="!isValid || saving">
                                <i class="ti ti-coin-plus me-1"></i>
                                {{ saving ? '...' : (t?.manual?.submit ?? 'Conceder crédito') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </Teleport>
</template>
