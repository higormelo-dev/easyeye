<script setup>
import { ref, watch, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import SearchSelect   from '@/Components/Panel/SearchSelect.vue';

/**
 * Criar/editar usuário no mesmo painel (OffcanvasPanel) de Pacientes e
 * Médicos — antes um painel próprio, sem rótulo acessível no fechar e sem
 * devolver o foco a quem abriu. Textos vêm de lang/{locale}/access_control.php
 * (prop `t`).
 */
const props = defineProps({
    open:       { type: Boolean, required: true },
    userId:     { type: String,  default: null },
    roles:      { type: Object,  default: () => ({}) },
    isClient:   { type: Boolean, default: true },
    t:          { type: Object,  default: () => ({}) },
    // Própria conta: o backend recusa desativar (EntityUserService::update,
    // 403) — o interruptor fica travado com a explicação.
    lockActive: { type: Boolean, default: false },
});

const emit    = defineEmits(['close']);
const isEdit  = computed(() => !!props.userId);
const title   = computed(() => (isEdit.value
    ? (props.t.form_title_edit ?? 'Editar usuário')
    : (props.t.form_title_create ?? 'Novo usuário')));
const loading = ref(false);
const loadErr = ref('');

const form = useForm({
    name:                  '',
    email:                 '',
    rule:                  '',
    active:                true,
    password:              '',
    password_confirmation: '',
});

const roleOptions = computed(() =>
    Object.entries(props.roles).map(([value, label]) => ({ value, label })),
);

// ── Perfis adicionais (RBAC granular ADITIVO) ───────────────────────────────
// Só faz sentido em edição: um EntityUser precisa existir (e ter seu id)
// antes de poder receber perfis customizados via entity_user_role — não há
// como atribuir perfil "durante" a criação, já que o registro ainda não
// existe. `availableRoles`/`selectedRoleIds` são estado local (não vêm de
// prop) porque só existem depois do fetch de edição — nome deliberadamente
// diferente da prop `roles` (que é o dicionário rule->label do perfil base).
const availableRoles  = ref([]);
const selectedRoleIds = ref([]);

function toggleRole(id) {
    const idx = selectedRoleIds.value.indexOf(id);
    if (idx === -1) {
        selectedRoleIds.value.push(id);
    } else {
        selectedRoleIds.value.splice(idx, 1);
    }
}

function resetForm() {
    form.reset();
    form.clearErrors();
    loadErr.value = '';
    availableRoles.value  = [];
    selectedRoleIds.value = [];
}

async function loadEditData(id) {
    loading.value = true;
    loadErr.value = '';
    try {
        const res  = await fetch(route('panel.accesscontrol.users.show', id), {
            headers: { Accept: 'application/json' },
        });
        const json = await res.json();
        if (!res.ok) throw new Error(json.message ?? '');
        const d    = json.data;
        form.name   = d.name   ?? '';
        form.email  = d.email  ?? '';
        form.rule   = d.rule   ?? '';
        form.active = d.active ?? true;
    } catch {
        loadErr.value = props.t.js_error_load ?? 'Erro ao carregar dados do usuário.';
    } finally {
        loading.value = false;
    }

    // Fetch separado (endpoint `edit()`) só para role_ids/roles — não usa o
    // `data` desta resposta (EntityUserResource, formato aninhado diferente
    // do `show()` acima) e falha silenciosamente: é uma seção secundária do
    // form, não deve bloquear a edição dos dados principais do usuário.
    try {
        const res  = await fetch(route('panel.accesscontrol.users.edit', id), {
            headers: { Accept: 'application/json' },
        });
        const json = await res.json();
        if (res.ok) {
            availableRoles.value  = json.roles ?? [];
            selectedRoleIds.value = json.role_ids ?? [];
        }
    } catch {
        // silencioso — seção "Perfis adicionais" fica vazia, resto do form funciona
    }
}

watch(() => props.open, async (val) => {
    if (val) {
        resetForm();
        if (props.userId) await loadEditData(props.userId);
    }
});

// UX: perfis adicionais são sincronizados numa chamada PATCH dedicada (ver
// UsersController::updateRoles()) disparada DEPOIS que o form principal
// salva com sucesso — não misturamos role_ids no useForm principal porque
// update() usa EntityUserRequest/EntityUserService, compartilhados com o
// fluxo de criação (mesmo racional documentado no backend). Optamos por um
// único botão "Salvar" (em vez de dois botões separados) por ser mais
// simples pro usuário; o encadeamento acontece só em onSuccess do form
// principal, então se os dados base falharem a validação, perfis nem chegam
// a ser sincronizados.
function submit() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (isEdit.value ? syncRoles() : emit('close')),
    };
    if (isEdit.value) {
        form.put(route('panel.accesscontrol.users.update', props.userId), opts);
    } else {
        form.post(route('panel.accesscontrol.users.store'), opts);
    }
}

