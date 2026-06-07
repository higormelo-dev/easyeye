<script setup>
import { ref, watch, computed } from 'vue';
import SearchSelect from "@/Components/Panel/SearchSelect.vue";

/**
 * Form modal GENÉRICO schema-driven para catálogos clínicos.
 *
 * Renderiza qualquer combinação de campos declarada em `fields` do controller:
 *   - text     : input texto
 *   - numeric  : input number (com step opcional)
 *   - color    : input color
 *   - checkbox : switch on/off
 *   - select   : dropdown (com options [{value, label}])
 *
 * Usa fetch direto (não Inertia useForm) pra POST/PATCH, porque os controllers
 * destes catálogos retornam JSON (BaseSettingController::genericStore/Update).
 */
const props = defineProps({
    open:         { type: Boolean, required: true },
    itemId:       { type: String,  default: null },
    fields:       { type: Array,   required: true },
    crudFields:   { type: Object,  required: true },
    storeUrl:     { type: String,  required: true },
    urlTemplates: { type: Object,  required: true },
    t:            { type: Object,  default: () => ({}) },
});

const emit    = defineEmits(['close', 'saved']);
const isEdit  = computed(() => !!props.itemId);
const title   = computed(() => isEdit.value
    ? (props.t.form_title_edit   ?? 'Editar registro')
    : (props.t.form_title_create ?? 'Novo registro'));

const loading = ref(false);
const saving  = ref(false);
const errors  = ref({});

const form    = ref({ ...props.crudFields });

function reset() {
    form.value  = { ...props.crudFields };
    errors.value = {};
}

