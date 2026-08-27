<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';

/**
 * Etapa de onboarding: confirmar o WhatsApp do responsável (código OTP)
 * DEPOIS do e-mail e ANTES do painel — gate `phone.verified` no grupo
 * /panel redireciona para cá até a confirmação.
 *
 * Os endpoints send/confirm respondem JSON (throttle 3/10min e 6/min);
 * sucesso na confirmação → entra no painel com reload completo (mesmo
 * racional do login: troca de contexto de shell guest-app → panel-app).
 */
const props = defineProps({
    appName:     { type: String, default: 'EasyEye' },
    maskedPhone: { type: String, default: '' },
});

const code     = ref('');
const busy     = ref(false);
const feedback = ref('');
const isError  = ref(false);

const logoutForm = useForm({});

async function post(url, body) {
    busy.value = true;
    feedback.value = '';
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await response.json().catch(() => ({}));
        return { ok: response.ok, data };
    } catch {
        return { ok: false, data: {} };
    } finally {
        busy.value = false;
    }
}

async function confirm() {
    const { ok, data } = await post('/phone/verification-confirm', { code: code.value });
    isError.value = !ok;
    feedback.value = data.message ?? (ok ? 'WhatsApp confirmado.' : 'Código inválido ou expirado.');
    if (ok) {
        setTimeout(() => { window.location.href = '/panel/dashboard'; }, 900);
    }
}

async function resend() {
    const { ok, data } = await post('/phone/verification-code');
    isError.value = !ok;
    feedback.value = data.message ?? (ok ? 'Código reenviado.' : 'Não foi possível reenviar agora.');
}

function logout() {
    logoutForm.post('/logout');
}
</script>

<template>
    <Head title="Confirme seu WhatsApp" />

    <GuestLayout
        :app-name="appName"
        title="Confirme seu WhatsApp"
        subtitle="Último passo antes de entrar"
    >
        <p class="text-muted mb-1" style="font-size:.9rem;">
            Enviamos um código de 6 dígitos por WhatsApp para
            <strong>{{ maskedPhone }}</strong>.
        </p>
        <p class="text-muted mb-4" style="font-size:.9rem;">
            Digite o código abaixo para concluir seu cadastro e acessar o sistema.
        </p>

        <div v-if="feedback" class="alert mb-4" :class="isError ? 'alert-danger' : 'alert-success'">
            <i class="ti me-1" :class="isError ? 'ti-alert-circle' : 'ti-circle-check'"></i> {{ feedback }}
        </div>

        <form @submit.prevent="confirm">
            <div class="mb-3">
                <input
                    v-model="code"
                    type="text"
                    inputmode="numeric"
                    maxlength="6"
                    class="form-control form-control-lg text-center fw-semibold"
                    style="letter-spacing:.5em;"
                    placeholder="000000"
                    autofocus
                    aria-label="Código de verificação do WhatsApp"
                >
            </div>

            <div class="d-grid mb-3">
                <button
                    type="submit"
                    class="btn btn-primary fw-semibold"
                    :disabled="busy || code.length !== 6"
                >
                    <i v-if="busy" class="ti ti-loader-2 ee-spin me-1"></i>
                    Confirmar
                </button>
            </div>
        </form>

        <div class="d-grid mb-3">
            <button type="button" class="btn btn-outline-secondary" :disabled="busy" @click="resend">
                <i class="ti ti-refresh me-1"></i> Reenviar código
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
                Sair
            </button>
        </div>
    </GuestLayout>
</template>