function syncRoles() {
    router.patch(
        route('panel.accesscontrol.users.roles.update', props.userId),
        { role_ids: selectedRoleIds.value },
        { preserveScroll: true, onFinish: () => emit('close') },
    );
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="520" :loading="loading" :close-label="t.close" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-user-shield me-2 text-primary" aria-hidden="true"></i>{{ title }}
            </h5>
        </template>

        <div v-if="loadErr" class="alert alert-danger small py-2 mb-0" role="alert">
            <i class="ti ti-alert-circle me-1" aria-hidden="true"></i>{{ loadErr }}
        </div>

        <form v-else id="user-form" autocomplete="off" @submit.prevent="submit">

            <!-- Nome -->
            <div class="mb-3">
                <label class="form-label fw-semibold" for="ufm_name">
                    {{ t.field_name ?? 'Nome completo' }}
                    <span class="text-danger" :title="t.required ?? 'obrigatório'" aria-hidden="true">*</span>
                </label>
                <input
                    id="ufm_name"
                    v-model="form.name"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.name }"
                    aria-required="true"
                    :aria-invalid="form.errors.name ? 'true' : undefined"
                    autocomplete="off"
                >
                <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
            </div>

            <!-- E-mail -->
            <div class="mb-3">
                <label class="form-label fw-semibold" for="ufm_email">
                    {{ t.field_email ?? 'E-mail' }}
                    <span class="text-danger" :title="t.required ?? 'obrigatório'" aria-hidden="true">*</span>
                </label>
                <input
                    id="ufm_email"
                    v-model="form.email"
                    type="email"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.email }"
                    aria-required="true"
                    :aria-invalid="form.errors.email ? 'true' : undefined"
                    autocomplete="off"
                >
                <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
            </div>

            <!-- Perfil -->
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    {{ t.field_role ?? 'Perfil de acesso' }}
                    <span class="text-danger" :title="t.required ?? 'obrigatório'" aria-hidden="true">*</span>
                </label>
                <SearchSelect
                    v-model="form.rule"
                    :options="roleOptions"
                    :value-key="'value'"
                    :label-key="'label'"
                    :placeholder="t.field_role_placeholder"
                    :clearable="false"
                    :invalid="!!form.errors.rule"
                />
                <div v-if="form.errors.rule" class="invalid-feedback d-block">{{ form.errors.rule }}</div>
            </div>

            <!-- Status (edit only) -->
            <div v-if="isEdit" class="mb-3">
                <div class="form-check form-switch">
                    <input
                        id="ufm_active"
                        v-model="form.active"
                        class="form-check-input"
                        type="checkbox"
                        :disabled="lockActive"
                        :aria-describedby="lockActive ? 'ufm_active_hint' : undefined"
                    >
                    <label class="form-check-label" for="ufm_active">{{ t.field_active ?? 'Usuário ativo' }}</label>
                </div>
                <div v-if="lockActive" id="ufm_active_hint" class="form-text">{{ t.self_protected }}</div>
            </div>

            <!-- Perfis adicionais (edit only — RBAC granular ADITIVO) -->
            <fieldset v-if="isEdit" class="mb-3">
                <legend class="form-label fw-semibold fs-6 mb-2">{{ t.field_extra_roles ?? 'Perfis adicionais' }}</legend>
                <div v-if="availableRoles.length === 0" class="small text-muted">
                    {{ t.extra_roles_empty ?? 'Nenhum perfil customizado cadastrado nesta clínica.' }}
                </div>
                <div v-else class="border rounded p-2 ufm-roles">
                    <div v-for="r in availableRoles" :key="r.id" class="form-check">
                        <input
                            :id="`ufm_role_${r.id}`"
                            type="checkbox"
                            class="form-check-input"
                            :checked="selectedRoleIds.includes(r.id)"
                            @change="toggleRole(r.id)"
                        >
                        <label class="form-check-label small" :for="`ufm_role_${r.id}`">{{ r.name }}</label>
                    </div>
                </div>
                <div class="form-text">{{ t.extra_roles_hint }}</div>
            </fieldset>

            <!-- Password (create only) -->
            <template v-if="!isEdit">
                <hr class="my-3">
                <div class="alert alert-info small py-2">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.credentials_info }}
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="ufm_password">
                        {{ t.field_password ?? 'Senha' }}
                        <span class="text-danger" :title="t.required ?? 'obrigatório'" aria-hidden="true">*</span>
                    </label>
                    <input
                        id="ufm_password"
                        v-model="form.password"
                        type="password"
                        class="form-control"
                        autocomplete="new-password"
                        aria-required="true"
                        aria-describedby="ufm_password_hint"
                        :class="{ 'is-invalid': form.errors.password }"
                    >
                    <div v-if="form.errors.password" class="invalid-feedback">{{ form.errors.password }}</div>
                    <div id="ufm_password_hint" class="form-text">{{ t.field_password_hint }}</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="ufm_password_confirmation">
                        {{ t.field_password_confirm ?? 'Confirmar senha' }}
                        <span class="text-danger" :title="t.required ?? 'obrigatório'" aria-hidden="true">*</span>
                    </label>
                    <input
                        id="ufm_password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        class="form-control"
                        autocomplete="new-password"
                        aria-required="true"
                        :class="{ 'is-invalid': form.errors.password_confirmation }"
                    >
                    <div v-if="form.errors.password_confirmation" class="invalid-feedback">
                        {{ form.errors.password_confirmation }}
                    </div>
                </div>
            </template>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">
                {{ t.btn_cancel ?? 'Cancelar' }}
            </button>
            <button
                v-if="!loadErr"
                type="submit"
                form="user-form"
                class="btn btn-primary"
                :disabled="form.processing"
            >
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ isEdit ? (t.btn_save ?? 'Salvar alterações') : (t.btn_create ?? 'Criar usuário') }}
            </button>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.ufm-roles {
    max-height: 160px;
    overflow-y: auto;
}
</style>
