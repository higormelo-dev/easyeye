<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';

/**
 * Concluir (com a resposta dada) ou recusar (com a justificativa) um pedido de
 * titular. O texto fica registrado como prova do atendimento (LGPD arts. 18,
 * 19 e 37). Textos: `t` de lgpd_requests.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    request: { type: Object, default: null },
    action: { type: String, default: 'complete' }, // complete | reject
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

const uid = useId();
const fid = (field) => `lgpd-respond-${uid}-${field}`;

const respondForm = useForm({ action: 'complete', text: '' });
const respondTitle = computed(() =>
    respondForm.action === 'reject' ? props.t.respond?.reject_title : props.t.respond?.complete_title,
);

watch(
    () => [props.open, props.action],
    ([open, action]) => {
        if (!open) return;
        respondForm.reset();
        respondForm.clearErrors();
        respondForm.action = action;
    },
    { immediate: true },
);

// Cancelar, X, Escape e fundo passam pela mesma proteção de alterações (MASTER.md §4).
function close() {
    if (respondForm.text.trim() !== '' && !window.confirm(props.t.form?.discard_confirm ?? '')) return;
    emit('close');
}

const textRef = ref(null);

function submitRespond() {
    respondForm.patch(route('panel.reports.compliance.lgpd-requests.update', props.request.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        // Após envio inválido, foco no campo com o erro (MASTER.md §5).
        onError: () => nextTick(() => textRef.value?.focus()),
    });
}
</script>

<template>
    <CenteredModal :open="open" size="md" density="default" @close="close">
        <template #header>
            <h2 class="h5 mb-0">{{ respondTitle }}</h2>
        </template>

        <form :id="fid('respond')" novalidate @submit.prevent="submitRespond">
            <p v-if="request" class="small text-muted">
                {{ request.requester_name }} — {{ t.types?.[request.type] ?? request.type }}
            </p>
            <label :for="fid('text')" class="form-label">
                {{ respondForm.action === 'reject' ? t.respond?.reject_label : t.respond?.complete_label }}
            </label>
            <textarea
                :id="fid('text')"
                ref="textRef"
                v-model="respondForm.text"
                class="form-control"
                :class="{ 'is-invalid': respondForm.errors.text || respondForm.errors.action }"
                rows="5"
                maxlength="5000"
                required
                aria-required="true"
                :aria-invalid="!!(respondForm.errors.text || respondForm.errors.action)"
                :aria-describedby="
                    respondForm.errors.text || respondForm.errors.action
                        ? `${fid('text-hint')} ${fid('text-error')}`
                        : fid('text-hint')
                "
                data-test="lgpd-respond-text"
            ></textarea>
            <div :id="fid('text-hint')" class="form-text">
                {{ respondForm.action === 'reject' ? t.respond?.reject_hint : t.respond?.complete_hint }}
            </div>
            <div
                v-if="respondForm.errors.text || respondForm.errors.action"
                :id="fid('text-error')"
                class="invalid-feedback d-block"
            >
                {{ respondForm.errors.text || respondForm.errors.action }}
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary" @click="close">
                {{ t.respond?.cancel }}
            </button>
            <button
                type="submit"
                class="btn"
                :class="respondForm.action === 'reject' ? 'btn-danger' : 'btn-success'"
                :form="fid('respond')"
                :disabled="respondForm.processing || !respondForm.text.trim()"
                data-test="lgpd-respond-submit"
            >
                {{ t.respond?.confirm }}
            </button>
        </template>
    </CenteredModal>
</template>
