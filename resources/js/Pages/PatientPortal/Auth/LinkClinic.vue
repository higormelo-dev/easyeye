<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';

/**
 * Convite de OUTRA clínica aberto por quem já está logado no portal: um clique
 * adiciona a clínica (paciente leigo — sem nova senha; o convite foi para o
 * MESMO e-mail da conta). Convite para outro e-mail não entra nesta conta — a
 * tela só orienta sair (o servidor também recusa: emailMatches vem dele).
 */
const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    clinics: { type: Array, default: () => [] },
    accountEmail: { type: String, default: '' },
    inviteEmail: { type: String, default: '' },
    emailMatches: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const tx = (key, params = {}) =>
    String(props.t?.[key] ?? key).replace(/:([A-Za-z_]+)/g, (match, name) =>
        name in params ? String(params[name]) : match,
    );

const form = useForm({});

function submit() {
    // Mesma querystring assinada do GET (person_id, expires, signature).
    form.post(window.location.pathname + window.location.search);
}

function logout() {
    router.post(route('patient-portal.logout'));
}
</script>

<template>
    <Head :title="tx('page_title')" />

    <main class="d-flex align-items-center justify-content-center min-vh-100 bg-light px-3">
        <div class="card shadow-sm border-0 w-100" style="max-width: 460px">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div
                        class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary mb-3"
                        style="width: 56px; height: 56px"
                        aria-hidden="true"
                    >
                        <i class="ti ti-building-hospital fs-4"></i>
                    </div>
                    <h1 class="h4 fw-bold mb-2">{{ tx('title') }}</h1>
                    <p class="text-muted small mb-2">{{ tx('intro') }}</p>
                    <ul class="list-unstyled fw-semibold mb-0">
                        <li v-for="clinic in clinics.length ? clinics : [tx('clinic_fallback')]" :key="clinic">
                            {{ clinic }}
                        </li>
                    </ul>
                </div>

                <dl class="small mb-3">
                    <dt class="text-muted fw-normal">{{ tx('account') }}</dt>
                    <dd class="mb-0 fw-semibold text-break">{{ accountEmail }}</dd>
                </dl>

                <template v-if="!emailMatches">
                    <div class="alert alert-warning py-2 small" role="alert">
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                        >{{ tx('email_mismatch', { email: inviteEmail }) }}
                    </div>
                    <div class="d-grid">
                        <button type="button" class="btn btn-outline-secondary" @click="logout">
                            {{ tx('not_you') }}
                        </button>
                    </div>
                </template>

                <form v-else @submit.prevent="submit" novalidate>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg fw-semibold" :disabled="form.processing">
                            <i v-if="form.processing" class="ti ti-loader-2 ee-spin me-1" aria-hidden="true"></i>
                            {{ tx('submit') }}
                        </button>
                        <button type="button" class="btn btn-link btn-sm text-muted" @click="logout">
                            {{ tx('not_you') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</template>
