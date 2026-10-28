<script setup>
import { computed, reactive, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { firstError, postOpRequest } from './usePostOpApi.js';

/**
 * Avaliação pós-operatória (rascunho ou edição de rascunho). O servidor decide
 * tudo que importa (olho, gravidade, equivalente esférico); aqui só se coleta e
 * se mostra o equivalente esférico calculado como prévia.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    evaluation: { type: Object, default: null },
    section: { type: Object, required: true },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close', 'saved']);

const ui = computed(() => props.t.ui ?? {});
const options = computed(() => props.section.options ?? {});
const saving = ref(false);
const error = ref('');

/** 'YYYY-MM-DDTHH:mm' no relógio LOCAL (toISOString() devolveria UTC e ficaria horas no futuro). */
function localInputNow() {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());

    return now.toISOString().slice(0, 16);
}

const blank = () => ({
    milestone: 'd1',
    eye: options.value.eyes?.[0]?.value ?? 'od',
    evaluated_at: localInputNow(),
    va_uncorrected_id: '',
    va_corrected_id: '',
    iop_mmhg: '',
    findings: [],
    findings_notes: '',
    refraction_sphere: '',
    refraction_cylinder: '',
    refraction_axis: '',
    complications: '',
    adherence: '',
    plan: '',
});

const form = reactive(blank());

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        error.value = '';
        Object.assign(form, blank());
        const e = props.evaluation;
        if (e) {
            Object.assign(form, {
                milestone: e.milestone.value,
                eye: e.eye.value,
                evaluated_at: String(e.evaluated_at).slice(0, 16),
                va_uncorrected_id: e.va_uncorrected_id ?? '',
                va_corrected_id: e.va_corrected_id ?? '',
                iop_mmhg: e.iop_mmhg ?? '',
                findings: e.findings.map((f) => f.value),
                findings_notes: e.findings_notes ?? '',
                refraction_sphere: e.refraction_sphere ?? '',
                refraction_cylinder: e.refraction_cylinder ?? '',
                refraction_axis: e.refraction_axis ?? '',
                complications: e.complications ?? '',
                adherence: e.adherence?.value ?? '',
                plan: e.plan ?? '',
            });
        }
    },
);

const equivalent = computed(() => {
    const sphere = parseFloat(String(form.refraction_sphere).replace(',', '.'));
    if (Number.isNaN(sphere)) return null;
    const cyl = parseFloat(String(form.refraction_cylinder).replace(',', '.'));
    return Math.round((sphere + (Number.isNaN(cyl) ? 0 : cyl / 2)) * 100) / 100;
});

const severeSelected = computed(() =>
    (options.value.findings ?? []).some((f) => f.severe && form.findings.includes(f.value)),
);

function payload() {
    return {
        ...form,
        evaluated_at: form.evaluated_at ? form.evaluated_at.replace('T', ' ') : null,
        expected_revision: props.evaluation?.revision ?? null,
    };
}

