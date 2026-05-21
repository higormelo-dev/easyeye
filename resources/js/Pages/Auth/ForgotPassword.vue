<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import forgotIllustrationImg from '@img/system/auth/forgot-illustration-img.png';
import logoWhiteSvg from '@img/system/logo-white.svg';

const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    t: { type: Object, default: () => ({}) },
});

const page = usePage();
const statusMessage = computed(() => page.props.flash?.status ?? null);

const form = useForm({ email: '' });

function submit() {
    form.post('/forgot-password');
}
</script>

<template>
    <Head :title="t.forgot_password?.title" />

    <GuestLayout
        :app-name="appName"
        :title="t.forgot_password?.title"
        :illustration-src="forgotIllustrationImg"
    >
        <!-- ── Left panel: steps ── -->
        <template #left-panel>
            <div class="ee-fp-dark-panel">
                <img :src="logoWhiteSvg" :alt="appName" style="height:32px; margin-bottom:2.5rem;">

                <div
                    class="rounded-circle d-flex align-items-center justify-content-center mb-4"
                    style="width:80px; height:80px; background:rgba(0,180,216,.15); font-size:2rem; color:#00B4D8;"
                >
                    <i class="ti ti-lock-open"></i>
                </div>

                <h3 class="fw-bold mb-2" style="color:#fff; font-size:1.4rem;">{{ t.panel_fp?.title }}</h3>
                <p class="mb-4" style="color:rgba(255,255,255,.65); font-size:.9rem; max-width:280px;">{{ t.panel_fp?.subtitle }}</p>

                <div class="d-flex flex-column gap-3 text-start" style="max-width:300px; width:100%;">
                    <div v-for="n in 3" :key="n" class="d-flex align-items-start gap-3">
                        <div
                            class="rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                            style="width:28px; height:28px; background:rgba(0,180,216,.2); color:#00B4D8; font-size:13px;"
                        >{{ n }}</div>
                        <span style="color:rgba(255,255,255,.75); font-size:.85rem; padding-top:4px;">
                            {{ n === 1 ? t.panel_fp?.step_1 : n === 2 ? t.panel_fp?.step_2 : t.panel_fp?.step_3 }}
                        </span>
                    </div>
                </div>

                <div
                    class="d-flex align-items-center gap-2 mt-4 px-3 py-2 rounded"
                    style="background:rgba(255,255,255,.07); color:rgba(255,255,255,.55); font-size:.8rem;"
                >
                    <i class="ti ti-shield-check" style="color:#00B4D8;"></i>
                    {{ t.panel_fp?.security_note }}
                </div>
            </div>
        </template>

        <!-- Status: link sent -->
        <div v-if="statusMessage" class="alert alert-success mb-4 py-2">
            <i class="ti ti-circle-check me-1"></i> {{ statusMessage }}
        </div>

        <!-- Validation error -->
        <div v-if="form.errors.email" class="alert alert-danger mb-4 py-2">
            <i class="ti ti-alert-circle me-1"></i> {{ form.errors.email }}
        </div>

        <form @submit.prevent="submit" novalidate>
            <div class="mb-4">
                <label class="form-label">E-mail <span style="color:#ef4444;">*</span></label>
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
                <button
                    type="submit"
                    class="btn btn-primary w-100"
                    :disabled="form.processing"
                >
                    <i v-if="form.processing" class="ti ti-loader-2 ee-spin me-1"></i>
                    <i v-else class="ti ti-send me-1"></i>
                    {{ t.forgot_password?.send_link }}
                </button>
            </div>

            <div class="text-center">
                <a href="/login" class="d-inline-flex align-items-center gap-1 text-muted" style="font-size:.875rem; text-decoration:none;">
                    <i class="ti ti-arrow-left"></i>
                    {{ t.back_to_login }}
                </a>
            </div>
        </form>
    </GuestLayout>
</template>
