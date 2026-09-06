<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

defineProps({
    appName: { type: String, default: 'EasyEye' },
});

const page = usePage();
const statusMessage = computed(() => page.props.flash?.status ?? null);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

function submit() {
    form.post(route('patient-portal.login.store'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Entrar — Portal do Paciente" />

    <div class="d-flex align-items-center justify-content-center min-vh-100 bg-light px-3">
        <div class="card shadow-sm border-0" style="max-width: 420px; width: 100%;">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div
                        class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary mb-3"
                        style="width:56px;height:56px;"
                    >
                        <i class="ti ti-heart-handshake fs-4"></i>
                    </div>
                    <h4 class="fw-bold mb-1">Portal do Paciente</h4>
                    <p class="text-muted small mb-0">Entre com o e-mail cadastrado na clínica</p>
                </div>

                <div v-if="statusMessage" class="alert alert-success py-2 mb-3">
                    <i class="ti ti-circle-check me-1"></i>{{ statusMessage }}
                </div>
                <div v-if="form.errors.email" class="alert alert-danger py-2 mb-3">
                    <i class="ti ti-alert-circle me-1"></i>{{ form.errors.email }}
                </div>

                <form @submit.prevent="submit" novalidate>
                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="ti ti-mail"></i></span>
                            <input
                                v-model="form.email"
                                type="email"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.email }"
                                autocomplete="username"
                                autofocus
                                required
                            >
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Senha</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="ti ti-lock"></i></span>
                            <input
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.password }"
                                autocomplete="current-password"
                                required
                            >
                            <button
                                type="button"
                                class="btn btn-light"
                                tabindex="-1"
                                @click="showPassword = !showPassword"
                            >
                                <i :class="showPassword ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                            </button>
                        </div>
                        <div v-if="form.errors.password" class="invalid-feedback d-block mt-1">
                            {{ form.errors.password }}
                        </div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="form-check mb-0">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                id="remember"
                                class="form-check-input"
                            >
                            <label for="remember" class="form-check-label">Lembrar de mim</label>
                        </div>
                        <a :href="route('patient-portal.password.request')" class="small">Esqueci minha senha</a>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            <i v-if="form.processing" class="ti ti-loader-2 ee-spin me-1"></i>
                            Entrar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<style>
.ee-spin {
    display: inline-block;
    animation: eeSpin 1s linear infinite;
}
@keyframes eeSpin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
</style>
