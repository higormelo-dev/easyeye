<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    t: { type: Object, default: () => ({}) },
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
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head :title="t.reset_password?.title" />

    <GuestLayout
        :app-name="appName"
        :title="t.reset_password?.title"
        :illustration-src="'/system/images/auth/reset-illustration-img.png'"
    >
        <div v-if="form.errors.email" class="alert alert-danger mb-3 py-2">
            <i class="ti ti-alert-circle me-1"></i> {{ form.errors.email }}
        </div>

        <form @submit.prevent="submit" novalidate>
            <input type="hidden" :value="form.token" name="token">

            <div class="mb-3">
                <label class="form-label">{{ t.reset_password?.email }} <span style="color:#ef4444;">*</span></label>
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
                <label class="form-label">{{ t.reset_password?.password }} <span style="color:#ef4444;">*</span></label>
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
                <label class="form-label">{{ t.reset_password?.confirm_password }} <span style="color:#ef4444;">*</span></label>
                <div class="input-group">
                    <input
                        v-model="form.password_confirmation"
                        :type="showConfirm ? 'text' : 'password'"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.password_confirmation }"
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
                    {{ t.reset_password?.submit }}
                </button>
            </div>

            <p class="text-center mb-0">
                <a href="/login" class="text-muted" style="font-size:.875rem; text-decoration:none;">{{ t.back_to_login }}</a>
            </p>
        </form>
    </GuestLayout>
</template>
