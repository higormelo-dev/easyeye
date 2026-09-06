<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const showPassword = ref(false);
const showConfirm = ref(false);

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('patient-portal.password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Redefinir senha — Portal do Paciente" />

    <div class="d-flex align-items-center justify-content-center min-vh-100 bg-light px-3">
        <div class="card shadow-sm border-0" style="max-width: 420px; width: 100%;">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div
                        class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary mb-3"
                        style="width:56px;height:56px;"
                    >
                        <i class="ti ti-key fs-4"></i>
                    </div>
                    <h4 class="fw-bold mb-1">Criar nova senha</h4>
                </div>

                <div v-if="form.errors.email" class="alert alert-danger py-2 mb-3">
                    <i class="ti ti-alert-circle me-1"></i>{{ form.errors.email }}
                </div>

                <form @submit.prevent="submit" novalidate>
                    <input type="hidden" :value="form.token" name="token">

                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="ti ti-mail"></i></span>
                            <input
                                v-model="form.email"
                                type="email"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.email }"
                                autofocus
                                autocomplete="username"
                                required
                            >
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nova senha</label>
                        <div class="input-group">
                            <input
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.password }"
                                autocomplete="new-password"
                                required
                            >
                            <button type="button" class="btn btn-outline-secondary" tabindex="-1" @click="showPassword = !showPassword">
                                <i :class="showPassword ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                            </button>
                        </div>
                        <div v-if="form.errors.password" class="invalid-feedback d-block mt-1">{{ form.errors.password }}</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Confirmar nova senha</label>
                        <div class="input-group">
                            <input
                                v-model="form.password_confirmation"
                                :type="showConfirm ? 'text' : 'password'"
                                class="form-control"
                                autocomplete="new-password"
                                required
                            >
                            <button type="button" class="btn btn-outline-secondary" tabindex="-1" @click="showConfirm = !showConfirm">
                                <i :class="showConfirm ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                            </button>
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary fw-semibold" :disabled="form.processing">
                            <i v-if="form.processing" class="ti ti-loader-2 ee-spin me-1"></i>
                            Redefinir senha
                        </button>
                    </div>

                    <p class="text-center mb-0">
                        <a :href="route('patient-portal.login')" class="text-muted small text-decoration-none">Voltar para o login</a>
                    </p>
                </form>
            </div>
        </div>
    </div>
</template>
