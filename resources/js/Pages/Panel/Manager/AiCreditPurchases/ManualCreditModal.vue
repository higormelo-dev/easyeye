<script setup>
import { ref, computed, watch } from 'vue';
import SearchSelect from "@/Components/Panel/SearchSelect.vue";

/**
 * Modal para lançar crédito IA manual em uma carteira de empresa.
 *
 * RBAC (vem de props.permissions):
 *   - create_manual            : exibe o botão de submit (todos os perfis SaaS que tiverem suporte)
 *   - create_manual_unlimited  : Admin/Financial — sem limite diário
 *   - create_manual_for_internal: Admin apenas — pode escolher a entidade interna
 */
const props = defineProps({
    open:            { type: Boolean, required: true },
    entities:        { type: Array,   default: () => [] },
    providers:       { type: Array,   default: () => [] },
    permissions:     { type: Object,  default: () => ({}) },
    presetEntityId:  { type: String,  default: null },
    t:               { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'submit']);

const form = ref({
    entity_id:    '',
    kind:         'courtesy',   // 'courtesy' (grátis, R$ 0) | 'purchase' (compra avulsa, paga)
    provider:     '',           // opcional — só metadata analítica
    credits:      100,
    amount_cents: 0,
    reason:       '',
});

const saving = ref(false);
const errorMessage = ref('');

watch(() => props.open, (val) => {
    if (val) {
        form.value = {
            entity_id:    props.presetEntityId ?? '',
            kind:         'courtesy',
            provider:     '',
            credits:      100,
            amount_cents: 0,
            reason:       '',
        };
        errorMessage.value = '';
    }
});

const isCourtesy = computed(() => form.value.kind === 'courtesy');

// Cortesia não tem valor monetário — zera o campo ao alternar.
watch(() => form.value.kind, (kind) => {
    if (kind === 'courtesy') form.value.amount_cents = 0;
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

const isValid = computed(() =>
    form.value.entity_id
    && form.value.credits > 0
    && form.value.reason.trim().length >= 10
    && (isCourtesy.value || form.value.amount_cents > 0),
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
            provider:     form.value.provider,
            credits:      form.value.credits,
            reason:       form.value.reason,
            amount_cents: isCourtesy.value ? 0 : form.value.amount_cents,
            package_code: isCourtesy.value ? 'courtesy' : 'manual',
        });
    } catch (e) {
        errorMessage.value = e?.message || 'Erro ao lançar crédito.';
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
                                {{ t?.manual?.modal_title ?? 'Adicionar crédito manual' }}
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

                                <!-- Empresa destinatária -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.manual?.select_entity ?? 'Empresa destinatária' }}
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

                                <!-- Tipo: Cortesia (grátis) x Compra avulsa (paga) -->
                                <div class="col-12">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.manual?.kind ?? 'Tipo' }} <span class="text-danger">*</span>
                                    </label>
                                    <div class="d-flex gap-2" role="group">
                                        <input id="kind-courtesy" v-model="form.kind" type="radio" class="btn-check" value="courtesy" :disabled="saving">
                                        <label class="btn btn-outline-info flex-fill" for="kind-courtesy">
                                            <i class="ti ti-gift me-1"></i>{{ t?.manual?.kind_courtesy ?? 'Cortesia (grátis)' }}
                                        </label>
                                        <input id="kind-purchase" v-model="form.kind" type="radio" class="btn-check" value="purchase" :disabled="saving">
                                        <label class="btn btn-outline-success flex-fill" for="kind-purchase">
                                            <i class="ti ti-cash me-1"></i>{{ t?.manual?.kind_purchase ?? 'Compra avulsa (paga)' }}
                                        </label>
                                    </div>
                                    <small class="text-muted d-block mt-1">
                                        {{ isCourtesy
                                            ? (t?.manual?.kind_courtesy_help ?? 'Crédito gratuito — não gera valor financeiro.')
                                            : (t?.manual?.kind_purchase_help ?? 'Compra paga fora do app — informe o valor cobrado.') }}
                                    </small>
                                </div>

                                <!-- Provedor (opcional — apenas etiqueta analítica) -->
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.manual?.select_provider ?? 'Provedor (opcional)' }}
                                    </label>
                                    <!-- Linha 1: "Sem preferência" ocupa toda a largura -->
                                    <input
                                        id="prov-none"
                                        v-model="form.provider"
                                        type="radio"
                                        class="btn-check"
                                        value=""
                                        :disabled="saving">
                                    <label
                                        class="btn btn-outline-secondary w-100 text-nowrap mb-2"
                                        for="prov-none"
                                    >
                                        <i class="ti ti-asterisk me-1"></i>{{ t?.manual?.no_provider ?? 'Sem preferência' }}
                                    </label>

                                    <!-- Linha 2: os 3 provedores dividindo a largura -->
                                    <div class="d-flex gap-2" role="group">
                                        <template v-for="p in providers" :key="p.value">
                                            <input
                                                :id="`prov-${p.value}`"
                                                v-model="form.provider"
                                                type="radio"
                                                class="btn-check"
                                                :value="p.value"
                                                :disabled="saving">
                                            <label
                                                class="btn btn-outline-primary flex-fill text-nowrap"
                                                :for="`prov-${p.value}`"
                                                style="min-width: 0; flex-basis: 0;"
                                            >
                                                <i :class="{
                                                    'ti ti-brand-openai': p.value === 'openai',
                                                    'ti ti-message-chatbot': p.value === 'anthropic',
                                                    'ti ti-brand-google': p.value === 'gemini',
                                                }" class="me-1"></i>
                                                {{ p.label }}
                                            </label>
                                        </template>
                                    </div>
                                    <small class="text-muted d-block mt-1">{{ t?.manual?.select_provider_help ?? 'Apenas etiqueta — créditos vão para o saldo único.' }}</small>
                                </div>

                                <!-- Quantidade -->
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.manual?.credits ?? 'Créditos' }}
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
                                </div>

                                <!-- Valor cobrado (apenas em compra avulsa) -->
                                <div v-if="!isCourtesy" class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold mb-1">
                                        {{ t?.manual?.amount_cents ?? 'Valor cobrado (centavos)' }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        v-model.number="form.amount_cents"
                                        type="number"
                                        min="1"
                                        max="9999999"
                                        class="form-control"
                                        :disabled="saving">
                                    <small class="text-muted">{{ t?.manual?.amount_help ?? 'Em centavos (ex.: 24990 = R$ 249,90).' }}</small>
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
                                {{ saving ? '...' : (t?.manual?.submit ?? 'Lançar crédito') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </Teleport>
</template>