async function loadEditData() {
    loading.value = true;
    try {
        const url = props.urlTemplates.update.replace('__ID__', props.itemId)
            .replace('/update', '/edit');   // legacy: edit é GET sem sufixo na route resource
        // Como a rota edit do resource Laravel é `panel.setting.X.edit` (GET .../edit),
        // urlTemplates.update aponta para o PATCH (sem /edit). Vamos reconstruir:
        const editUrl = props.urlTemplates.update
            .replace('__ID__', props.itemId);
        // Simpler: usar o show endpoint que existe e tem o mesmo formato.
        const showUrl = props.urlTemplates.show.replace('__ID__', props.itemId);
        const res = await fetch(showUrl, { headers: { Accept: 'application/json' } });
        const json = await res.json();

        // Preserva só os campos do crudFields (defensa contra leitura cruzada).
        const next = { ...props.crudFields };
        for (const key of Object.keys(props.crudFields)) {
            if (json.data && key in json.data) {
                next[key] = json.data[key];
            }
        }
        form.value = next;
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, async (val) => {
    if (!val) return;
    reset();
    if (isEdit.value) await loadEditData();
});

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function submit() {
    saving.value = true;
    errors.value = {};

    const url    = isEdit.value
        ? props.urlTemplates.update.replace('__ID__', props.itemId)
        : props.storeUrl;
    const payload = isEdit.value
        ? { ...form.value, _method: 'PATCH' }
        : form.value;

    try {
        const res = await fetch(url, {
            method:  'POST',
            headers: {
                Accept:         'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(payload),
        });
        const json = await res.json();

        if (res.status === 422) {
            errors.value = json.errors ?? {};
            return;
        }
        if (!res.ok) {
            toast(json.message ?? 'Erro ao salvar.', 'error');
            return;
        }
        toast(json.message ?? '', 'success');
        emit('saved', json.data);
    } catch {
        toast('Falha de rede ao salvar.', 'error');
    } finally {
        saving.value = false;
    }
}

function toast(msg, type) {
    if (!msg) return;
    if (type === 'success' && window.showSuccessToast) return window.showSuccessToast(msg);
    if (type === 'error'   && window.showErrorToast)   return window.showErrorToast(msg);
}

function close() {
    if (saving.value) return;
    emit('close');
}

function hasError(field)   { return !!(errors.value[field] && errors.value[field].length); }
function firstError(field) { return errors.value[field]?.[0] ?? ''; }
</script>

<template>
    <div
        v-if="open"
        class="modal d-block"
        tabindex="-1"
        style="background: rgba(0,0,0,.45);"
        @click.self="close"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="ti ti-database me-1 text-info"></i>{{ title }}
                    </h5>
                    <button type="button" class="btn-close" :disabled="saving" @click="close"></button>
                </div>

                <div class="modal-body">
                    <div v-if="loading" class="text-center text-muted py-3">
                        <span class="spinner-border spinner-border-sm me-2"></span>
                        {{ t.loading ?? 'Carregando...' }}
                    </div>

                    <form v-else @submit.prevent="submit" class="row g-3">
                        <template v-for="field in fields" :key="field.key">
                            <div :class="field.type === 'checkbox' ? 'col-md-6' : 'col-12'">
                                <!-- Label -->
                                <label v-if="field.type !== 'checkbox'" class="form-label">
                                    {{ field.label }}
                                    <span v-if="field.required" class="text-danger">*</span>
                                </label>

                                <!-- text / numeric -->
                                <input
                                    v-if="field.type === 'text' || field.type === 'numeric' || !field.type"
                                    v-model="form[field.key]"
                                    :type="field.type === 'numeric' ? 'number' : 'text'"
                                    class="form-control"
                                    :class="{ 'is-invalid': hasError(field.key) }"
                                    :maxlength="field.maxlength ?? 255"
                                    :step="field.step ?? undefined"
                                    :min="field.min ?? undefined"
                                    :required="field.required ?? false"
                                    autocomplete="off"
                                >

                                <!-- color -->
                                <input
                                    v-else-if="field.type === 'color'"
                                    v-model="form[field.key]"
                                    type="color"
                                    class="form-control form-control-color"
                                    :class="{ 'is-invalid': hasError(field.key) }"
                                    style="width: 100px; height: 40px;"
                                >

                                <!-- select -->
                                <SearchSelect
                                    v-else-if="field.type === 'select'"
                                    v-model="form[field.key]"
                                    :options="field.options ?? []"
                                    :value-key="'value'"
                                    :label-key="'label'"
                                    :placeholder="'—'"
                                    :clearable="!(field.required ?? false)"
                                    :invalid="hasError(field.key)"
                                />

                                <!-- checkbox -->
                                <div v-else-if="field.type === 'checkbox'" class="form-check form-switch mt-2">
                                    <input
                                        :id="`field_${field.key}`"
                                        v-model="form[field.key]"
                                        type="checkbox"
                                        class="form-check-input"
                                        role="switch"
                                    >
                                    <label class="form-check-label" :for="`field_${field.key}`">
                                        {{ field.label }}
                                    </label>
                                </div>

                                <div v-if="hasError(field.key)" class="invalid-feedback d-block">
                                    {{ firstError(field.key) }}
                                </div>
                                <small v-if="field.hint" class="text-muted d-block">{{ field.hint }}</small>
                            </div>
                        </template>

                        <!-- active toggle no edit -->
                        <div v-if="isEdit && 'active' in crudFields" class="col-md-6">
                            <label class="form-label">{{ t.status_active ?? 'Ativo' }}</label>
                            <div class="form-check form-switch">
                                <input
                                    id="field_active"
                                    v-model="form.active"
                                    type="checkbox"
                                    class="form-check-input"
                                    role="switch"
                                >
                                <label class="form-check-label" for="field_active">
                                    {{ form.active ? (t.status_active ?? 'Ativo') : (t.status_inactive ?? 'Inativo') }}
                                </label>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        :disabled="saving"
                        @click="close"
                    >
                        {{ t.btn_cancel ?? 'Cancelar' }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="saving || loading"
                        @click="submit"
                    >
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ti ti-check me-1"></i>
                        {{ isEdit ? (t.btn_save ?? 'Salvar') : (t.btn_create ?? 'Cadastrar') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
