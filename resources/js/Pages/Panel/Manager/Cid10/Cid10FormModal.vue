<script setup>
import { computed, ref, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import ReasonField from '@/Components/Panel/ReasonField.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { usageLocked } from './cid10Presenter.js';

/**
 * Cadastro/edição de um código do catálogo global CID-10.
 *
 * - Novo código = personalizado (fora da tabela oficial): aviso de que guias
 *   TISS podem recusar.
 * - Código oficial: mostra o texto oficial ao lado; mudar a descrição (ou o
 *   código) pede justificativa (vai para a trilha de auditoria).
 * - Código em uso: o campo código fica travado (o servidor confere ao vivo).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    code: { type: Object, default: null }, // null = novo
    categories: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

const { number } = useLocaleFormat();
const uid = useId();
const categoryListId = `cid-categories-${uid}`;

const isEdit = computed(() => !!props.code);
const isOfficial = computed(() => isEdit.value && !props.code.is_custom);
const codeLocked = computed(() => isEdit.value && usageLocked(props.code));

const form = useForm({ code: '', description: '', category: '', reason: '' });
const reasonField = ref(null);

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;
        form.clearErrors();
        const c = props.code ?? {};
        form.code = c.code ?? '';
        form.description = c.description ?? '';
        form.category = c.category ?? '';
        form.reason = '';
    },
);

const codeChanged = computed(() => isEdit.value && form.code.trim().toUpperCase() !== props.code.code);
const descriptionChanged = computed(() => isEdit.value && form.description.trim() !== props.code.description);

// Mudar o texto (ou o código) de um código OFICIAL exige justificativa.
const needsReason = computed(() => isOfficial.value && (descriptionChanged.value || codeChanged.value));
const showCustomWarning = computed(() => !isEdit.value || props.code.is_custom || codeChanged.value);

const canSubmit = computed(
    () =>
        !form.processing &&
        form.code.trim() !== '' &&
        form.description.trim() !== '' &&
        (!needsReason.value || (reasonField.value?.valid ?? false)),
);

function useOfficialText() {
    form.description = props.code.official_description ?? form.description;
}

function submit() {
    if (!canSubmit.value) return;

    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    form.transform((data) => ({
        code: data.code.trim().toUpperCase(),
        description: data.description,
        category: data.category,
        ...(needsReason.value ? { reason: data.reason } : {}),
    }));

    if (isEdit.value) {
        form.put(route('manager.cid10.update', props.code.id), options);
    } else {
        form.post(route('manager.cid10.store'), options);
    }
}
</script>

<template>
    <CenteredModal :open="open" size="lg" :close-label="t.close" @close="emit('close')">
        <template #header>
            <h5 class="mb-0">
                <i class="ti ti-stethoscope me-1 text-primary" aria-hidden="true"></i>
                {{ isEdit ? (t.edit_title ?? '').replace(':code', code.code) : t.new_title }}
            </h5>
        </template>

        <form id="cid10-form" @submit.prevent="submit">
            <div v-if="showCustomWarning" class="alert alert-warning py-2 small" role="note" data-test="custom-warning">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.custom_warning }}
            </div>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold" for="cid-code">
                        {{ t.field_code }} <span class="text-danger">*</span>
                    </label>
                    <input
                        id="cid-code"
                        v-model="form.code"
                        type="text"
                        class="form-control text-uppercase"
                        :class="{ 'is-invalid': form.errors.code }"
                        maxlength="10"
                        autocomplete="off"
                        :disabled="codeLocked"
                        :title="
                            codeLocked
                                ? (t.code_locked ?? '').replace(':count', number(code.usage.total + code.usage.links))
                                : undefined
                        "
                        aria-describedby="cid-code-hint"
                    />
                    <div id="cid-code-hint" class="form-text">
                        {{
                            codeLocked
                                ? (t.code_locked ?? '').replace(':count', number(code.usage.total + code.usage.links))
                                : t.field_code_hint
                        }}
                    </div>
                    <div class="invalid-feedback" data-test="error-code">{{ form.errors.code }}</div>
                </div>
                <div class="col-12 col-md-8">
                    <label class="form-label fw-semibold" for="cid-category">{{ t.field_category }}</label>
                    <input
                        id="cid-category"
                        v-model="form.category"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.category }"
                        maxlength="255"
                        :placeholder="t.field_category_ph"
                        :list="categoryListId"
                    />
                    <datalist :id="categoryListId">
                        <option v-for="c in categories" :key="c" :value="c"></option>
                    </datalist>
                    <div class="invalid-feedback">{{ form.errors.category }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="cid-description">
                        {{ t.field_description }} <span class="text-danger">*</span>
                    </label>
                    <textarea
                        id="cid-description"
                        v-model="form.description"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.description }"
                        rows="2"
                        maxlength="1000"
                    ></textarea>
                    <div class="invalid-feedback" data-test="error-description">{{ form.errors.description }}</div>
                </div>

                <!-- Código oficial: o texto do DATASUS fica sempre visível ao lado. -->
                <div v-if="isOfficial" class="col-12">
                    <div class="border rounded p-2 small bg-body-tertiary" data-test="official-box">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <strong>{{ t.official_text }}</strong>
                            <button
                                v-if="code.official_description && form.description !== code.official_description"
                                type="button"
                                class="btn btn-sm btn-link p-0 ms-auto text-decoration-none"
                                data-test="use-official"
                                @click="useOfficialText"
                            >
                                <i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i>{{ t.restore_official }}
                            </button>
                        </div>
                        <div class="text-muted">{{ code.official_description ?? t.detail_official_none }}</div>
                        <div class="text-muted mt-1">
                            <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.official_edit_note }}
                        </div>
                        <div v-if="codeChanged" class="text-warning-emphasis mt-1">
                            <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.code_change_note }}
                        </div>
                    </div>
                </div>

                <div v-if="needsReason" class="col-12" data-test="reason">
                    <ReasonField
                        ref="reasonField"
                        v-model="form.reason"
                        :label="t.field_reason"
                        :hint="t.reason_hint"
                        :error="form.errors.reason"
                    />
                </div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" @click="emit('close')">{{ t.cancel }}</button>
            <button type="submit" form="cid10-form" class="btn btn-primary" :disabled="!canSubmit" data-test="save">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ t.save }}
            </button>
        </template>
    </CenteredModal>
</template>
