<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    personId: { type: String, required: true },
    name: { type: String, default: '' },
    email: { type: String, default: '' },
});

const showPassword = ref(false);
const showConfirm = ref(false);

const form = useForm({
    password: '',
    password_confirmation: '',
});

function submit() {
    // Reenvia a MESMA querystring do link de convite (person_id, expires,
    // signature) — o middleware `signed` valida a URL exata usada para gerar
    // o link, então o POST precisa carregar os mesmos parâmetros do GET.
    form.post(window.location.pathname + window.location.search, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Criar senha — Portal do Paciente" />

    <div class="d-flex align-items-center justify-content-center min-vh-100 bg-light px-3">
        <div class="card shadow-sm border-0" style="max-width: 440px; width: 100%;">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div
                        class="rounded-circle d-inline-flex align-items-center justify-content-center bg-success-subtle text-success mb-3"
                        style="width:56px;height:56px;"
                    >
                        <i class="ti ti-shield-check fs-4"></i>
                    </div>
                    <h4 class="fw-bold mb-1">Bem-vindo(a){{ name ? `, ${name}` : '' }}!</h4>
                    <p class="text-muted small mb-0">Crie sua senha para acessar o Portal do Paciente</p>
                    <p v-if="email" class="text-muted small mb-0">{{ email }}</p>
                </div>

                <div v-if="form.errors.password" class="alert alert-danger py-2 mb-3">
                    <i class="ti ti-alert-circle me-1"></i>{{ form.errors.password }}
                </div>

                <form @submit.prevent="submit" novalidate>
                    <div class="mb-3">
                        <label class="form-label">Senha</label>
                        <div class="input-group">
                            <input
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                class="form-control"
                                :class="{ 'is-invalid': form.errors.password }"
                                autocomplete="new-password"
                                autofocus
                                required
                            >
                            <button type="button" class="btn btn-outline-secondary" tabindex="-1" @click="showPassword = !showPassword">
                                <i :class="showPassword ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Confirmar senha</label>
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

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-semibold" :disabled="form.processing">
                            <i v-if="form.processing" class="ti ti-loader-2 ee-spin me-1"></i>
                            Criar minha conta
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
