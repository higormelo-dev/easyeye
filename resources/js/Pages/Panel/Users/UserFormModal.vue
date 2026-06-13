<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

const props = defineProps({
    open:     { type: Boolean, required: true },
    userId:   { type: String,  default: null },
    roles:    { type: Object,  default: () => ({}) },
    isClient: { type: Boolean, default: true },
    t:        { type: Object,  default: () => ({}) },
});

const emit    = defineEmits(['close']);
const isEdit  = computed(() => !!props.userId);
const title   = computed(() => isEdit.value ? (props.t.form_title_edit ?? 'Editar Usuário') : (props.t.form_title_create ?? 'Novo Usuário'));
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

function resetForm() {
    form.reset();
    form.clearErrors();
    loadErr.value = '';
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
}

watch(() => props.open, async (val) => {
    if (val) {
        resetForm();
        if (props.userId) await loadEditData(props.userId);
    }
});

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    if (isEdit.value) {
        form.put(route('panel.accesscontrol.users.update', props.userId), opts);
    } else {
        form.post(route('panel.accesscontrol.users.store'), opts);
    }
}
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop -->
        <transition name="fade">
            <div v-if="open" class="ufm-backdrop" @click="$emit('close')" />
        </transition>

        <!-- Offcanvas panel -->
        <transition name="slide-right">
            <div v-if="open" class="ufm-panel" role="dialog" :aria-label="title">

                <!-- Header -->
                <div class="ufm-header">
                    <h5 class="mb-0 fw-semibold">
                        <i class="ti ti-user-shield me-2 text-primary"></i>{{ title }}
                    </h5>
                    <button type="button" class="btn-close" @click="$emit('close')"></button>
                </div>

                <!-- Loading -->
                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>

                <!-- Load error -->
                <div v-else-if="loadErr" class="p-4">
                    <div class="alert alert-danger small py-2 mb-0">
                        <i class="ti ti-alert-circle me-1"></i>{{ loadErr }}
                    </div>
                </div>

                <template v-else>
                    <form class="ufm-body" autocomplete="off" @submit.prevent="submit">

                        <!-- Nome -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                {{ t.field_name }} <span class="text-danger">*</span>
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.name }"
                                autocomplete="off"
                            >
                            <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                        </div>

                        <!-- E-mail -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                {{ t.field_email }} <span class="text-danger">*</span>
                            </label>
                            <input
                                v-model="form.email"
                                type="email"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.email }"
                                autocomplete="off"
                            >
                            <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
                        </div>

                        <!-- Perfil -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                {{ t.field_role }} <span class="text-danger">*</span>
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
                                    v-model="form.active"
                                    class="form-check-input"
                                    type="checkbox"
                                    id="usfActive"
                                >
                                <label class="form-check-label" for="usfActive">{{ t.field_active }}</label>
                            </div>
                        </div>

                        <!-- Password (create only) -->
                        <template v-if="!isEdit">
                            <hr class="my-3">
                            <div class="alert alert-info small py-2">
                                <i class="ti ti-info-circle me-1"></i>{{ t.credentials_info }}
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    {{ t.field_password }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    v-model="form.password"
                                    type="password"
                                    class="form-control"
                                    autocomplete="new-password"
                                    :class="{ 'is-invalid': form.errors.password }"
                                >
                                <div v-if="form.errors.password" class="invalid-feedback">{{ form.errors.password }}</div>
                                <div class="form-text">{{ t.field_password_hint }}</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    {{ t.field_password_confirm }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    v-model="form.password_confirmation"
                                    type="password"
                                    class="form-control"
                                    autocomplete="new-password"
                                    :class="{ 'is-invalid': form.errors.password_confirmation }"
                                >
                                <div v-if="form.errors.password_confirmation" class="invalid-feedback">
                                    {{ form.errors.password_confirmation }}
                                </div>
                            </div>
                        </template>

                        <!-- Footer -->
                        <div class="ufm-footer">
                            <button type="button" class="btn btn-light" @click="$emit('close')">
                                {{ t.btn_cancel }}
                            </button>
                            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                                {{ isEdit ? t.btn_save : t.btn_create }}
                            </button>
                        </div>

                    </form>
                </template>

            </div>
        </transition>
    </Teleport>
</template>

<style scoped>
.ufm-backdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.35);
    z-index: 1054;
}
.ufm-panel {
    position: fixed; top: 0; right: 0;
    width: 460px; max-width: 100vw;
    height: 100dvh;
    background: #fff;
    box-shadow: -4px 0 32px rgba(0,0,0,.18);
    z-index: 1055;
    display: flex; flex-direction: column;
}
:root[data-bs-theme=dark] .ufm-panel { background: #0f1729; }

.ufm-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--bs-border-color);
    flex-shrink: 0;
}
.ufm-body {
    flex: 1; overflow-y: auto;
    padding: 1.25rem;
    display: flex; flex-direction: column;
}
.ufm-footer {
    display: flex; justify-content: flex-end; gap: .75rem;
    padding: 1rem 0 0;
    margin-top: auto;
    border-top: 1px solid var(--bs-border-color);
    position: sticky; bottom: 0;
    background: #fff;
}
:root[data-bs-theme=dark] .ufm-footer { background: #0f1729; }

.fade-enter-active, .fade-leave-active   { transition: opacity .22s ease; }
.fade-enter-from, .fade-leave-to         { opacity: 0; }
.slide-right-enter-active,
.slide-right-leave-active                { transition: transform .28s cubic-bezier(.4,0,.2,1); }
.slide-right-enter-from,
.slide-right-leave-to                    { transform: translateX(100%); }

@media (max-width: 500px) { .ufm-panel { width: 100vw; } }
</style>
