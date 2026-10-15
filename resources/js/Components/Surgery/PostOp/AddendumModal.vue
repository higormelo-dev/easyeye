<script setup>
import { computed, reactive, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { firstError, postOpRequest } from './usePostOpApi.js';

/** Adendo de uma avaliação finalizada: texto + refração corrigida (opcional). */
const props = defineProps({
    open: { type: Boolean, required: true },
    evaluation: { type: Object, default: null },
    section: { type: Object, required: true },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close', 'saved']);

const ui = computed(() => props.t.ui ?? {});
const saving = ref(false);
const error = ref('');
const form = reactive({ text: '', corrected_sphere: '', corrected_cylinder: '', corrected_axis: '' });

watch(
    () => props.open,
    (open) => {
        if (open) {
            Object.assign(form, { text: '', corrected_sphere: '', corrected_cylinder: '', corrected_axis: '' });
            error.value = '';
        }
    },
);

async function submit() {
    if (saving.value || !props.evaluation) return;
    saving.value = true;
    error.value = '';

    const result = await postOpRequest(
        'POST',
        props.section.urls.evaluation_addendum.replace('__ID__', props.evaluation.id),
        { ...form },
    );

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
    <CenteredModal :open="open" size="md" @close="emit('close')">
        <template #header>
            <h5 class="mb-0">{{ ui.addendum_title }}</h5>
        </template>

        <form class="row g-3" novalidate @submit.prevent="submit">
            <div class="col-12">
                <label class="form-label" for="ad-text">{{ ui.addendum_text }}</label>
                <textarea id="ad-text" v-model="form.text" rows="3" class="form-control" :disabled="saving"></textarea>
            </div>
            <div class="col-12">
                <p class="form-text mb-1">{{ ui.addendum_refraction_hint }}</p>
            </div>
            <div class="col-4">
                <label class="form-label" for="ad-sph">{{ ui.f_sphere }}</label>
                <input
                    id="ad-sph"
                    v-model="form.corrected_sphere"
                    type="number"
                    step="0.25"
                    class="form-control"
                    :disabled="saving"
                />
            </div>
            <div class="col-4">
                <label class="form-label" for="ad-cyl">{{ ui.f_cylinder }}</label>
                <input
                    id="ad-cyl"
                    v-model="form.corrected_cylinder"
                    type="number"
                    step="0.25"
                    class="form-control"
                    :disabled="saving"
                />
            </div>
            <div class="col-4">
                <label class="form-label" for="ad-axis">{{ ui.f_axis }}</label>
                <input
                    id="ad-axis"
                    v-model="form.corrected_axis"
                    type="number"
                    min="0"
                    max="180"
                    class="form-control"
                    :disabled="saving"
                />
            </div>
            <div v-if="error" class="col-12">
                <div class="alert alert-danger mb-0" role="alert">{{ error }}</div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary" @click="emit('close')">{{ ui.cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="saving" data-testid="ad-save" @click="submit">
                {{ ui.addendum_save }}
            </button>
        </template>
    </CenteredModal>
</template>
