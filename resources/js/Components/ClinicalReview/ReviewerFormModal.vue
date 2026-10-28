<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { makeTx } from '@/Support/recallRule.js';

/**
 * Cadastro/edição de Revisor Clínico (Manager). CRM e RQE são comparados normalizados no
 * servidor ("12.345" = "012345"), que também recusa duplicidade; aqui só se digita.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    /** Revisor em edição; nulo = novo. */
    reviewer: { type: Object, default: null },
    ufs: { type: Array, default: () => [] },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const tx = makeTx(() => props.t);

const blank = () => ({
    name: '',
    email: '',
    crm_number: '',
    crm_uf: '',
    rqe: '',
    specialty: 'Oftalmologia',
    phone: '',
    notes: '',
});

const form = useForm(blank());

watch(
    () => [props.open, props.reviewer?.id],
    () => {
        if (!props.open) return;

        const source = props.reviewer;

        form.defaults(
            source
                ? {
                      name: source.name ?? '',
                      email: source.email ?? '',
                      crm_number: source.crm_number ?? '',
                      crm_uf: source.crm_uf ?? '',
                      rqe: source.rqe ?? '',
                      specialty: source.specialty ?? '',
                      phone: source.phone ?? '',
                      notes: source.notes ?? '',
                  }
                : blank(),
        );
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function submit() {
    if (form.processing) return;

    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    if (props.reviewer) {
        form.put(route('manager.clinical-reviewers.update', props.reviewer.id), options);
    } else {
        form.post(route('manager.clinical-reviewers.store'), options);
    }
}
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                {{ reviewer ? tx('reviewer_form.edit_title') : tx('reviewer_form.new_title') }}
            </h5>
        </template>

        <form id="reviewer-form" novalidate data-testid="reviewer-form" @submit.prevent="submit">
            <div class="row g-3">
                <div class="col-md-7">
                    <label for="rf-name" class="form-label">{{ tx('reviewer_form.name') }}</label>
                    <input
                        id="rf-name"
                        v-model="form.name"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.name }"
                        maxlength="160"
                        required
                    />
                    <div v-if="form.errors.name" class="invalid-feedback d-block" role="alert">
                        {{ form.errors.name }}
                    </div>
                </div>
                <div class="col-md-5">
                    <label for="rf-email" class="form-label">{{ tx('reviewer_form.email') }}</label>
                    <input
                        id="rf-email"
                        v-model="form.email"
                        type="email"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.email }"
                        maxlength="190"
                        required
                    />
                    <div v-if="form.errors.email" class="invalid-feedback d-block" role="alert">
                        {{ form.errors.email }}
                    </div>
                </div>

                <div class="col-sm-6 col-md-3">
                    <label for="rf-crm" class="form-label">{{ tx('reviewer_form.crm_number') }}</label>
                    <input
                        id="rf-crm"
                        v-model="form.crm_number"
                        type="text"
                        inputmode="numeric"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.crm_number }"
                        maxlength="15"
                        required
                    />
                    <div v-if="form.errors.crm_number" class="invalid-feedback d-block" role="alert">
                        {{ form.errors.crm_number }}
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label for="rf-uf" class="form-label">{{ tx('reviewer_form.crm_uf') }}</label>
                    <select
                        id="rf-uf"
                        v-model="form.crm_uf"
                        class="form-select"
                        :class="{ 'is-invalid': form.errors.crm_uf }"
                        required
                    >
                        <option value="">{{ tx('reviewer_form.select') }}</option>
                        <option v-for="uf in ufs" :key="uf" :value="uf">{{ uf }}</option>
                    </select>
                    <div v-if="form.errors.crm_uf" class="invalid-feedback d-block" role="alert">
                        {{ form.errors.crm_uf }}
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label for="rf-rqe" class="form-label">{{ tx('reviewer_form.rqe') }}</label>
                    <input
                        id="rf-rqe"
                        v-model="form.rqe"
                        type="text"
                        inputmode="numeric"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.rqe }"
                        maxlength="15"
                        aria-describedby="rf-rqe-hint"
                        required
                    />
                    <div id="rf-rqe-hint" class="form-text">{{ tx('reviewer_form.rqe_hint') }}</div>
                    <div v-if="form.errors.rqe" class="invalid-feedback d-block" role="alert">
                        {{ form.errors.rqe }}
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label for="rf-specialty" class="form-label">{{ tx('reviewer_form.specialty') }}</label>
                    <input
                        id="rf-specialty"
                        v-model="form.specialty"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.specialty }"
                        maxlength="80"
                    />
                    <div v-if="form.errors.specialty" class="invalid-feedback d-block" role="alert">
                        {{ form.errors.specialty }}
                    </div>
                </div>

                <div class="col-md-6">
                    <label for="rf-phone" class="form-label">{{ tx('reviewer_form.phone') }}</label>
                    <input
                        id="rf-phone"
                        v-model="form.phone"
                        type="tel"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.phone }"
                        maxlength="20"
                    />
                    <div v-if="form.errors.phone" class="invalid-feedback d-block" role="alert">
                        {{ form.errors.phone }}
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="rf-notes" class="form-label">{{ tx('reviewer_form.notes') }}</label>
                    <textarea
                        id="rf-notes"
                        v-model="form.notes"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.notes }"
                        rows="3"
                        maxlength="1000"
                        aria-describedby="rf-notes-hint"
                    ></textarea>
                    <div id="rf-notes-hint" class="form-text">{{ tx('reviewer_form.notes_hint') }}</div>
                    <div v-if="form.errors.notes" class="invalid-feedback d-block" role="alert">
                        {{ form.errors.notes }}
                    </div>
                </div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="form.processing" @click="emit('close')">
                {{ tx('reviewer_form.cancel') }}
            </button>
            <button
                type="submit"
                form="reviewer-form"
                class="btn btn-primary"
                :disabled="form.processing"
                data-testid="reviewer-save"
            >
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ tx('reviewer_form.save') }}
            </button>
        </template>
    </CenteredModal>
</template>
