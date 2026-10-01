<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

/**
 * Form de criação/edição de Role customizada (RBAC granular ADITIVO).
 *
 * Diferente do padrão usado em PlanFormModal/CatalogFormModal, NÃO existe
 * endpoint `show`/`edit` no backend (Route::resource('roles', ...)->except([
 * 'create','edit','show']) — ver routes/web.php) porque o próprio index()
 * já devolve a Role completa (permission_ids, permissions, users_count) via
 * RoleResource. Por isso o componente recebe o objeto `role` já pronto do
 * pai (Index.vue), em vez de buscar via fetch ao abrir.
 *
 * Textos vêm de lang/{locale}/access_control_roles.php (prop `t`); rótulos
 * das permissões já chegam traduzidos em `availablePermissions`.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    role: { type: Object, default: null },
    availablePermissions: { type: Array, default: () => [] },
    routes: { type: Object, required: true }, // { store, update } — update com placeholder __ID__
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);
const isEdit = computed(() => !!props.role);
const title = computed(() =>
    isEdit.value ? (props.t.form_title_edit ?? 'Editar perfil') : (props.t.form_title_create ?? 'Novo perfil'),
);

const form = useForm({
    name: '',
    description: '',
    permission_ids: [],
});

// Só exibe grupos que tenham ao menos uma permission com PermissionRecord
// sincronizado (id não nulo) — sem id não há o que marcar (Rule::exists em
// RoleRequest rejeitaria mesmo assim).
const groups = computed(() =>
    props.availablePermissions
        .map((g) => ({ ...g, items: g.items.filter((item) => item.id) }))
        .filter((g) => g.items.length > 0),
);

function resetForm() {
    form.reset();
    form.clearErrors();

    if (props.role) {
        form.name = props.role.name ?? '';
        form.description = props.role.description ?? '';
        form.permission_ids = [...(props.role.permission_ids ?? [])];
    }
}

watch(
    () => props.open,
    (val) => {
        if (val) resetForm();
    },
);

function togglePermission(id) {
    const idx = form.permission_ids.indexOf(id);
    if (idx === -1) {
        form.permission_ids.push(id);
    } else {
        form.permission_ids.splice(idx, 1);
    }
}

function toggleGroup(group, checked) {
    const ids = group.items.map((i) => i.id);
    form.permission_ids = checked
        ? [...new Set([...form.permission_ids, ...ids])]
        : form.permission_ids.filter((id) => !ids.includes(id));
}

function isGroupFullySelected(group) {
    return group.items.every((i) => form.permission_ids.includes(i.id));
}

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    isEdit.value
        ? form.put(props.routes.update.replace('__ID__', props.role.id), opts)
        : form.post(props.routes.store, opts);
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="560" @close="$emit('close')">
        <!-- Header -->
        <template #header>
            <h5 class="mb-0 fw-semibold"><i class="ti ti-shield-lock me-2 text-primary"></i>{{ title }}</h5>
        </template>

        <!-- Body -->
        <form @submit.prevent="submit">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="role_name">
                        {{ t.field_name ?? 'Nome' }}
                        <span class="text-danger" :title="t.required ?? 'obrigatório'" aria-hidden="true">*</span>
                    </label>
                    <input
                        id="role_name"
                        v-model="form.name"
                        type="text"
                        maxlength="255"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.name }"
                        aria-required="true"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                        autocomplete="off"
                    />
                    <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="role_description">{{ t.field_description ?? 'Descrição' }}</label>
                    <textarea
                        id="role_description"
                        v-model="form.description"
                        class="form-control"
                        rows="2"
                        :placeholder="
                            t.field_description_hint ?? 'Opcional — explique quando este perfil deve ser usado'
                        "
                    ></textarea>
                    <div v-if="form.errors.description" class="invalid-feedback d-block">
                        {{ form.errors.description }}
                    </div>
                </div>
            </div>

            <hr class="my-3" />

            <h6
                class="text-muted fw-semibold mb-1"
                style="font-size: 0.75rem; letter-spacing: 0.05em; text-transform: uppercase"
            >
                {{ t.field_permissions ?? 'Permissões' }}
            </h6>
            <div v-if="form.errors.permission_ids" class="alert alert-danger small py-2 mb-2">
                {{ form.errors.permission_ids }}
            </div>

            <div v-if="groups.length === 0" class="text-muted small py-3">
                {{ t.no_permissions ?? 'Nenhuma permissão disponível para atribuir.' }}
            </div>

            <div v-for="group in groups" :key="group.group" class="mb-3">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="fw-semibold small">{{ group.group }}</span>
                    <button
                        type="button"
                        class="btn btn-link btn-sm p-0 fs-12"
                        @click="toggleGroup(group, !isGroupFullySelected(group))"
                    >
                        {{
                            isGroupFullySelected(group)
                                ? (t.unselect_all ?? 'Desmarcar todos')
                                : (t.select_all ?? 'Marcar todos')
                        }}
                    </button>
                </div>
                <div class="border rounded p-2">
                    <div v-for="item in group.items" :key="item.id" class="form-check">
                        <input
                            :id="`perm_${item.id}`"
                            type="checkbox"
                            class="form-check-input"
                            :checked="form.permission_ids.includes(item.id)"
                            @change="togglePermission(item.id)"
                        />
                        <label class="form-check-label small" :for="`perm_${item.id}`">
                            {{ item.label }}
                        </label>
                    </div>
                </div>
            </div>
        </form>

        <!-- Footer -->
        <template #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">
                {{ t.btn_cancel ?? 'Cancelar' }}
            </button>
            <button type="button" class="btn btn-primary" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ isEdit ? (t.btn_save ?? 'Salvar alterações') : (t.btn_create ?? 'Criar perfil') }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
