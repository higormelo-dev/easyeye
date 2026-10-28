<script setup>
import { useId } from 'vue';
import { makeTx } from '@/Support/recallRule.js';

/**
 * ReviewAttestationFields — a identificação do Revisor e a declaração que ele assina ao
 * enviar o parecer: nome, e-mail, CRM/UF, RQE, especialidade, conflito de interesses, se
 * autoriza mostrar a identidade às clínicas e a concordância com o texto da declaração.
 *
 * Os campos começam VAZIOS de propósito: o Revisor declara a própria identidade, e a EasyEye
 * compara com o cadastro. Preencher de antemão com o cadastro tiraria essa prova (quem
 * recebesse o link encaminhado assinaria como o Revisor convidado sem digitar nada).
 */
const props = defineProps({
    modelValue: { type: Object, required: true },
    ufs: { type: Array, default: () => [] },
    statement: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
    idFor: { type: Function, required: true },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const tx = makeTx(() => props.t);
const uid = useId();

const path = (field) => `attestation.${field}`;
const error = (field) => props.errors[path(field)] ?? '';

function update(field, value) {
    emit('update:modelValue', { ...props.modelValue, [field]: value });
}

/** Atributos comuns de um campo de texto (ligação com o rótulo, erro e foco). */
function fieldAttrs(field) {
    return {
        id: props.idFor(path(field)),
        'aria-invalid': error(field) ? 'true' : 'false',
        'aria-describedby': error(field) ? `${props.idFor(path(field))}-error` : undefined,
    };
}
</script>

<template>
    <section class="card shadow-sm mb-4" :aria-labelledby="`${uid}-title`" data-testid="review-attestation">
        <div class="card-header bg-body">
            <h2 :id="`${uid}-title`" class="h5 mb-0">{{ tx('attestation.title') }}</h2>
        </div>

        <fieldset :disabled="disabled" class="card-body border-0" style="min-width: 0">
            <p class="text-body-secondary">{{ tx('attestation.intro') }}</p>

            <div class="row g-3">
                <div class="col-md-6">
                    <label :for="idFor(path('name'))" class="form-label fw-medium">{{ tx('attestation.name') }}</label>
                    <input
                        v-bind="fieldAttrs('name')"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': error('name') }"
                        autocomplete="name"
                        maxlength="160"
                        :value="modelValue.name"
                        data-testid="attestation-name"
                        @input="update('name', $event.target.value)"
                    />
                    <div
                        v-if="error('name')"
                        :id="idFor(path('name')) + '-error'"
                        class="invalid-feedback d-block"
                        role="alert"
                    >
                        {{ error('name') }}
                    </div>
                </div>

                <div class="col-md-6">
                    <label :for="idFor(path('email'))" class="form-label fw-medium">{{
                        tx('attestation.email')
                    }}</label>
                    <input
                        v-bind="fieldAttrs('email')"
                        type="email"
                        class="form-control"
                        :class="{ 'is-invalid': error('email') }"
                        autocomplete="email"
                        maxlength="190"
                        :value="modelValue.email"
                        data-testid="attestation-email"
                        @input="update('email', $event.target.value)"
                    />
                    <div
                        v-if="error('email')"
                        :id="idFor(path('email')) + '-error'"
                        class="invalid-feedback d-block"
                        role="alert"
                    >
                        {{ error('email') }}
                    </div>
                </div>

                <div class="col-sm-6 col-md-3">
                    <label :for="idFor(path('crm_number'))" class="form-label fw-medium">{{
                        tx('attestation.crm_number')
                    }}</label>
                    <input
                        v-bind="fieldAttrs('crm_number')"
                        type="text"
                        inputmode="numeric"
                        class="form-control"
                        :class="{ 'is-invalid': error('crm_number') }"
                        autocomplete="off"
                        maxlength="15"
                        :value="modelValue.crm_number"
                        data-testid="attestation-crm-number"
                        @input="update('crm_number', $event.target.value)"
                    />
                    <div
                        v-if="error('crm_number')"
                        :id="idFor(path('crm_number')) + '-error'"
                        class="invalid-feedback d-block"
                        role="alert"
                    >
                        {{ error('crm_number') }}
                    </div>
                </div>

                <div class="col-sm-6 col-md-3">
                    <label :for="idFor(path('crm_uf'))" class="form-label fw-medium">{{
                        tx('attestation.crm_uf')
                    }}</label>
                    <select
                        v-bind="fieldAttrs('crm_uf')"
                        class="form-select"
                        :class="{ 'is-invalid': error('crm_uf') }"
                        :value="modelValue.crm_uf"
                        data-testid="attestation-crm-uf"
                        @change="update('crm_uf', $event.target.value)"
                    >
                        <option value="">{{ tx('attestation.select') }}</option>
                        <option v-for="uf in ufs" :key="uf" :value="uf" :selected="modelValue.crm_uf === uf">
                            {{ uf }}
                        </option>
                    </select>
                    <div
                        v-if="error('crm_uf')"
                        :id="idFor(path('crm_uf')) + '-error'"
                        class="invalid-feedback d-block"
                        role="alert"
                    >
                        {{ error('crm_uf') }}
                    </div>
                </div>

                <div class="col-sm-6 col-md-3">
                    <label :for="idFor(path('rqe'))" class="form-label fw-medium">{{ tx('attestation.rqe') }}</label>
                    <input
                        v-bind="fieldAttrs('rqe')"
                        type="text"
                        inputmode="numeric"
                        class="form-control"
                        :class="{ 'is-invalid': error('rqe') }"
                        autocomplete="off"
                        maxlength="15"
                        :value="modelValue.rqe"
                        data-testid="attestation-rqe"
                        @input="update('rqe', $event.target.value)"
                    />
                    <div
                        v-if="error('rqe')"
                        :id="idFor(path('rqe')) + '-error'"
                        class="invalid-feedback d-block"
                        role="alert"
                    >
                        {{ error('rqe') }}
                    </div>
                </div>

                <div class="col-sm-6 col-md-3">
                    <label :for="idFor(path('specialty'))" class="form-label fw-medium">{{
                        tx('attestation.specialty')
                    }}</label>
                    <input
                        v-bind="fieldAttrs('specialty')"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': error('specialty') }"
                        maxlength="80"
                        :value="modelValue.specialty"
                        @input="update('specialty', $event.target.value)"
                    />
                    <div
                        v-if="error('specialty')"
                        :id="idFor(path('specialty')) + '-error'"
                        class="invalid-feedback d-block"
                        role="alert"
                    >
                        {{ error('specialty') }}
                    </div>
                </div>
            </div>

            <hr />

            <!-- conflito de interesses -->
            <fieldset class="border-0 p-0 m-0" style="min-width: 0">
                <legend class="h6 fw-semibold">{{ tx('attestation.conflict_title') }}</legend>
                <p class="small text-body-secondary mb-2">{{ tx('attestation.conflict_hint') }}</p>
                <div class="form-check">
                    <input
                        :id="idFor(path('has_conflict'))"
                        class="form-check-input"
                        type="radio"
                        :name="`${uid}-conflict`"
                        :checked="modelValue.has_conflict === false"
                        :aria-invalid="error('has_conflict') ? 'true' : 'false'"
                        data-testid="conflict-no"
                        @change="update('has_conflict', false)"
                    />
                    <label class="form-check-label" :for="idFor(path('has_conflict'))">{{
                        tx('attestation.conflict_no')
                    }}</label>
                </div>
                <div class="form-check">
                    <input
                        :id="`${idFor(path('has_conflict'))}-yes`"
                        class="form-check-input"
                        type="radio"
                        :name="`${uid}-conflict`"
                        :checked="modelValue.has_conflict === true"
                        data-testid="conflict-yes"
                        @change="update('has_conflict', true)"
                    />
                    <label class="form-check-label" :for="`${idFor(path('has_conflict'))}-yes`">{{
                        tx('attestation.conflict_yes')
                    }}</label>
                </div>
                <div v-if="error('has_conflict')" class="invalid-feedback d-block" role="alert">
                    {{ error('has_conflict') }}
                </div>

                <div v-if="modelValue.has_conflict === true" class="mt-2">
                    <label :for="idFor(path('conflict_description'))" class="form-label fw-medium">{{
                        tx('attestation.conflict_description')
                    }}</label>
                    <textarea
                        v-bind="fieldAttrs('conflict_description')"
                        class="form-control"
                        :class="{ 'is-invalid': error('conflict_description') }"
                        rows="3"
                        maxlength="2000"
                        :value="modelValue.conflict_description"
                        data-testid="conflict-description"
                        @input="update('conflict_description', $event.target.value)"
                    ></textarea>
                    <div
                        v-if="error('conflict_description')"
                        :id="idFor(path('conflict_description')) + '-error'"
                        class="invalid-feedback d-block"
                        role="alert"
                    >
                        {{ error('conflict_description') }}
                    </div>
                </div>
            </fieldset>

            <hr />

            <!-- identidade visível às clínicas -->
            <fieldset class="border-0 p-0 m-0" style="min-width: 0">
                <legend class="h6 fw-semibold">{{ tx('attestation.display_title') }}</legend>
                <p class="small text-body-secondary mb-2">{{ tx('attestation.display_hint') }}</p>
                <div class="form-check">
                    <input
                        :id="idFor(path('allow_identity_display'))"
                        class="form-check-input"
                        type="radio"
                        :name="`${uid}-display`"
                        :checked="modelValue.allow_identity_display === true"
                        :aria-invalid="error('allow_identity_display') ? 'true' : 'false'"
                        data-testid="display-yes"
                        @change="update('allow_identity_display', true)"
                    />
                    <label class="form-check-label" :for="idFor(path('allow_identity_display'))">{{
                        tx('attestation.display_yes')
                    }}</label>
                </div>
                <div class="form-check">
                    <input
                        :id="`${idFor(path('allow_identity_display'))}-no`"
                        class="form-check-input"
                        type="radio"
                        :name="`${uid}-display`"
                        :checked="modelValue.allow_identity_display === false"
                        data-testid="display-no"
                        @change="update('allow_identity_display', false)"
                    />
                    <label class="form-check-label" :for="`${idFor(path('allow_identity_display'))}-no`">{{
                        tx('attestation.display_no')
                    }}</label>
                </div>
                <div v-if="error('allow_identity_display')" class="invalid-feedback d-block" role="alert">
                    {{ error('allow_identity_display') }}
                </div>
            </fieldset>

            <hr />

            <!-- a declaração -->
            <h3 :id="`${uid}-statement`" class="h6 fw-semibold">{{ tx('attestation.statement_title') }}</h3>
            <p v-if="statement.is_translation" class="small text-body-secondary" data-testid="statement-translation">
                {{ tx('attestation.statement_translation') }}
            </p>
            <div
                class="border rounded p-3 bg-body-tertiary small text-break overflow-auto"
                style="max-height: 16rem; white-space: pre-line"
                role="region"
                tabindex="0"
                :aria-labelledby="`${uid}-statement`"
                data-testid="statement-text"
            >
                {{ statement.text }}
            </div>
            <p class="small text-body-secondary mt-1 mb-2">
                {{ tx('attestation.statement_code', { code: String(statement.sha256 ?? '').slice(0, 12) }) }}
            </p>
            <details v-if="statement.official_text" class="mb-3" data-testid="statement-official">
                <summary class="small fw-medium">{{ tx('attestation.statement_official') }}</summary>
                <div
                    class="border rounded p-3 mt-2 bg-body-tertiary small text-break overflow-auto"
                    style="max-height: 16rem; white-space: pre-line"
                    role="region"
                    tabindex="0"
                    :aria-label="tx('attestation.statement_official')"
                >
                    {{ statement.official_text }}
                </div>
            </details>
            <div v-else class="mb-3"></div>

            <div class="form-check">
                <input
                    :id="idFor(path('agreed'))"
                    class="form-check-input"
                    type="checkbox"
                    :checked="modelValue.agreed === true"
                    :aria-invalid="error('agreed') ? 'true' : 'false'"
                    :aria-describedby="error('agreed') ? idFor(path('agreed')) + '-error' : undefined"
                    data-testid="attestation-agreed"
                    @change="update('agreed', $event.target.checked)"
                />
                <label class="form-check-label fw-medium" :for="idFor(path('agreed'))">{{
                    tx('attestation.agreed')
                }}</label>
            </div>
            <div
                v-if="error('agreed')"
                :id="idFor(path('agreed')) + '-error'"
                class="invalid-feedback d-block"
                role="alert"
            >
                {{ error('agreed') }}
            </div>
        </fieldset>
    </section>
</template>
