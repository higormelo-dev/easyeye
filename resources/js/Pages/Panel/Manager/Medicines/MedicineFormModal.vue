<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';

/**
 * Cadastro/edição de um item do catálogo global de medicamentos.
 * Item da CMED/Anvisa: dados cadastrais só leitura (vêm da importação) —
 * só a posologia sugerida é editável.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    medicine: { type: Object, default: null }, // null = novo
    presentations: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.medicine);
const isCmed = computed(() => props.medicine?.source === 'cmed');

const form = useForm({
    name: '',
    active_ingredient: '',
    concentration: '',
    medicine_presentation_id: '',
    dosage: '',
    frequency: '',
    duration: '',
    instructions: '',
    is_ophthalmic: false,
    active: true,
});

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;
        form.clearErrors();
        const m = props.medicine ?? {};
        form.name = m.name ?? '';
        form.active_ingredient = m.active_ingredient ?? '';
        form.concentration = m.concentration ?? '';
        form.medicine_presentation_id = m.medicine_presentation_id ?? '';
        form.dosage = m.dosage ?? '';
        form.frequency = m.frequency ?? '';
        form.duration = m.duration ?? '';
        form.instructions = m.instructions ?? '';
        form.is_ophthalmic = m.is_ophthalmic ?? false;
        form.active = m.active ?? true;
    },
);

function submit() {
    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    // transform é persistente no useForm: definido nos DOIS caminhos pra uma
    // edição de item da CMED não vazar pro próximo cadastro.
    if (!isEdit.value) {
        form.transform((data) => ({ ...data, medicine_presentation_id: data.medicine_presentation_id || null })).post(
            route('manager.medicines.store'),
            options,
        );
        return;
    }

    // CMED: só a posologia vai pro servidor (o resto vem da importação).
    form.transform((data) =>
        isCmed.value
            ? {
                  dosage: data.dosage,
                  frequency: data.frequency,
                  duration: data.duration,
                  instructions: data.instructions,
              }
            : { ...data, medicine_presentation_id: data.medicine_presentation_id || null },
    ).put(route('manager.medicines.update', props.medicine.id), options);
}
</script>

<template>
    <CenteredModal :open="open" size="lg" :close-label="t.close" @close="emit('close')">
        <template #header>
            <h5 class="mb-0">
                <i class="ti ti-pill me-1 text-primary"></i>
                {{ isEdit ? t.edit_title : t.new_title }}
            </h5>
        </template>

        <form id="medicine-form" @submit.prevent="submit">
            <div v-if="isCmed" class="alert alert-info py-2 small">
                <i class="ti ti-info-circle me-1"></i>{{ t.cmed_readonly_hint }}
                <div class="mt-1 text-body">
                    <strong>{{ medicine.name }}</strong>
                    <span v-if="medicine.concentration"> {{ medicine.concentration }}</span>
                    <span v-if="medicine.form"> · {{ medicine.form }}</span>
                    <div v-if="medicine.active_ingredient" class="text-muted">{{ medicine.active_ingredient }}</div>
                    <div v-if="medicine.presentation_detail" class="text-muted">
                        {{ medicine.presentation_detail }}
                    </div>
                </div>
            </div>

            <template v-else>
                <div class="row g-2 mb-2">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="med-name">
                            {{ t.field_name }} <span class="text-danger">*</span>
                        </label>
                        <input
                            id="med-name"
                            v-model="form.name"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.name }"
                            maxlength="255"
                        />
                        <div class="invalid-feedback">{{ form.errors.name }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="med-ingredient">{{
                            t.field_active_ingredient
                        }}</label>
                        <input
                            id="med-ingredient"
                            v-model="form.active_ingredient"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.active_ingredient }"
                            maxlength="1000"
                        />
                        <div class="invalid-feedback">{{ form.errors.active_ingredient }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="med-concentration">{{
                            t.field_concentration
                        }}</label>
                        <input
                            id="med-concentration"
                            v-model="form.concentration"
                            type="text"
                            class="form-control"
                            :placeholder="t.field_concentration_ph"
                            maxlength="255"
                        />
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" for="med-presentation">{{ t.field_presentation }}</label>
                        <select
                            id="med-presentation"
                            v-model="form.medicine_presentation_id"
                            class="form-select"
                            :class="{ 'is-invalid': form.errors.medicine_presentation_id }"
                        >
                            <option value="">—</option>
                            <option v-for="p in presentations" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                        <div class="invalid-feedback">{{ form.errors.medicine_presentation_id }}</div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-3 mb-3">
                    <div class="form-check">
                        <input id="med-oft" v-model="form.is_ophthalmic" type="checkbox" class="form-check-input" />
                        <label for="med-oft" class="form-check-label">{{ t.field_is_ophthalmic }}</label>
                    </div>
                    <div class="form-check">
                        <input id="med-active" v-model="form.active" type="checkbox" class="form-check-input" />
                        <label for="med-active" class="form-check-label">{{ t.field_active }}</label>
                    </div>
                </div>
            </template>

            <h6 class="fw-semibold mb-1">{{ t.posology_title }}</h6>
            <p class="text-muted small mb-2">{{ t.posology_hint }}</p>
            <div class="row g-2">
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="med-dosage">{{ t.field_dosage }}</label>
                    <input
                        id="med-dosage"
                        v-model="form.dosage"
                        type="text"
                        class="form-control form-control-sm"
                        :placeholder="t.field_dosage_ph"
                        maxlength="255"
                    />
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="med-frequency">{{ t.field_frequency }}</label>
                    <input
                        id="med-frequency"
                        v-model="form.frequency"
                        type="text"
                        class="form-control form-control-sm"
                        :placeholder="t.field_frequency_ph"
                        maxlength="255"
                    />
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="med-duration">{{ t.field_duration }}</label>
                    <input
                        id="med-duration"
                        v-model="form.duration"
                        type="text"
                        class="form-control form-control-sm"
                        :placeholder="t.field_duration_ph"
                        maxlength="255"
                    />
                </div>
                <div class="col-12">
                    <label class="form-label small" for="med-instructions">{{ t.field_instructions }}</label>
                    <textarea
                        id="med-instructions"
                        v-model="form.instructions"
                        class="form-control form-control-sm"
                        rows="2"
                        maxlength="2000"
                    ></textarea>
                </div>
            </div>
            <div v-if="Object.keys(form.errors).length && !form.errors.name" class="text-danger small mt-2">
                {{ Object.values(form.errors)[0] }}
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" @click="emit('close')">{{ t.cancel }}</button>
            <button type="submit" form="medicine-form" class="btn btn-primary" :disabled="form.processing">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ t.save }}
            </button>
        </template>
    </CenteredModal>
</template>
