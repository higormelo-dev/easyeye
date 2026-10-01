<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import twostepIllustrationImg from '@img/system/auth/twostep-verification-illustration-img.png';

/**
 * Convite de uma clínica (médico ou usuário) para quem já tem login no EasyEye. Mostra só
 * o nome da clínica; aceitar/recusar usam links assinados gerados pelo
 * servidor (acceptUrl/declineUrl). Textos: lang/<locale>/doctors.php
 * ou access_control.php (invitation.page).
 */
const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    clinicName: { type: String, default: '' },
    // Linha extra já traduzida pelo servidor (ex.: perfil oferecido).
    detail: { type: String, default: '' },
    open: { type: Boolean, default: false },
    acceptUrl: { type: String, required: true },
    declineUrl: { type: String, required: true },
    t: { type: Object, default: () => ({}) },
});

const tx = (key) => String(props.t?.[key] ?? key).replace(/:clinic\b/g, props.clinicName);
const title = computed(() => tx('title'));

const form = useForm({});

function accept() {
    form.post(props.acceptUrl);
}

function decline() {
    form.post(props.declineUrl);
}

function goToClinics() {
    router.visit(route('selectentity.create'));
}
</script>

<template>
    <Head :title="title" />

    <GuestLayout
        :app-name="appName"
        :title="title"
        :subtitle="open ? tx('intro') : ''"
        :illustration-src="twostepIllustrationImg"
    >
        <template v-if="open">
            <p v-if="detail" class="fw-semibold mb-2">{{ detail }}</p>
            <p class="text-muted small mb-4">{{ tx('note') }}</p>

            <div class="d-grid gap-2">
                <button type="button" class="btn btn-primary fw-semibold" :disabled="form.processing" @click="accept">
                    <i v-if="form.processing" class="ti ti-loader-2 ee-spin me-1" aria-hidden="true"></i>
                    {{ tx('accept') }}
                </button>
                <button type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="decline">
                    {{ tx('decline') }}
                </button>
            </div>
        </template>

        <template v-else>
            <div class="alert alert-warning" role="alert">{{ tx('closed') }}</div>
            <div class="d-grid">
                <button type="button" class="btn btn-primary" @click="goToClinics">{{ tx('back') }}</button>
            </div>
        </template>
    </GuestLayout>
</template>
