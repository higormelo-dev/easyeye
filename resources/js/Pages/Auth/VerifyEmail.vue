<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    t: { type: Object, default: () => ({}) },
});

const page = usePage();
const verificationSent = computed(() => page.props.flash?.status === 'verification-link-sent');

const resendForm = useForm({});
const logoutForm = useForm({});

function resend() {
    resendForm.post('/email/verification-notification');
}

function logout() {
    logoutForm.post('/logout');
}
</script>

<template>
    <Head :title="t.verify_email?.title" />

    <GuestLayout
        :app-name="appName"
        :title="t.verify_email?.title"
        subtitle="Confirmação necessária"
        :illustration-src="'/system/images/auth/email-verification-illustration-img.png'"
    >
        <p class="text-muted mb-4" style="font-size:.9rem;">{{ t.verify_email?.description }}</p>

        <div v-if="verificationSent" class="alert alert-success mb-4">
            <i class="ti ti-circle-check me-1"></i> {{ t.verify_email?.link_sent }}
        </div>

        <div class="d-grid mb-3">
            <button
                type="button"
                class="btn btn-primary fw-semibold"
                :disabled="resendForm.processing"
                @click="resend"
            >
                <i v-if="resendForm.processing" class="ti ti-loader-2 ee-spin me-1"></i>
                {{ t.verify_email?.resend }}
            </button>
        </div>

        <div class="text-center">
            <button
                type="button"
                class="btn btn-link text-muted"
                style="font-size:.875rem;"
                :disabled="logoutForm.processing"
                @click="logout"
            >
                {{ t.log_out }}
            </button>
        </div>
    </GuestLayout>
</template>
