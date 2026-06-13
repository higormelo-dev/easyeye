<script setup>
import { ref, watch, computed } from 'vue';
import SearchSelect from "@/Components/Panel/SearchSelect.vue";

/**
 * Modal de cadastro/edição de Usuário Integrador.
 * Usa fetch direto (não Inertia useForm) porque store/update retornam JSON
 * — consistente com o padrão dos outros FormModals do Manager.
 */
const props = defineProps({
    open:         { type: Boolean, required: true },
    entityId:     { type: String,  required: true },
    itemId:       { type: String,  default: null },
    editDataUrl:  { type: String,  default: '' },
    updateUrl:    { type: String,  default: '' },
    t:            { type: Object,  default: () => ({}) },
});

const emit    = defineEmits(['close', 'saved']);
const isEdit  = computed(() => !!props.itemId);
const title   = computed(() => isEdit.value
    ? (props.t.form_title_edit   ?? 'Editar Usuário Integrador')
    : (props.t.form_title_create ?? 'Novo Usuário Integrador'));

const loading = ref(false);
const saving  = ref(false);
const loadErr = ref('');

const form = ref({
    name:     '',
    email:    '',
    password: '',
    active:   true,
});

const errors = ref({});

function reset() {
    form.value = { name: '', email: '', password: '', active: true };
    errors.value = {};
    loadErr.value = '';
}

async function loadEditData() {
    loading.value = true;
    loadErr.value = '';
    try {
        const res  = await fetch(props.editDataUrl, {
            headers: { Accept: 'application/json' },
        });
        const json = await res.json();
        if (!res.ok) throw new Error(json.message ?? '');
        form.value = {
            name:     json.data.name     ?? '',
            email:    json.data.email    ?? '',
            password: '',
            active:   json.data.active   ?? true,
        };
    } catch {
        loadErr.value = props.t.detail_loading_error ?? 'Erro ao carregar dados.';
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, async (val) => {
    if (!val) return;
    reset();
    if (isEdit.value && props.editDataUrl) {
        await loadEditData();
    }
});

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function submit() {
    saving.value = true;
    errors.value = {};

    const url    = isEdit.value
        ? props.updateUrl
        : route('manager.entities.user-integrators.store', props.entityId);
    const method = isEdit.value ? 'PATCH' : 'POST';

    // PATCH via POST + _method é o pattern Laravel-friendly para multipart
    const payload = isEdit.value ? { ...form.value, _method: 'PATCH' } : form.value;

    try {
        const res = await fetch(url, {
            method: isEdit.value ? 'POST' : 'POST',
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

function hasError(field) {
    return !!(errors.value[field] && errors.value[field].length);
}
function firstError(field) {
    return errors.value[field]?.[0] ?? '';
}
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
                        <i class="ti ti-user-cog me-1 text-info"></i>{{ title }}
                    </h5>
                    <button type="button" class="btn-close" :disabled="saving" @click="close"></button>
                </div>

                <div class="modal-body">
                    <div v-if="loading" class="text-center text-muted py-3">
                        <span class="spinner-border spinner-border-sm me-2"></span>
                        {{ t.detail_loading ?? 'Carregando...' }}
                    </div>

                    <div v-else-if="loadErr" class="alert alert-danger small">
                        {{ loadErr }}
                    </div>

                    <form v-else @submit.prevent="submit" class="row g-3">
                        <div class="col-12">
                            <label class="form-label">
                                {{ t.field_name ?? 'Nome' }} <span class="text-danger">*</span>
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': hasError('name') }"
                                maxlength="255"
                                autocomplete="off"
                                required
                            >
                            <div class="invalid-feedback">{{ firstError('name') }}</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">
                                {{ t.field_email ?? 'E-mail' }} <span class="text-danger">*</span>
                            </label>
                            <input
                                v-model="form.email"
                                type="email"
                                class="form-control"
                                :class="{ 'is-invalid': hasError('email') }"
                                maxlength="255"
                                autocomplete="off"
                                required
                            >
                            <div class="invalid-feedback">{{ firstError('email') }}</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">
                                {{ t.field_password ?? 'Senha' }}
                                <span v-if="!isEdit" class="text-danger">*</span>
                            </label>
                            <input
                                v-model="form.password"
                                type="password"
                                class="form-control"
                                :class="{ 'is-invalid': hasError('password') }"
                                maxlength="255"
                                autocomplete="new-password"
                            >
                            <div class="invalid-feedback">{{ firstError('password') }}</div>
                            <small v-if="isEdit" class="text-muted">
                                {{ t.field_password_hint ?? 'Deixe em branco para não alterar.' }}
                            </small>
                        </div>

                        <div v-if="isEdit" class="col-md-6">
                            <label class="form-label">{{ t.field_active ?? 'Ativo' }}</label>
                            <SearchSelect
                                v-model="form.active"
                                :options="[{ value: true, label: t.field_yes ?? 'Sim' }, { value: false, label: t.field_no ?? 'Não' }]"
                                :value-key="'value'"
                                :label-key="'label'"
                                :clearable="false"
                            />
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