async function submit(finalize) {
    if (saving.value) return;
    saving.value = true;
    error.value = '';

    const urls = props.section.urls;
    const e = props.evaluation;
    let result;

    if (!e) {
        result = await postOpRequest('POST', urls.evaluation_store, payload());
        if (result.ok && finalize) {
            const url = urls.evaluation_finalize.replace('__ID__', result.data.id);
            result = await postOpRequest('POST', url, { ...payload(), expected_revision: result.data.revision });
        }
    } else if (finalize) {
        result = await postOpRequest('POST', urls.evaluation_finalize.replace('__ID__', e.id), payload());
    } else {
        result = await postOpRequest('PUT', urls.evaluation_update.replace('__ID__', e.id), payload());
    }

    saving.value = false;

    if (result.ok) {
        emit('saved');
        emit('close');
        return;
    }

    error.value = firstError(result, ui.value.error_generic);
}
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="emit('close')">
        <template #header>
            <h5 class="mb-0">{{ evaluation ? ui.edit_evaluation : ui.new_evaluation }}</h5>
        </template>

        <form class="row g-3" novalidate @submit.prevent="submit(false)">
            <fieldset :disabled="saving" class="row g-3 m-0 p-0 border-0">
                <div class="col-md-4">
                    <label class="form-label" for="po-milestone">{{ ui.f_milestone }}</label>
                    <select id="po-milestone" v-model="form.milestone" class="form-select">
                        <option v-for="m in options.milestones" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="po-eye">{{ ui.f_eye }}</label>
                    <select id="po-eye" v-model="form.eye" class="form-select">
                        <option v-for="e in options.eyes" :key="e.value" :value="e.value">{{ e.label }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="po-when">{{ ui.f_evaluated_at }}</label>
                    <input id="po-when" v-model="form.evaluated_at" type="datetime-local" class="form-control" />
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="po-vau">{{ ui.f_va_uncorrected }}</label>
                    <select id="po-vau" v-model="form.va_uncorrected_id" class="form-select">
                        <option value="">{{ ui.none }}</option>
                        <option v-for="v in options.visual_acuities" :key="v.id" :value="v.id">{{ v.name }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="po-vac">{{ ui.f_va_corrected }}</label>
                    <select id="po-vac" v-model="form.va_corrected_id" class="form-select">
                        <option value="">{{ ui.none }}</option>
                        <option v-for="v in options.visual_acuities" :key="v.id" :value="v.id">{{ v.name }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="po-iop">{{ ui.f_iop }}</label>
                    <input
                        id="po-iop"
                        v-model="form.iop_mmhg"
                        type="number"
                        step="0.5"
                        min="0"
                        max="80"
                        class="form-control"
                    />
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="po-sph">{{ ui.f_sphere }}</label>
                    <input
                        id="po-sph"
                        v-model="form.refraction_sphere"
                        type="number"
                        step="0.25"
                        class="form-control"
                    />
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="po-cyl">{{ ui.f_cylinder }}</label>
                    <input
                        id="po-cyl"
                        v-model="form.refraction_cylinder"
                        type="number"
                        step="0.25"
                        class="form-control"
                    />
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="po-axis">{{ ui.f_axis }}</label>
                    <input
                        id="po-axis"
                        v-model="form.refraction_axis"
                        type="number"
                        min="0"
                        max="180"
                        class="form-control"
                    />
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <span class="form-text" data-testid="po-se">
                        {{ ui.f_se }}: <strong>{{ equivalent === null ? '—' : equivalent }}</strong>
                    </span>
                </div>

                <div class="col-12">
                    <span class="form-label d-block">{{ ui.f_findings }}</span>
                    <div class="d-flex flex-wrap gap-3">
                        <div v-for="f in options.findings" :key="f.value" class="form-check">
                            <input
                                :id="`po-f-${f.value}`"
                                v-model="form.findings"
                                class="form-check-input"
                                type="checkbox"
                                :value="f.value"
                            />
                            <label
                                class="form-check-label"
                                :class="{ 'text-danger fw-semibold': f.severe }"
                                :for="`po-f-${f.value}`"
                                >{{ f.label }}</label
                            >
                        </div>
                    </div>
                    <div
                        v-if="severeSelected"
                        class="alert alert-danger mt-2 mb-0 py-2"
                        role="alert"
                        data-testid="po-severe-warning"
                    >
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ ui.severe_form_warning }}
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="po-fnotes">{{ ui.f_findings_notes }}</label>
                    <textarea id="po-fnotes" v-model="form.findings_notes" rows="2" class="form-control"></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label" for="po-compl">{{ ui.f_complications }}</label>
                    <textarea id="po-compl" v-model="form.complications" rows="2" class="form-control"></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="po-adh">{{ ui.f_adherence }}</label>
                    <select id="po-adh" v-model="form.adherence" class="form-select">
                        <option value="">{{ ui.none }}</option>
                        <option v-for="a in options.adherence" :key="a.value" :value="a.value">{{ a.label }}</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="po-plan">{{ ui.f_plan }}</label>
                    <textarea id="po-plan" v-model="form.plan" rows="2" class="form-control"></textarea>
                </div>
            </fieldset>

            <div v-if="error" class="col-12">
                <div class="alert alert-danger mb-0" role="alert" data-testid="po-error">{{ error }}</div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary" @click="emit('close')">{{ ui.cancel }}</button>
            <button
                type="button"
                class="btn btn-outline-primary"
                :disabled="saving"
                data-testid="po-save-draft"
                @click="submit(false)"
            >
                {{ ui.save_draft }}
            </button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="saving"
                data-testid="po-save-finalize"
                @click="submit(true)"
            >
                {{ ui.save_finalize }}
            </button>
        </template>
    </CenteredModal>
</template>
