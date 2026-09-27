<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/**
 * Importar o XML de retorno (demonstrativo) da operadora — só convênios com
 * operadora TISS vinculada. Upload via useForm com forceFormData.
 */
const props = defineProps({
    open:      { type: Boolean, default: false },
    covenants: { type: Array,   default: () => [] },
    url:       { type: String,  required: true },
    t:         { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const form = useForm({
    covenant_id: '',
    xml_file:    null,
});

const rootRef = ref(null);

const covenantsWithOperator = computed(() => props.covenants.filter((c) => c.has_tiss_operator));
const disabled              = computed(() => covenantsWithOperator.value.length === 0);

watch(() => props.open, (open) => {
    if (!open) return;
    form.reset();
    form.clearErrors();
}, { immediate: true });

function onFileChange(event) {
    form.xml_file = event.target.files?.[0] ?? null;
}

function requestClose() {
    if (!form.processing) emit('close');
}

function submit() {
    if (form.processing || disabled.value) return;

    form.post(props.url, {
        forceFormData:  true,
        preserveScroll: true,
        preserveState:  true,
        onSuccess:      () => emit('saved'),
    });
}

useDialogKeyboard(() => props.open, { onEscape: requestClose, focusRef: rootRef });
</script>

<template>
    <OffcanvasPanel :open="open" :width="480" @close="requestClose">
        <template #header>
            <h5 class="mb-0 fw-semibold"><i class="ti ti-file-upload me-2 text-primary" aria-hidden="true"></i>{{ t.import_return_title }}</h5>
        </template>

        <form ref="rootRef" novalidate @submit.prevent="submit">
            <p class="small text-muted">{{ t.import_return_hint }}</p>

            <div v-if="disabled" class="alert alert-warning small py-2 mb-3">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.import_return_no_covenants }}
            </div>

            <div class="mb-3">
                <label for="billing-import-covenant" class="form-label">
                    {{ t.import_return_covenant }} <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <select
                    id="billing-import-covenant"
                    v-model="form.covenant_id"
                    class="form-select"
                    required
                    aria-required="true"
                    :disabled="disabled"
                    :class="{ 'is-invalid': form.errors.covenant_id }"
                >
                    <option value="">{{ t.select }}</option>
                    <option v-for="c in covenantsWithOperator" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <div v-if="form.errors.covenant_id" class="invalid-feedback">{{ form.errors.covenant_id }}</div>
            </div>

            <div class="mb-0">
                <label for="billing-import-file" class="form-label">
                    {{ t.import_return_file }} <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input
                    id="billing-import-file"
                    type="file"
                    accept=".xml,text/xml,application/xml"
                    class="form-control"
                    required
                    aria-required="true"
                    :disabled="disabled"
                    :class="{ 'is-invalid': form.errors.xml_file }"
                    @change="onFileChange"
                >
                <div v-if="form.errors.xml_file" class="invalid-feedback">{{ form.errors.xml_file }}</div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="form.processing" @click="requestClose">{{ t.btn_cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="form.processing || disabled" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ form.processing ? t.processing : t.import_return_btn }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
