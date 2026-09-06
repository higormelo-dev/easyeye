<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({
    appName: { type: String, default: 'EasyEye' },
});

const page = usePage();
const statusMessage = computed(() => page.props.flash?.status ?? null);

const form = useForm({ email: '' });

function submit() {
    form.post(route('patient-portal.password.email'));
}
</script>

<template>
    <Head title="Esqueci minha senha — Portal do Paciente" />

    <div class="d-flex align-items-center justify-content-center min-vh-100 bg-light px-3">
        <div class="card shadow-sm border-0" style="max-width: 420px; width: 100%;">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div
                        class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary mb-3"
                        style="width:56px;height:56px;"
                    >
                        <i class="ti ti-lock-open fs-4"></i>
                    </div>
                    <h4 class="fw-bold mb-1">Esqueci minha senha</h4>
                    <p class="text-muted small mb-0">Enviaremos um link de redefinição para o seu e-mail</p>
                </div>

                <div v-if="statusMessage" class="alert alert-success py-2 mb-3">
                    <i class="ti ti-circle-check me-1"></i>{{ statusMessage }}
                </div>
                <div v-if="form.errors.email" class="alert alert-danger py-2 mb-3">
                    <i class="ti ti-alert-circle me-1"></i>{{ form.errors.email }}
                </div>

                <form @submit.prevent="submit" novalidate>
                    <div class="mb-4">
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

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            <i v-if="form.processing" class="ti ti-loader-2 ee-spin me-1"></i>
                            <i v-else class="ti ti-send me-1"></i>
                            Enviar link
                        </button>
                    </div>

                    <div class="text-center">
                        <a :href="route('patient-portal.login')" class="text-muted small text-decoration-none">
                            <i class="ti ti-arrow-left me-1"></i>Voltar para o login
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
