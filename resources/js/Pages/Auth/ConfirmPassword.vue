<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    t: { type: Object, default: () => ({}) },
});

const showPassword = ref(false);
const form = useForm({ password: '' });

function submit() {
    form.post('/confirm-password', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head :title="t.confirm_password?.title ?? 'Confirmação de segurança'" />

    <GuestLayout
        :app-name="appName"
        title="Área Segura"
        subtitle="Confirme sua identidade"
        :illustration-src="'/system/images/auth/twostep-verification-illustration-img.png'"
    >
        <p class="text-muted mb-4" style="font-size:.9rem;">
            Por segurança, confirme sua senha antes de continuar.
        </p>

        <div v-if="form.errors.password" class="alert alert-danger mb-3 py-2">
            {{ form.errors.password }}
        </div>

        <form @submit.prevent="submit" novalidate>
            <div class="mb-4">
                <label class="form-label">Senha <span style="color:#ef4444;">*</span></label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="ti ti-lock text-muted"></i></span>
                    <input
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        class="form-control border-start-0 border-end-0"
                        :class="{ 'is-invalid': form.errors.password }"
                        autocomplete="current-password"
                        autofocus
                        required
                    >
                    <button type="button" class="btn btn-outline-secondary border-start-0" tabindex="-1" @click="showPassword = !showPassword">
                        <i :class="showPassword ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                    </button>
                </div>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-primary fw-semibold" :disabled="form.processing">
                    <i v-if="form.processing" class="ti ti-loader-2 ee-spin me-1"></i>
                    Confirmar
                </button>
            </div>
        </form>
    </GuestLayout>
</template>
