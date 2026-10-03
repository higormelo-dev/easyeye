<script setup>
import { ref, watch, computed } from 'vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

/**
 * Modal de cadastro/edição de Integrador.
 * Usa fetch direto (não Inertia useForm) porque store/update retornam JSON
 * — consistente com o padrão dos outros FormModals do Manager.
 *
 * Observação de segurança: o "token" de API do integrador NÃO é emitido aqui.
 * Ele é gerado por POST /api/integrators (login do equipamento via Sanctum).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    entityId: { type: String, required: true },
    userIntegratorId: { type: String, required: true },
    itemId: { type: String, default: null },
    editDataUrl: { type: String, default: '' },
    updateUrl: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);
const isEdit = computed(() => !!props.itemId);
const title = computed(() =>
    isEdit.value ? (props.t.form_title_edit ?? 'Editar Integrador') : (props.t.form_title_create ?? 'Novo Integrador'),
);

const loading = ref(false);
const saving = ref(false);
const loadErr = ref('');

const activeOptions = computed(() => [
    { value: true, label: props.t.field_yes ?? 'Sim' },
    { value: false, label: props.t.field_no ?? 'Não' },
]);

const form = ref({
    name: '',
    ip: '',
    mac: '',
    active: true,
    token_profile: 'capture',
    update_channel: 'stable',
    update_cohort: 'all',
});

const errors = ref({});

function reset() {
    form.value = {
        name: '',
        ip: '',
        mac: '',
        active: true,
        token_profile: 'capture',
        update_channel: 'stable',
        update_cohort: 'all',
    };
    errors.value = {};
    loadErr.value = '';
}

async function loadEditData() {
    loading.value = true;
    loadErr.value = '';
    try {
        const res = await fetch(props.editDataUrl, { headers: { Accept: 'application/json' } });
        const json = await res.json();
        if (!res.ok) throw new Error(json.message ?? '');
        if (
            !['capture', 'worklist', 'support'].includes(json.data.token_profile) ||
            !['stable', 'pilot'].includes(json.data.update_channel) ||
            typeof json.data.update_cohort !== 'string' ||
            !json.data.update_cohort
        ) {
            throw new Error('Incomplete authorization state');
        }
        form.value = {
            name: json.data.name ?? '',
            ip: json.data.ip ?? '',
            mac: json.data.mac ?? '',
            active: json.data.active ?? true,
            token_profile: json.data.token_profile,
            update_channel: json.data.update_channel,
            update_cohort: json.data.update_cohort,
        };
    } catch {
        loadErr.value = props.t.detail_loading_error ?? 'Erro ao carregar dados.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    async (val) => {
        if (!val) return;
        reset();
        if (isEdit.value && props.editDataUrl) {
            await loadEditData();
        }
    },
);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function submit() {
    if (loading.value || loadErr.value) return;
    saving.value = true;
    errors.value = {};

    const url = isEdit.value
        ? props.updateUrl
        : route('manager.entities.user-integrators.integrators.store', [props.entityId, props.userIntegratorId]);

    const payload = isEdit.value ? { ...form.value, _method: 'PATCH' } : form.value;

    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
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
    if (type === 'error' && window.showErrorToast) return window.showErrorToast(msg);
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
    <div v-if="open" class="modal d-block" tabindex="-1" style="background: rgba(0, 0, 0, 0.45)" @click.self="close">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="ti ti-plug me-1 text-info"></i>{{ title }}</h5>
                    <button type="button" class="btn-close" :disabled="saving" @click="close"></button>
                </div>

                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col">
                            <label>Finalidade do integrador</label
                            ><select v-model="form.token_profile" class="form-select">
                                <option value="capture">Aquisição</option>
                                <option value="worklist">Agenda de equipamentos</option>
                                <option value="support">Suporte operacional</option>
                            </select>
                        </div>
                        <div class="col">
                            <label>Canal de atualização</label
                            ><select v-model="form.update_channel" class="form-select">
                                <option value="stable">Estável</option>
                                <option value="pilot">Piloto</option>
                            </select>
                        </div>
                        <div class="col">
                            <label>Coorte</label
                            ><input v-model="form.update_cohort" class="form-control" maxlength="64" />
                        </div>
                    </div>
                    <div v-if="loading" class="text-center text-muted py-3">
                        <span class="spinner-border spinner-border-sm me-2"></span>
                        {{ t.detail_loading ?? 'Carregando...' }}
                    </div>

                    <div v-else-if="loadErr" class="alert alert-danger small">
                        {{ loadErr }}
                    </div>

                    <form v-else class="row g-3" @submit.prevent="submit">
                        <div class="col-12">
                            <label class="form-label">
                                {{ t.field_name ?? 'Nome' }} <span class="text-danger">*</span>
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': hasError('name') }"
                                maxlength="100"
                                autocomplete="off"
                                style="text-transform: uppercase"
                                required
                            />
                            <div class="invalid-feedback">{{ firstError('name') }}</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                {{ t.field_ip ?? 'IP' }} <span class="text-danger">*</span>
                            </label>
                            <input
                                v-model="form.ip"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': hasError('ip') }"
                                placeholder="192.168.1.10"
                                maxlength="45"
                                autocomplete="off"
                                required
                            />
                            <div class="invalid-feedback">{{ firstError('ip') }}</div>
                            <small class="text-muted">{{ t.field_ip_hint }}</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                {{ t.field_mac ?? 'MAC' }} <span class="text-danger">*</span>
                            </label>
                            <input
                                v-model="form.mac"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': hasError('mac') }"
                                placeholder="00:1B:44:11:3A:B7"
                                maxlength="17"
                                autocomplete="off"
                                style="text-transform: uppercase"
                                required
                            />
                            <div class="invalid-feedback">{{ firstError('mac') }}</div>
                            <small class="text-muted">{{ t.field_mac_hint }}</small>
                        </div>

                        <div v-if="isEdit" class="col-md-6">
                            <label class="form-label">{{ t.field_active ?? 'Ativo' }}</label>
                            <SearchSelect
                                v-model="form.active"
                                :options="activeOptions"
                                :value-key="'value'"
                                :label-key="'label'"
                                :clearable="false"
                            />
                        </div>
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="close">
                        {{ t.btn_cancel ?? 'Cancelar' }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="saving || loading || !!loadErr"
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
