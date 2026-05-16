<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    t: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}

const statusMessage = computed(() => props.flash?.status ?? null);
</script>

<template>
    <Head :title="t.sign_in" />

    <GuestLayout layout-mode="login-illustration" :app-name="appName">
        <!-- ── Left panel: branding + features + quote ── -->
        <template #left-panel>
            <div class="ee-login-panel-wrapper">
                <div class="ee-login-panel-blob ee-login-panel-blob-1"></div>
                <div class="ee-login-panel-blob ee-login-panel-blob-2"></div>

                <img :src="'/system/images/logo-white.svg'" :alt="appName" class="ee-login-panel-logo">
                <img :src="'/system/images/icons/log-illustration-img-01.png'" :alt="appName" class="ee-login-panel-img">

                <div class="ee-login-features">
                    <div class="ee-login-feature">
                        <div class="ee-login-feature-ico"><i class="ti ti-calendar"></i></div>
                        <span>{{ t.panel?.feature_schedule }}</span>
                    </div>
                    <div class="ee-login-feature">
                        <div class="ee-login-feature-ico"><i class="ti ti-file-medical"></i></div>
                        <span>{{ t.panel?.feature_record }}</span>
                    </div>
                    <div class="ee-login-feature">
                        <div class="ee-login-feature-ico"><i class="ti ti-receipt"></i></div>
                        <span>{{ t.panel?.feature_tiss }}</span>
                    </div>
                    <div class="ee-login-feature">
                        <div class="ee-login-feature-ico"><i class="ti ti-shield-check"></i></div>
                        <span>{{ t.panel?.feature_compliance }}</span>
                    </div>
                </div>

                <div class="ee-login-quote">
                    <p>"{{ t.panel?.quote_text }}"</p>
                    <cite>{{ t.panel?.quote_author }}</cite>
                </div>
            </div>
        </template>

        <!-- ── Right: login form ── -->
        <div class="text-center mb-4">
            <a href="/login" class="d-inline-block">
                <img :src="'/system/images/icons/logo.svg'" class="img-fluid ee-login-brand" :alt="appName">
            </a>
        </div>

        <div class="card ee-login-card">
            <div class="card-body ee-login-card-body ee-auth-form">
                <div class="text-center mb-4">
                    <h2 class="ee-login-title">{{ t.sign_in }}</h2>
                    <p class="ee-login-subtitle">{{ t.login_subtitle }}</p>
                </div>

                <div v-if="statusMessage" class="alert alert-success mb-3 py-2">
                    <i class="ti ti-circle-check me-1"></i> {{ statusMessage }}
                </div>

                <div v-if="form.errors.email" class="alert alert-danger mb-3 py-2">
                    <i class="ti ti-alert-circle me-1"></i> {{ form.errors.email }}
                </div>

                <form @submit.prevent="submit" novalidate>

                    <div class="mb-3">
                        <label class="form-label">{{ t.email ?? 'E-mail' }}</label>
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
                        <label class="form-label">{{ t.password ?? 'Senha' }}</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="ti ti-lock"></i></span>
                            <input
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                class="form-control border-end-0"
                                :class="{ 'is-invalid': form.errors.password }"
                                autocomplete="current-password"
                                placeholder="••••••••••"
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
                            <label for="remember" class="form-check-label text-dark">
                                {{ t.remember_me }}
                            </label>
                        </div>
                        <a href="/forgot-password" class="ee-login-forgot">{{ t.forget_password }}</a>
                    </div>

                    <div class="d-grid mb-3">
                        <button
                            type="submit"
                            class="btn btn-primary ee-login-submit"
                            :disabled="form.processing"
                        >
                            <span v-if="form.processing">
                                <i class="ti ti-loader-2 ee-spin me-1"></i>
                            </span>
                            {{ t.sign_in }}
                        </button>
                    </div>

                    <p class="ee-login-register text-center mb-0">
                        {{ t.no_account_yet }}
                        <a href="/register">{{ t.sign_up }}</a>
                    </p>

                    <div class="ee-login-trust">
                        <div class="ee-login-trust-item"><i class="ti ti-lock"></i> SSL</div>
                        <div class="ee-login-trust-item"><i class="ti ti-shield-check"></i> LGPD</div>
                        <div class="ee-login-trust-item"><i class="ti ti-certificate"></i> CFM</div>
                        <div class="ee-login-trust-item"><i class="ti ti-star"></i> 97% NPS</div>
                    </div>
                </form>
            </div>
        </div>
    </GuestLayout>
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
