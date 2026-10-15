<script setup>
import { computed, reactive, ref } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { firstError, postOpRequest } from '@/Components/Surgery/PostOp/usePostOpApi.js';

/**
 * Aba "Alta": o que falta (D1 e descrição finalizados, sem rascunho), a decisão do
 * cirurgião (texto + plano de seguimento) e, depois, o documento de alta emitido.
 */
const props = defineProps({
    section: { type: Object, required: true },
    surgicalCase: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['reload']);

const { dateTime } = useLocaleFormat();
const ui = computed(() => props.t.postop?.ui ?? {});
const form = reactive({ discharge_text: '', follow_up_plan: '' });
const saving = ref(false);
const error = ref('');

const ready = computed(() => props.section.blockers.length === 0);

async function submit() {
    if (saving.value) return;
    if (!window.confirm(ui.value.discharge_confirm)) return;
    saving.value = true;
    error.value = '';

    const result = await postOpRequest('POST', props.section.urls.discharge, {
        ...form,
        expected_revision: props.surgicalCase.revision,
    });

    saving.value = false;

    if (result.ok) {
        emit('reload');
        return;
    }

    error.value = firstError(result, ui.value.error_generic);
}
</script>

<template>
    <div class="discharge-tab">
        <section v-if="section.discharged" class="card" :aria-label="ui.discharge_done_title">
            <div class="card-body">
                <h2 class="h6">
                    <i class="ti ti-circle-check text-success me-1" aria-hidden="true"></i>{{ ui.discharge_done_title }}
                </h2>
                <p class="mb-1 small text-muted">
                    {{ dateTime(section.discharged_at)
                    }}<template v-if="section.document"> — {{ section.document.doctor }}</template>
                </p>
                <template v-if="section.document">
                    <p class="mb-1">{{ section.document.discharge_text }}</p>
                    <p class="mb-2">
                        <strong>{{ ui.discharge_plan_label }}:</strong> {{ section.document.follow_up_plan }}
                    </p>
                    <a
                        class="btn btn-sm btn-outline-secondary"
                        :href="section.document.pdf_url"
                        target="_blank"
                        rel="noopener"
                    >
                        <i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i>{{ ui.pdf }}
                    </a>
                </template>
            </div>
        </section>

        <section v-else class="card" :aria-label="ui.discharge_title">
            <div class="card-body">
                <h2 class="h6">{{ ui.discharge_title }}</h2>

                <div v-if="!ready" class="alert alert-warning" role="alert" data-testid="dc-blockers">
                    <strong class="d-block mb-1">{{ ui.discharge_blockers }}</strong>
                    <ul class="mb-0">
                        <li v-for="b in section.blockers" :key="b">{{ b }}</li>
                    </ul>
                </div>
                <p v-else class="text-success">
                    <i class="ti ti-check me-1" aria-hidden="true"></i>{{ ui.discharge_ready }}
                </p>

                <p v-if="!section.is_surgeon" class="text-muted small">{{ ui.discharge_only_surgeon }}</p>

                <form v-if="section.can_discharge" class="row g-3" novalidate @submit.prevent="submit">
                    <fieldset :disabled="saving || !ready" class="row g-3 m-0 p-0 border-0">
                        <div class="col-12">
                            <label class="form-label" for="dc-text">{{ ui.discharge_text_label }}</label>
                            <textarea
                                id="dc-text"
                                v-model="form.discharge_text"
                                rows="3"
                                class="form-control"
                                maxlength="5000"
                            ></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="dc-plan">{{ ui.discharge_plan_label }}</label>
                            <textarea
                                id="dc-plan"
                                v-model="form.follow_up_plan"
                                rows="2"
                                class="form-control"
                                maxlength="3000"
                            ></textarea>
                        </div>
                    </fieldset>
                    <div v-if="error" class="col-12">
                        <div class="alert alert-danger mb-0" role="alert">{{ error }}</div>
                    </div>
                    <div class="col-12">
                        <button
                            type="submit"
                            class="btn btn-success"
                            :disabled="saving || !ready"
                            data-testid="dc-submit"
                        >
                            <i class="ti ti-door-exit me-1" aria-hidden="true"></i>{{ ui.discharge_button }}
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</template>
