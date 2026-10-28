<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import AttachResult from './AttachResult.vue';
import { validationErrors } from './billingHelpers.js';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/**
 * "Incluir em lote" de uma guia TISS individual/fora de lote: carrega os
 * lotes em rascunho do mesmo convênio (servidor), escolhe um (rádio) e envia.
 * A guia passa pela pré-validação — o resultado aparece aqui, num aviso ao
 * vivo; o Index recarrega a lista (`saved`).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    claim: { type: Object, default: null },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const { tx } = useTrans(() => props.t);
const { date } = useLocaleFormat();

const targets = ref([]);
const chosen = ref('');
const loading = ref(false);
const processing = ref(false);
const errors = ref([]);
const result = ref(null);
const rootRef = ref(null);
const resultRef = ref(null);

const target = computed(() => targets.value.find((item) => item.id === chosen.value) ?? null);
const canConfirm = computed(() => Boolean(target.value) && !loading.value && !processing.value && !result.value);

async function loadTargets() {
    loading.value = true;

    try {
        const { data } = await window.axios.get(props.claim.attach_targets_url);
        targets.value = data?.data ?? [];
        chosen.value = (targets.value.find((item) => item.is_current) ?? targets.value[0])?.id ?? '';
    } catch (error) {
        const messages = Object.values(validationErrors(error) ?? {});
        errors.value = messages.length ? messages : [props.t.add_claims_load_failed];
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open || !props.claim) return;

        targets.value = [];
        chosen.value = '';
        errors.value = [];
        result.value = null;

        if (props.claim.attach_targets_url) loadTargets();
    },
    { immediate: true },
);

function targetLabel(item) {
    return tx('attach_target_label', {
        code: item.code,
        count: item.claims_count,
        period: tx('summary_period_value', { from: date(item.period_start), to: date(item.period_end) }),
    });
}

function requestClose() {
    if (!processing.value) emit('close');
}

async function submit() {
    if (!canConfirm.value) return;

    processing.value = true;
    errors.value = [];

    try {
        const { data } = await window.axios.post(target.value.attach_url, { claim_ids: [props.claim.id] });

        result.value = data;
        emit('saved', data);
        nextTick(() => resultRef.value?.focus?.());
    } catch (error) {
        const messages = Object.values(validationErrors(error) ?? {});
        errors.value = messages.length ? messages : [props.t.add_claims_failed];
    } finally {
        processing.value = false;
    }
}

useDialogKeyboard(() => props.open, { onEscape: requestClose, focusRef: rootRef });
</script>

<template>
    <CenteredModal :open="open" size="md" :close-label="t.btn_close" @close="requestClose">
        <template #header>
            <h2 class="h5 mb-0 fw-semibold">
                <i class="ti ti-package-import me-2 text-primary" aria-hidden="true"></i
                >{{ tx('attach_title', { code: claim?.code ?? '' }) }}
            </h2>
        </template>

        <!-- Foco inicial no conteúdo (os lotes ainda carregam): Tab segue para os controles. -->
        <div
            v-if="claim"
            ref="rootRef"
            tabindex="-1"
            data-autofocus
            class="billing-dialog-body"
            data-test="attach-modal"
        >
            <p class="small text-muted">{{ t.attach_intro }}</p>

            <div v-if="errors.length" class="alert alert-danger small py-2" role="alert" data-test="attach-error">
                <ul class="mb-0 ps-3">
                    <li v-for="message in errors" :key="message">{{ message }}</li>
                </ul>
            </div>

            <template v-if="!result">
                <p v-if="loading" class="d-flex align-items-center gap-2 small text-muted" role="status">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>{{ t.attach_loading }}
                </p>
                <p
                    v-else-if="targets.length === 0 && !errors.length"
                    class="small text-muted text-center py-3 mb-0"
                    data-test="attach-no-targets"
                >
                    {{ t.attach_no_targets }}
                </p>
                <fieldset v-else-if="targets.length" data-test="attach-targets">
                    <legend class="form-label fs-6">{{ t.attach_targets_legend }}</legend>
                    <div v-for="item in targets" :key="item.id" class="form-check mb-2">
                        <input
                            :id="`billing-attach-target-${item.id}`"
                            v-model="chosen"
                            type="radio"
                            name="billing-attach-target"
                            class="form-check-input"
                            :value="item.id"
                            data-test="attach-target"
                        />
                        <label :for="`billing-attach-target-${item.id}`" class="form-check-label small">
                            {{ targetLabel(item) }}
                            <span v-if="item.is_current" class="badge badge-soft-info ms-1">{{
                                t.attach_target_current
                            }}</span>
                        </label>
                    </div>
                </fieldset>
            </template>

            <div role="status" aria-live="polite" class="mt-3">
                <section
                    v-if="result"
                    ref="resultRef"
                    tabindex="-1"
                    aria-labelledby="billing-attach-result-title"
                    data-test="attach-modal-result"
                >
                    <h3 id="billing-attach-result-title" class="h6 fw-semibold">{{ t.attach_result_title }}</h3>
                    <AttachResult :result="result" :t="t" />
                </section>
            </div>
        </div>

        <template #footer>
            <button
                type="button"
                class="btn btn-light"
                :disabled="processing"
                data-test="attach-close"
                @click="requestClose"
            >
                {{ result ? t.btn_close : t.btn_cancel }}
            </button>
            <button
                v-if="!result"
                type="button"
                class="btn btn-primary"
                data-test="attach-confirm"
                :disabled="!canConfirm"
                @click="submit"
            >
                <span v-if="processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ processing ? t.processing : t.attach_confirm }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
/* Alvo de foco programático (não é controle): sem contorno. */
.billing-dialog-body:focus {
    outline: none;
}
</style>
