<script setup>
import { computed, nextTick, ref, watch, watchEffect } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import AttachResult from './AttachResult.vue';
import { validationErrors } from './billingHelpers.js';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/**
 * Lote em rascunho (TISS):
 *  - mode 'add' — "Adicionar guias": carrega do servidor as guias elegíveis
 *    do convênio do lote (individuais ou pendentes), seleção múltipla
 *    (rotulada por linha + "selecionar todas");
 *  - mode 'reprocess' — "Reprocessar pendentes": refaz a pré-validação das
 *    guias do lote que ficaram fora do lote TISS.
 * O resultado por guia (entrou / ficou de fora + pré-validação) aparece aqui
 * mesmo, num aviso ao vivo; o Index recarrega a lista (`saved`).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    batch: { type: Object, default: null },
    mode: { type: String, default: 'add' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const { tx } = useTrans(() => props.t);
const { money, date } = useLocaleFormat();

const claims = ref([]);
const total = ref(0);
const selected = ref([]);
const loading = ref(false);
const processing = ref(false);
const errors = ref([]);
const result = ref(null);
const rootRef = ref(null);
const resultRef = ref(null);
const allRef = ref(null);

const isAdd = computed(() => props.mode !== 'reprocess');
const title = computed(() =>
    tx(isAdd.value ? 'add_claims_title' : 'reprocess_title', { code: props.batch?.code ?? '' }),
);
const allSelected = computed(() => claims.value.length > 0 && selected.value.length === claims.value.length);
const canConfirm = computed(
    () => !processing.value && !loading.value && !result.value && (isAdd.value ? selected.value.length > 0 : true),
);

watchEffect(() => {
    if (allRef.value) allRef.value.indeterminate = selected.value.length > 0 && !allSelected.value;
});

async function loadClaims() {
    loading.value = true;

    try {
        const { data } = await window.axios.get(props.batch.attachable_claims_url);
        claims.value = data?.data ?? [];
        total.value = Number(data?.total ?? claims.value.length);
    } catch (error) {
        errors.value = Object.values(validationErrors(error) ?? {}).slice(0, 1);
        if (!errors.value.length) errors.value = [props.t.add_claims_load_failed];
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open || !props.batch) return;

        claims.value = [];
        total.value = 0;
        selected.value = [];
        errors.value = [];
        result.value = null;

        if (isAdd.value && props.batch.attachable_claims_url) loadClaims();
    },
    { immediate: true },
);

function toggle(id) {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((value) => value !== id)
        : [...selected.value, id];
}

function toggleAll() {
    selected.value = allSelected.value ? [] : claims.value.map((claim) => claim.id);
}

function originLabel(claim) {
    if (claim.origin === 'this') return props.t.add_claims_origin_this;
    if (claim.origin === 'other') return tx('add_claims_origin_other', { code: claim.origin_batch_code ?? '' });

    return props.t.add_claims_origin_individual;
}

function requestClose() {
    if (!processing.value) emit('close');
}

async function submit() {
    if (!canConfirm.value) return;

    processing.value = true;
    errors.value = [];

    try {
        const { data } = isAdd.value
            ? await window.axios.post(props.batch.attach_claims_url, { claim_ids: selected.value })
            : await window.axios.post(props.batch.reprocess_url);

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
    <CenteredModal :open="open" size="lg" :close-label="t.btn_close" @close="requestClose">
        <template #header>
            <h2 class="h5 mb-0 fw-semibold">
                <i :class="['ti me-2 text-primary', isAdd ? 'ti-package-import' : 'ti-refresh']" aria-hidden="true"></i
                >{{ title }}
            </h2>
        </template>

        <!-- Foco inicial no conteúdo (a lista ainda carrega): Tab segue para os controles. -->
        <div
            v-if="batch"
            ref="rootRef"
            tabindex="-1"
            data-autofocus
            class="billing-dialog-body"
            data-test="add-claims-modal"
        >
            <p v-if="isAdd" class="small text-muted">{{ t.add_claims_intro }}</p>
            <p v-else class="small text-muted" data-test="reprocess-intro">
                {{ tx('reprocess_intro', { count: batch.pending_count ?? 0 }) }}
            </p>

            <div v-if="errors.length" class="alert alert-danger small py-2" role="alert" data-test="add-claims-error">
                <ul class="mb-0 ps-3">
                    <li v-for="message in errors" :key="message">{{ message }}</li>
                </ul>
            </div>

            <template v-if="isAdd && !result">
                <p v-if="loading" class="d-flex align-items-center gap-2 small text-muted" role="status">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>{{ t.add_claims_loading }}
                </p>
                <p
                    v-else-if="claims.length === 0 && !errors.length"
                    class="small text-muted text-center py-3 mb-0"
                    data-test="add-claims-empty"
                >
                    {{ t.add_claims_empty }}
                </p>
                <template v-else-if="claims.length">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <div class="form-check mb-0">
                            <input
                                id="billing-add-claims-all"
                                ref="allRef"
                                type="checkbox"
                                class="form-check-input"
                                data-test="add-claims-all"
                                :checked="allSelected"
                                @change="toggleAll"
                            />
                            <label for="billing-add-claims-all" class="form-check-label small">{{
                                t.add_claims_select_all
                            }}</label>
                        </div>
                        <span class="small text-muted" aria-live="polite" data-test="add-claims-count">{{
                            tx('add_claims_selected', { count: selected.length })
                        }}</span>
                    </div>
                    <p v-if="total > claims.length" class="small text-muted" data-test="add-claims-truncated">
                        {{ tx('add_claims_truncated', { shown: claims.length, total }) }}
                    </p>
                    <ul class="list-group billing-add-claims__list" data-test="add-claims-list">
                        <li
                            v-for="claim in claims"
                            :key="claim.id"
                            class="list-group-item d-flex align-items-start gap-2"
                            data-test="add-claims-item"
                        >
                            <input
                                :id="`billing-add-claim-${claim.id}`"
                                type="checkbox"
                                class="form-check-input mt-1 flex-shrink-0"
                                :checked="selected.includes(claim.id)"
                                :aria-label="
                                    tx('add_claims_select_row', {
                                        code: claim.code,
                                        patient: claim.patient_name || '—',
                                    })
                                "
                                @change="toggle(claim.id)"
                            />
                            <label :for="`billing-add-claim-${claim.id}`" class="flex-grow-1 small mb-0">
                                <span class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="fw-semibold">{{ claim.code }}</span>
                                    <span>{{ claim.patient_name || '—' }}</span>
                                    <span class="text-muted">{{ date(claim.attendance_date) }}</span>
                                    <span class="ms-auto fw-semibold text-nowrap">{{ money(claim.amount) }}</span>
                                </span>
                                <span class="d-flex flex-wrap gap-1 mt-1">
                                    <span class="badge badge-soft-secondary">{{ originLabel(claim) }}</span>
                                    <span v-if="claim.has_errors" class="badge badge-soft-warning">
                                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                                        >{{ t.pending_badge }}
                                    </span>
                                </span>
                            </label>
                        </li>
                    </ul>
                </template>
            </template>

            <!-- Resultado ao vivo (leitor de tela anuncia; o foco vai para cá ao gravar). -->
            <div role="status" aria-live="polite" class="mt-3">
                <section
                    v-if="result"
                    ref="resultRef"
                    tabindex="-1"
                    aria-labelledby="billing-add-claims-result-title"
                    data-test="add-claims-result"
                >
                    <h3 id="billing-add-claims-result-title" class="h6 fw-semibold">{{ t.attach_result_title }}</h3>
                    <AttachResult :result="result" :t="t" />
                </section>
            </div>
        </div>

        <template #footer>
            <button
                type="button"
                class="btn btn-light"
                :disabled="processing"
                data-test="add-claims-close"
                @click="requestClose"
            >
                {{ result ? t.btn_close : t.btn_cancel }}
            </button>
            <button
                v-if="!result"
                type="button"
                class="btn btn-primary"
                data-test="add-claims-confirm"
                :disabled="!canConfirm"
                @click="submit"
            >
                <span v-if="processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{
                    processing
                        ? t.processing
                        : isAdd
                          ? tx('add_claims_confirm', { count: selected.length })
                          : t.reprocess_confirm
                }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.billing-add-claims__list {
    max-height: 22rem;
    overflow-y: auto;
}

/* Alvo de foco programático (não é controle): sem contorno. */
.billing-dialog-body:focus {
    outline: none;
}
</style>
