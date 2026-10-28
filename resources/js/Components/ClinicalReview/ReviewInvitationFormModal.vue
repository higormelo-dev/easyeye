<script setup>
import { computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { makeTx } from '@/Support/recallRule.js';

/**
 * Novo convite de revisão (Manager): escolhe o Revisor ativo, as versões congeladas a
 * revisar, o prazo do link e, se quiser, um código de acesso (2ª barreira, passado por OUTRO
 * canal — o código gerado pelo sistema aparece uma vez depois do envio).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    reviewers: { type: Array, default: () => [] },
    versions: { type: Array, default: () => [] },
    expiryDays: { type: Array, default: () => [14] },
    defaultExpiry: { type: Number, default: 14 },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const page = usePage();
const tx = makeTx(() => props.t);

const activeReviewers = computed(() => props.reviewers.filter((reviewer) => reviewer.active));

const form = useForm({
    reviewer_id: '',
    version_ids: [],
    expires_in_days: props.defaultExpiry,
    pin_mode: 'none', // none | generate | custom
    access_code: '',
}).transform((data) => ({
    reviewer_id: data.reviewer_id,
    version_ids: data.version_ids,
    expires_in_days: data.expires_in_days,
    generate_access_code: data.pin_mode === 'generate',
    access_code: data.pin_mode === 'custom' ? data.access_code : null,
}));

watch(
    () => props.open,
    (open) => {
        if (!open) return;

        form.defaults({
            reviewer_id: '',
            version_ids: [],
            expires_in_days: props.defaultExpiry,
            pin_mode: 'none',
            access_code: '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const canSubmit = computed(
    () =>
        !form.processing &&
        form.reviewer_id !== '' &&
        form.version_ids.length > 0 &&
        (form.pin_mode !== 'custom' || form.access_code.trim() !== ''),
);

function submit() {
    if (!canSubmit.value) return;

    form.post(route('manager.clinical-reviews.store'), {
        preserveScroll: true,
        // Recusa de negócio (duplicado, versão que saiu de revisão, código inválido) volta como aviso, não como erro de
        // campo: o modal continua aberto para o Manager corrigir.
        onSuccess: () => {
            if (!page.props.flash?.error) emit('close');
        },
    });
}
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">{{ tx('invitation_form.title') }}</h5>
        </template>

        <form id="invitation-form" novalidate data-testid="invitation-form" @submit.prevent="submit">
            <div class="mb-3">
                <label for="if-reviewer" class="form-label">{{ tx('invitation_form.reviewer') }}</label>
                <select
                    id="if-reviewer"
                    v-model="form.reviewer_id"
                    class="form-select"
                    :class="{ 'is-invalid': form.errors.reviewer_id }"
                    required
                >
                    <option value="">{{ tx('invitation_form.select') }}</option>
                    <option v-for="reviewer in activeReviewers" :key="reviewer.id" :value="reviewer.id">
                        {{ reviewer.name }} — {{ reviewer.crm_label }}
                    </option>
                </select>
                <div v-if="form.errors.reviewer_id" class="invalid-feedback d-block" role="alert">
                    {{ form.errors.reviewer_id }}
                </div>
            </div>

            <fieldset class="mb-3 border-0 p-0" style="min-width: 0">
                <legend class="form-label fs-6">{{ tx('invitation_form.versions') }}</legend>
                <p class="form-text mt-0">{{ tx('invitation_form.versions_hint') }}</p>

                <p v-if="!versions.length" class="text-warning-emphasis" data-testid="no-versions">
                    {{ tx('invitations.no_versions') }}
                </p>
                <div v-for="version in versions" :key="version.id" class="form-check">
                    <input
                        :id="`if-version-${version.id}`"
                        v-model="form.version_ids"
                        class="form-check-input"
                        type="checkbox"
                        :value="version.id"
                        :data-testid="`version-${version.protocol_key}`"
                    />
                    <label class="form-check-label" :for="`if-version-${version.id}`">
                        {{ version.protocol_title }} — v{{ version.version }}
                        <code class="small ms-1">{{ version.short_hash }}</code>
                    </label>
                </div>
                <div v-if="form.errors.version_ids" class="invalid-feedback d-block" role="alert">
                    {{ form.errors.version_ids }}
                </div>
            </fieldset>

            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label for="if-expiry" class="form-label">{{ tx('invitation_form.expires') }}</label>
                    <select
                        id="if-expiry"
                        v-model.number="form.expires_in_days"
                        class="form-select"
                        :class="{ 'is-invalid': form.errors.expires_in_days }"
                    >
                        <option v-for="days in expiryDays" :key="days" :value="days">
                            {{ tx('invitation_form.days', { count: days }) }}
                        </option>
                    </select>
                    <div v-if="form.errors.expires_in_days" class="invalid-feedback d-block" role="alert">
                        {{ form.errors.expires_in_days }}
                    </div>
                </div>
            </div>

            <fieldset class="mb-0 border-0 p-0" style="min-width: 0">
                <legend class="form-label fs-6">{{ tx('invitation_form.pin') }}</legend>
                <p class="form-text mt-0">{{ tx('invitation_form.pin_hint') }}</p>

                <div class="form-check">
                    <input
                        id="if-pin-none"
                        v-model="form.pin_mode"
                        class="form-check-input"
                        type="radio"
                        value="none"
                    />
                    <label class="form-check-label" for="if-pin-none">{{ tx('invitation_form.pin_none') }}</label>
                </div>
                <div class="form-check">
                    <input
                        id="if-pin-generate"
                        v-model="form.pin_mode"
                        class="form-check-input"
                        type="radio"
                        value="generate"
                        data-testid="pin-generate"
                    />
                    <label class="form-check-label" for="if-pin-generate">{{
                        tx('invitation_form.pin_generate')
                    }}</label>
                </div>
                <div class="form-check">
                    <input
                        id="if-pin-custom"
                        v-model="form.pin_mode"
                        class="form-check-input"
                        type="radio"
                        value="custom"
                        data-testid="pin-custom"
                    />
                    <label class="form-check-label" for="if-pin-custom">{{ tx('invitation_form.pin_custom') }}</label>
                </div>

                <div v-if="form.pin_mode === 'custom'" class="mt-2" style="max-width: 18rem">
                    <label for="if-pin-code" class="form-label">{{ tx('invitation_form.pin_custom_label') }}</label>
                    <input
                        id="if-pin-code"
                        v-model="form.access_code"
                        type="text"
                        class="form-control font-monospace text-uppercase"
                        :class="{ 'is-invalid': form.errors.access_code }"
                        maxlength="30"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <div v-if="form.errors.access_code" class="invalid-feedback d-block" role="alert">
                        {{ form.errors.access_code }}
                    </div>
                </div>
            </fieldset>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="form.processing" @click="emit('close')">
                {{ tx('invitation_form.cancel') }}
            </button>
            <button
                type="submit"
                form="invitation-form"
                class="btn btn-primary"
                :disabled="!canSubmit"
                data-testid="invitation-send"
            >
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ tx('invitation_form.send') }}
            </button>
        </template>
    </CenteredModal>
</template>
