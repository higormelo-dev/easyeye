<script setup>
import { computed, ref } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/**
 * Confirmação antes de enviar o lote (I/O externo irreversível no TISS):
 * mostra código, operadora, nº de guias que vão (e as que ficam por
 * pendência), período e total. Lote particular só é "marcado como cobrado".
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    batch: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    error: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'confirm']);

const { tx } = useTrans(() => props.t);
const { money, date } = useLocaleFormat();

const cancelRef = ref(null);

const isParticular = computed(() => Boolean(props.batch?.is_particular));
const title = computed(() =>
    isParticular.value ? props.t.submit_confirm_title_particular : props.t.submit_confirm_title_tiss,
);
const intro = computed(() =>
    isParticular.value ? props.t.submit_confirm_intro_particular : props.t.submit_confirm_intro_tiss,
);
const confirmLabel = computed(() => (isParticular.value ? props.t.action_mark_charged : props.t.action_submit_tiss));

function requestClose() {
    if (!props.processing) emit('close');
}

// Foco inicial em "Cancelar": Enter por engano não dispara o envio.
useDialogKeyboard(() => props.open, { onEscape: requestClose, focusRef: cancelRef });
</script>

<template>
    <CenteredModal :open="open" size="md" @close="requestClose">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i :class="['ti', isParticular ? 'ti-checks' : 'ti-send', 'me-2 text-primary']" aria-hidden="true"></i
                >{{ title }}
            </h5>
        </template>

        <div v-if="batch" data-test="submit-summary">
            <p class="small text-muted">{{ intro }}</p>

            <dl class="row small mb-0">
                <dt class="col-5 text-muted fw-normal">{{ t.summary_batch }}</dt>
                <dd class="col-7 fw-semibold" data-test="summary-code">{{ batch.code }}</dd>

                <dt class="col-5 text-muted fw-normal">{{ isParticular ? t.summary_covenant : t.summary_operator }}</dt>
                <dd class="col-7" data-test="summary-operator">{{ batch.covenant_name || t.no_covenant }}</dd>

                <dt class="col-5 text-muted fw-normal">{{ t.summary_period }}</dt>
                <dd class="col-7">
                    {{ tx('summary_period_value', { from: date(batch.period_start), to: date(batch.period_end) }) }}
                </dd>

                <dt class="col-5 text-muted fw-normal">{{ t.summary_guides }}</dt>
                <dd class="col-7" data-test="summary-guides">
                    {{ tx('summary_guides_value', { count: batch.included_count }) }}
                </dd>

                <dt class="col-5 text-muted fw-normal">{{ t.summary_total }}</dt>
                <dd class="col-7 fw-semibold mb-0" data-test="summary-total">{{ money(batch.total_amount) }}</dd>
            </dl>

            <div
                v-if="batch.pending_count > 0"
                class="alert alert-warning small py-2 mt-3 mb-0"
                data-test="summary-pending"
            >
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                >{{ tx('summary_pending_warning', { count: batch.pending_count }) }}
            </div>

            <div v-if="error" class="alert alert-danger small py-2 mt-3 mb-0" role="alert" data-test="submit-error">
                {{ error }}
            </div>
        </div>

        <template #footer>
            <button ref="cancelRef" type="button" class="btn btn-light" :disabled="processing" @click="requestClose">
                {{ t.btn_cancel }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                data-test="confirm-submit"
                :disabled="processing"
                @click="emit('confirm')"
            >
                <span v-if="processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ processing ? t.processing : confirmLabel }}
            </button>
        </template>
    </CenteredModal>
</template>
