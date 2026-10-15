<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { makeTx } from '@/Support/recallRule.js';

/**
 * Pausa GLOBAL dos convites de retorno por WhatsApp (Fase 6, ADR 0002 item 9) — o kill-switch do EasyEye: desliga o
 * canal em TODAS as clínicas na hora, com motivo registrado (número queimado, modelo reprovado, denúncia), e o que já
 * estava na fila é cancelado. Mostra quantas clínicas e quantos retornos a ação atinge antes de confirmar.
 */
const props = defineProps({
    // { paused: {reason, by_name, at} | null, impact: {clinics, recalls} }
    recall: { type: Object, default: () => ({ paused: null, impact: { clinics: 0, recalls: 0 } }) },
    routes: { type: Object, required: true },
    t: { type: Object, required: true },
});

const tx = makeTx(() => props.t.recall);
const { number, dateTime } = useLocaleFormat();

const paused = computed(() => props.recall?.paused ?? null);
const impact = computed(() => props.recall?.impact ?? { clinics: 0, recalls: 0 });

const dialog = ref(null); // 'pause' | 'resume' | null
const reason = ref('');
const busy = ref(false);
const errors = ref({});
const failed = ref('');

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function open(kind) {
    dialog.value = kind;
    reason.value = '';
    errors.value = {};
    failed.value = '';
}

function close() {
    if (!busy.value) dialog.value = null;
}

async function confirm() {
    if (busy.value || !dialog.value) return;

    busy.value = true;
    errors.value = {};
    failed.value = '';

    try {
        const pause = dialog.value === 'pause';

        await window.axios.post(
            pause ? props.routes.recall_pause : props.routes.recall_resume,
            pause ? { reason: reason.value.trim() } : {},
            { headers: { 'X-CSRF-TOKEN': csrf() } },
        );

        dialog.value = null;
        router.reload({ only: ['recall'], preserveScroll: true });
    } catch (e) {
        errors.value = e.response?.data?.errors ?? {};
        failed.value = Object.keys(errors.value).length ? '' : (e.response?.data?.message ?? '');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="card mb-3" aria-labelledby="wa-recall-pause" data-test="recall-pause">
        <div class="card-header bg-transparent d-flex flex-wrap align-items-center gap-2">
            <h5 id="wa-recall-pause" class="mb-0 fw-semibold flex-grow-1">
                <i class="ti ti-bell-ringing me-1 text-success" aria-hidden="true"></i>{{ t.recall.pause_title }}
                <span
                    v-if="paused"
                    class="badge badge-soft-danger rounded fs-12 fw-medium ms-1"
                    data-test="recall-paused-badge"
                    >{{ t.recall.badge_paused }}</span
                >
                <span v-else class="badge badge-soft-success rounded fs-12 fw-medium ms-1">{{
                    t.recall.badge_active
                }}</span>
            </h5>
            <button
                v-if="!paused"
                type="button"
                class="btn btn-sm btn-danger"
                data-test="recall-pause-open"
                @click="open('pause')"
            >
                <i class="ti ti-player-pause me-1" aria-hidden="true"></i>{{ t.recall.pause }}
            </button>
            <button
                v-else
                type="button"
                class="btn btn-sm btn-success"
                data-test="recall-resume-open"
                @click="open('resume')"
            >
                <i class="ti ti-player-play me-1" aria-hidden="true"></i>{{ t.recall.resume }}
            </button>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-2">{{ t.recall.pause_text }}</p>

            <div v-if="paused" class="alert alert-danger small mb-2" role="status" data-test="recall-paused-info">
                <div class="fw-semibold">
                    {{
                        tx('paused_since', { name: paused.by_name ?? '—', date: paused.at ? dateTime(paused.at) : '' })
                    }}
                </div>
                <div>{{ tx('paused_reason', { reason: paused.reason }) }}</div>
            </div>

            <p class="small mb-0" data-test="recall-impact">
                {{ tx('impact', { clinics: number(impact.clinics), recalls: number(impact.recalls) }) }}
            </p>
        </div>

        <CenteredModal :open="dialog !== null" size="md" @close="close">
            <template #header>
                <h5 class="mb-0">{{ dialog === 'pause' ? t.recall.pause : t.recall.resume }}</h5>
            </template>

            <form id="wa-recall-form" novalidate @submit.prevent="confirm">
                <p class="mb-2">{{ dialog === 'pause' ? t.recall.confirm_pause : t.recall.confirm_resume }}</p>
                <p class="small fw-semibold" data-test="recall-dialog-impact">
                    {{ tx('impact', { clinics: number(impact.clinics), recalls: number(impact.recalls) }) }}
                </p>

                <template v-if="dialog === 'pause'">
                    <label class="form-label small mb-1" for="wa-recall-reason">{{ t.recall.reason }} *</label>
                    <textarea
                        id="wa-recall-reason"
                        v-model="reason"
                        class="form-control"
                        :class="{ 'is-invalid': errors.reason }"
                        rows="3"
                        maxlength="500"
                        aria-describedby="wa-recall-reason-hint"
                        required
                    ></textarea>
                    <div v-if="errors.reason" class="invalid-feedback d-block" role="alert">{{ errors.reason[0] }}</div>
                    <div id="wa-recall-reason-hint" class="form-text">{{ t.recall.reason_hint }}</div>
                </template>

                <p v-if="failed" class="text-danger small mt-2 mb-0" role="alert">{{ failed }}</p>
            </form>

            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="close">
                    {{ t.ui.cancel }}
                </button>
                <button
                    type="submit"
                    form="wa-recall-form"
                    class="btn"
                    :class="dialog === 'pause' ? 'btn-danger' : 'btn-success'"
                    :disabled="busy || (dialog === 'pause' && reason.trim().length < 10)"
                    data-test="recall-confirm"
                >
                    {{ dialog === 'pause' ? t.recall.pause : t.recall.resume }}
                </button>
            </template>
        </CenteredModal>
    </section>
</template>
