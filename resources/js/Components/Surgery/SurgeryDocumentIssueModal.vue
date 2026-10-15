<script setup>
import { computed, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import TinyMceEditor from '@/Components/Panel/TinyMceEditor.vue';

/**
 * Emissão (ou reemissão) de TCLE / orientações pré-operatórias.
 *
 * Fluxo: escolhe o modelo → o servidor monta o texto com os dados do caso
 * (prévia) → o usuário revisa/edita → emite. O texto emitido é CONGELADO.
 * Emitir usa axios (não Inertia) para que um erro de regra (variável
 * desconhecida, caso cancelado) volte para dentro do modal SEM perder o texto
 * editado. Enquanto o POST está no ar TODO controle que entra no payload fica
 * travado (fieldset + editor): o que fosse digitado depois que o POST saiu não
 * estaria no documento e o sucesso fecha o modal.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    // { type, label, templates: [{ id, name, version, category, suggested }] }
    issuable: { type: Object, default: null },
    // documento vigente que está sendo reemitido (texto de partida = o dele)
    reissue: { type: Object, default: null },
    urls: { type: Object, required: true },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close', 'issued']);

const ui = computed(() => props.t.ui ?? {});
const variableLabels = computed(() => props.t.variables ?? {});

const templateId = ref('');
const title = ref('');
const content = ref('');
const eye = ref(null);
const basisHash = ref(null); // retrato do pedido em que a prévia se baseou
const pending = ref([]);
const unknown = ref([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const fieldErrors = ref({});
let ticket = 0;

function tx(text, params = {}) {
    return Object.entries(params).reduce((acc, [name, value]) => acc.replace(`:${name}`, String(value)), text ?? '');
}

function tokens(keys) {
    return keys.map((key) => '{{' + key + '}}').join(', ');
}

function labelList(keys) {
    return keys.map((key) => variableLabels.value[key] ?? key).join(', ');
}

async function loadPreview() {
    if (!props.issuable) return;
    const mine = ++ticket;
    loading.value = true;
    error.value = '';

    try {
        const { data } = await window.axios.post(props.urls.preview, {
            type: props.issuable.type,
            template_id: templateId.value || null,
        });
        if (mine !== ticket) return; // chegou depois de outra escolha de modelo
        title.value = data.title ?? '';
        content.value = data.html ?? '';
        pending.value = data.pending ?? [];
        unknown.value = data.unknown ?? [];
        eye.value = data.eye ?? null;
        basisHash.value = data.basis_hash ?? null;
    } catch (e) {
        if (mine !== ticket) return;
        error.value = e?.response?.data?.message ?? ui.value.preview_failed ?? '';
    } finally {
        if (mine === ticket) loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        error.value = '';
        fieldErrors.value = {};
        pending.value = [];
        unknown.value = [];
        basisHash.value = null;

        if (props.reissue) {
            // Reemissão parte do texto JÁ emitido (pode ter sido editado).
            templateId.value = '';
            title.value = props.reissue.title ?? '';
            content.value = props.reissue.content ?? '';
            return;
        }

        const templates = props.issuable?.templates ?? [];
        templateId.value = (templates.find((tpl) => tpl.suggested) ?? templates[0])?.id ?? '';
        title.value = '';
        content.value = '';
        loadPreview();
    },
);

async function onTemplateChange() {
    if (content.value.trim() !== '' && !(await confirmReplace())) {
        return false;
    }
    await loadPreview();
    return true;
}

async function confirmReplace() {
    // Trocar o modelo descarta o que foi editado: confirma antes.
    const message = ui.value.replace_text ?? '';
    if (!message) return true;
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon: 'warning',
            text: message,
            showCancelButton: true,
            confirmButtonText: ui.value.replace_confirm,
            cancelButtonText: ui.value.cancel,
        });
        return result.isConfirmed;
    }
    return window.confirm(message);
}

async function submit() {
    if (saving.value || loading.value) return;
    saving.value = true;
    error.value = '';
    fieldErrors.value = {};

    try {
        const { data } = await window.axios.post(props.urls.store, {
            type: props.issuable.type,
            template_id: templateId.value || null,
            title: title.value,
            content: content.value,
            // Retrato do pedido da prévia: se o pedido mudou desde então, o servidor recusa (409) e a prévia é refeita.
            basis_hash: basisHash.value || undefined,
        });
        emit('issued', data);
        emit('close');
    } catch (e) {
        const response = e?.response;
        fieldErrors.value = response?.data?.errors ?? {};
        error.value = response?.data?.message ?? ui.value.submit_failed ?? '';
    } finally {
        saving.value = false;
    }
}

const modalTitle = computed(() =>
    props.reissue
        ? tx(ui.value.reissue_title, { type: props.reissue.type_label })
        : tx(ui.value.modal_title, { type: props.issuable?.label ?? '' }),
);

const hasTemplates = computed(() => (props.issuable?.templates ?? []).length > 0);
const canSubmit = computed(
    () => !saving.value && !loading.value && title.value.trim().length >= 2 && content.value.trim().length > 0,
);
</script>

<template>
    <CenteredModal :open="open" size="xl" @close="!saving && emit('close')">
        <template #header>{{ modalTitle }}</template>

        <form class="p-2" novalidate @submit.prevent="submit">
            <fieldset :disabled="saving" class="d-grid gap-3 border-0 p-0 m-0" data-testid="issue-fieldset">
                <div v-if="reissue" class="alert alert-warning py-2 mb-0" role="status">
                    {{ ui.reissue_warning }}
                </div>

                <p v-if="!reissue" class="mb-0" data-testid="issue-eye">
                    <span class="fw-medium">{{ ui.eye_label }}:</span>
                    <span v-if="eye" class="badge text-bg-light border ms-1" :title="eye.label">{{ eye.short }}</span>
                    <span v-else-if="!loading" class="text-danger ms-1" role="alert" data-testid="issue-eye-missing">{{
                        ui.eye_missing
                    }}</span>
                </p>

                <div v-if="!reissue">
                    <label class="form-label fw-medium" for="doc-template">{{ ui.template_label }}</label>
                    <select
                        id="doc-template"
                        v-model="templateId"
                        class="form-select"
                        data-testid="issue-template"
                        @change="onTemplateChange"
                    >
                        <option value="">{{ ui.template_free }}</option>
                        <option v-for="tpl in issuable?.templates ?? []" :key="tpl.id" :value="tpl.id">
                            {{ tpl.name }}<template v-if="tpl.suggested"> — {{ ui.template_suggested }}</template>
                        </option>
                    </select>
                    <p
                        v-if="!hasTemplates"
                        class="form-text text-warning-emphasis mb-0"
                        data-testid="issue-no-templates"
                    >
                        {{ ui.no_templates }}
                    </p>
                </div>

                <div>
                    <label class="form-label fw-medium" for="doc-title">{{ ui.title_label }}</label>
                    <input
                        id="doc-title"
                        v-model="title"
                        type="text"
                        maxlength="200"
                        class="form-control"
                        :class="{ 'is-invalid': fieldErrors.title }"
                        data-testid="issue-title"
                    />
                    <div class="invalid-feedback">{{ (fieldErrors.title ?? [])[0] }}</div>
                </div>

                <div>
                    <label class="form-label fw-medium" for="doc-content">{{ ui.content_label }}</label>
                    <p class="form-text mt-0">{{ ui.edit_hint }}</p>
                    <div v-if="loading" class="text-muted small py-4 text-center" role="status">
                        {{ ui.loading_preview }}
                    </div>
                    <div v-show="!loading" id="doc-content">
                        <TinyMceEditor v-model="content" :height="380" :disabled="saving" />
                    </div>
                    <div v-if="fieldErrors.content" class="text-danger small mt-1">{{ fieldErrors.content[0] }}</div>
                </div>

                <div v-if="pending.length" class="alert alert-info py-2 mb-0" role="status" data-testid="issue-pending">
                    {{ tx(ui.pending_variables, { list: labelList(pending) }) }}
                </div>
                <div
                    v-if="unknown.length"
                    class="alert alert-danger py-2 mb-0"
                    role="alert"
                    data-testid="issue-unknown"
                >
                    {{ tx(ui.unknown_variables, { list: tokens(unknown) }) }}
                </div>
            </fieldset>

            <div v-if="error" class="alert alert-danger py-2 mt-3 mb-0" role="alert" data-testid="issue-error">
                {{ error }}
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="emit('close')">
                    {{ ui.cancel }}
                </button>
                <button type="submit" class="btn btn-primary" :disabled="!canSubmit" data-testid="issue-submit">
                    {{ saving ? ui.submitting : ui.submit }}
                </button>
            </div>
        </form>
    </CenteredModal>
</template>
