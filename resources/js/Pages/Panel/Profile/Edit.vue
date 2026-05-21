<script setup>
import { ref, computed } from 'vue';
import { useForm, Link, usePage } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    user:        { type: Object, required: true },
    urls:        { type: Object, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props?.flash?.status);

// ── Form principal: nome + email + foto ──────────────────────────────────────
const profileForm = useForm({
    _method: 'PATCH',
    name:    props.user.name,
    email:   props.user.email,
    photo:   null,
});

const photoPreview = ref(props.user.photo_url);

function handlePhotoChange(e) {
    const file = e.target.files[0];
    if (file) {
        profileForm.photo = file;
        photoPreview.value = URL.createObjectURL(file);
    }
}

function submitProfile() {
    profileForm.post(props.urls.update, {
        forceFormData: true,
        preserveScroll: true,
    });
}

// ── Form de senha ────────────────────────────────────────────────────────────
const passwordForm = useForm({
    _method:               'PUT',
    current_password:      '',
    password:              '',
    password_confirmation: '',
});

const showCurrent = ref(false);
const showNew     = ref(false);
const showConfirm = ref(false);

function submitPassword() {
    passwordForm.post(props.urls.password_update, {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}
</script>

<template>
    <AppLayout title="Meu perfil" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Meu perfil" />

            <!-- Flash de sucesso -->
            <div v-if="flashStatus === 'profile-updated'" class="alert alert-success alert-dismissible fade show mb-3">
                <i class="ti ti-circle-check me-2"></i>Perfil salvo com sucesso.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <div v-if="flashStatus === 'password-updated'" class="alert alert-success alert-dismissible fade show mb-3">
                <i class="ti ti-circle-check me-2"></i>Senha alterada com sucesso.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <div class="card">
                <div class="card-body">
                    <!-- ── Seção 1: Dados pessoais ──────────────────────────────── -->
                    <form @submit.prevent="submitProfile" class="border-bottom mb-4 pb-3">
                        <div class="row align-items-center mb-4">
                            <div class="col-lg-2">
                                <label class="form-label mb-0 fw-medium">Foto</label>
                                <p class="text-muted small mb-0 mt-1">JPG, PNG ou WebP, máx 2MB.</p>
                            </div>
                            <div class="col-lg-10">
                                <div class="d-flex align-items-center gap-3">
                                    <img
                                        :src="photoPreview"
                                        :alt="user.name"
                                        class="rounded-circle border"
                                        style="width: 80px; height: 80px; object-fit: cover;"
                                    >
                                    <label class="btn btn-outline-secondary btn-sm mb-0">
                                        <i class="ti ti-photo me-1"></i>Alterar foto
                                        <input
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            class="d-none"
                                            @change="handlePhotoChange"
                                        >
                                    </label>
                                </div>
                                <div v-if="profileForm.errors.photo" class="text-danger small mt-1">
                                    {{ profileForm.errors.photo }}
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="profile_name" class="form-label">Nome <span class="text-danger">*</span></label>
                                <input
                                    id="profile_name"
                                    v-model="profileForm.name"
                                    type="text"
                                    class="form-control"
                                    :class="{ 'is-invalid': profileForm.errors.name }"
                                    required
                                    autocomplete="name"
                                >
                                <div v-if="profileForm.errors.name" class="invalid-feedback">{{ profileForm.errors.name }}</div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="profile_email" class="form-label">E-mail <span class="text-danger">*</span></label>
                                <input
                                    id="profile_email"
                                    v-model="profileForm.email"
                                    type="email"
                                    class="form-control"
                                    :class="{ 'is-invalid': profileForm.errors.email }"
                                    required
                                    autocomplete="username"
                                >
                                <div v-if="profileForm.errors.email" class="invalid-feedback">{{ profileForm.errors.email }}</div>

                                <div v-if="user.must_verify_email && !user.email_verified" class="mt-2">
                                    <span class="badge bg-warning text-dark me-1">
                                        <i class="ti ti-alert-circle me-1"></i>E-mail não verificado
                                    </span>
                                    <Link :href="urls.send_verification" method="post" as="button" class="btn btn-link btn-sm p-0 align-baseline">
                                        Reenviar verificação
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <Link :href="urls.dashboard" class="btn btn-light">Cancelar</Link>
                            <button type="submit" class="btn btn-primary" :disabled="profileForm.processing">
                                <span v-if="profileForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ti ti-device-floppy me-1"></i>Salvar perfil
                            </button>
                        </div>
                    </form>

                    <!-- ── Seção 2: Senha ───────────────────────────────────────── -->
                    <form @submit.prevent="submitPassword" class="border-bottom mb-4 pb-3">
                        <h5 class="fw-bold mb-3">Alterar senha</h5>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Senha atual</label>
                                <div class="input-group">
                                    <input
                                        v-model="passwordForm.current_password"
                                        :type="showCurrent ? 'text' : 'password'"
                                        class="form-control"
                                        :class="{ 'is-invalid': passwordForm.errors.current_password }"
                                        autocomplete="current-password"
                                    >
                                    <button type="button" class="btn btn-outline-secondary" tabindex="-1" @click="showCurrent = !showCurrent">
                                        <i :class="showCurrent ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                    </button>
                                </div>
                                <div v-if="passwordForm.errors.current_password" class="text-danger small">{{ passwordForm.errors.current_password }}</div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Nova senha</label>
                                <div class="input-group">
                                    <input
                                        v-model="passwordForm.password"
                                        :type="showNew ? 'text' : 'password'"
                                        class="form-control"
                                        :class="{ 'is-invalid': passwordForm.errors.password }"
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="btn btn-outline-secondary" tabindex="-1" @click="showNew = !showNew">
                                        <i :class="showNew ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                    </button>
                                </div>
                                <div v-if="passwordForm.errors.password" class="text-danger small">{{ passwordForm.errors.password }}</div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Confirmar nova senha</label>
                                <div class="input-group">
                                    <input
                                        v-model="passwordForm.password_confirmation"
                                        :type="showConfirm ? 'text' : 'password'"
                                        class="form-control"
                                        autocomplete="new-password"
                                    >
                                    <button type="button" class="btn btn-outline-secondary" tabindex="-1" @click="showConfirm = !showConfirm">
                                        <i :class="showConfirm ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-outline-primary btn-sm" :disabled="passwordForm.processing">
                                <span v-if="passwordForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ti ti-lock me-1"></i>Atualizar senha
                            </button>
                        </div>
                    </form>

                    <!-- ── Seção 3: 2FA ─────────────────────────────────────────── -->
                    <div class="pb-2">
                        <h5 class="fw-bold mb-3">Autenticação em dois fatores</h5>
                        <div class="d-flex align-items-center gap-3">
                            <span v-if="user.has_two_factor_enabled" class="badge badge-soft-success rounded text-success border border-success">
                                <i class="ti ti-shield-check me-1"></i>Ativo
                            </span>
                            <span v-else class="badge badge-soft-secondary rounded">
                                <i class="ti ti-shield-off me-1"></i>Inativo
                            </span>
                            <Link :href="urls.two_factor_setup" class="btn btn-sm btn-outline-primary">
                                <i class="ti ti-settings me-1"></i>{{ user.has_two_factor_enabled ? 'Gerenciar' : 'Configurar' }}
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
