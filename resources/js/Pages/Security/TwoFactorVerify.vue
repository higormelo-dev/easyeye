<script setup>
import { ref, computed, nextTick } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';

/**
 * Verificação 2FA no fluxo de login (após senha, antes de liberar as rotas
 * protegidas pelo middleware `2fa`). Mesmo desenho da tela "Confirme seu
 * WhatsApp" (Auth/VerifyPhone.vue): título/subtítulo, parágrafos de apoio,
 * alert de feedback, input grande centralizado e botões full-width.
 *
 * Modos:
 *  - 'totp'     → código de 6 dígitos do app autenticador (Google/Microsoft).
 *  - 'recovery' → código de recuperação XXXX-XXXX (perdeu o aparelho).
 *
 * Esta página roda no shell `guest-app` (sem Ziggy/@routes): os endpoints
 * são caminhos literais, como na referência. O POST responde JSON e o
 * sucesso faz reload completo para o redirect do backend (troca de shell
 * guest-app → panel-app).
 */
const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    t:       { type: Object, default: () => ({}) },
});

const mode     = ref('totp');   // 'totp' | 'recovery'
const code     = ref('');
const busy     = ref(false);
const feedback = ref('');
const isError  = ref(false);
const codeInput = ref(null);

const logoutForm = useForm({});

const isTotp = computed(() => mode.value === 'totp');

const RECOVERY_PATTERN = /^[A-Z0-9]{4}-?[A-Z0-9]{4}$/i;

const canSubmit = computed(() => {
    return isTotp.value
        ? /^\d{6}$/.test(code.value)
        : RECOVERY_PATTERN.test(code.value.trim());
});

/** Recovery code é gravado com hífen no servidor: garante o formato XXXX-XXXX. */
function normalizedCode() {
    if (isTotp.value) {
        return code.value.trim();
    }

    const raw = code.value.trim().toUpperCase().replace(/\s+/g, '');

    return raw.length === 8 ? `${raw.slice(0, 4)}-${raw.slice(4)}` : raw;
}

async function post(url, body) {
    busy.value = true;
    feedback.value = '';
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await response.json().catch(() => ({}));
        return { ok: response.ok, status: response.status, data };
    } catch {
        return { ok: false, status: 0, data: {} };
    } finally {
        busy.value = false;
    }
}

function errorMessage(status, data) {
    if (status === 0)   return props.t.network_error ?? 'Erro de rede. Tente novamente.';
    if (status === 429) return props.t.too_many_attempts ?? 'Muitas tentativas. Aguarde um minuto e tente novamente.';
    if (status === 401 || status === 419) {
        return props.t.session_expired ?? 'Sua sessão expirou. Recarregue a página e entre novamente.';
    }

    return data.errors?.code?.[0] ?? data.message ?? props.t.invalid_code ?? 'Código inválido ou expirado.';
}

async function verify() {
    if (!canSubmit.value || busy.value) return;

    const { ok, status, data } = await post('/security/two-factor/verify', { code: normalizedCode() });

    isError.value = !ok;

    if (!ok) {
        feedback.value = errorMessage(status, data);
        code.value = '';
        return;
    }

    feedback.value = data.message ?? props.t.verified ?? 'Código verificado.';
    setTimeout(() => { window.location.href = data.redirect ?? '/panel/dashboard'; }, 900);
}

function toggleMode() {
    mode.value     = isTotp.value ? 'recovery' : 'totp';
    code.value     = '';
    feedback.value = '';
    isError.value  = false;
    nextTick(() => codeInput.value?.focus());
}

function logout() {
    logoutForm.post('/logout');
}
</script>

<template>
    <Head :title="t.verify_title" />

    <GuestLayout
        :app-name="appName"
        :title="t.verify_title"
        :subtitle="t.verify_subtitle"
    >
        <p class="text-muted mb-1" style="font-size:.9rem;">
            {{ isTotp ? t.verify_hint_totp : t.verify_hint_recovery }}
        </p>
        <p class="text-muted mb-4" style="font-size:.9rem;">
            {{ t.verify_help }}
        </p>

        <div v-if="feedback" class="alert mb-4" :class="isError ? 'alert-danger' : 'alert-success'">
            <i class="ti me-1" :class="isError ? 'ti-alert-circle' : 'ti-circle-check'"></i> {{ feedback }}
        </div>

        <form @submit.prevent="verify">
            <div class="mb-3">
                <input
                    ref="codeInput"
                    v-model="code"
                    type="text"
                    :inputmode="isTotp ? 'numeric' : 'text'"
                    :maxlength="isTotp ? 6 : 9"
                    :autocomplete="isTotp ? 'one-time-code' : 'off'"
                    class="form-control form-control-lg text-center fw-semibold"
                    :class="{ 'text-uppercase': !isTotp }"
                    :style="{ letterSpacing: isTotp ? '.5em' : '.25em' }"
                    :placeholder="isTotp ? t.code_placeholder : t.recovery_code_placeholder"
                    autofocus
                    :aria-label="isTotp ? t.code_aria_label : t.recovery_code_aria_label"
                >
            </div>

            <div class="d-grid mb-3">
                <button
                    type="submit"
                    class="btn btn-primary fw-semibold"
                    :disabled="busy || !canSubmit"
                >
                    <i v-if="busy" class="ti ti-loader-2 ee-spin me-1"></i>
                    <i v-else class="ti ti-shield-check me-1"></i>
                    {{ t.btn_verify }}
                </button>
            </div>
        </form>

        <div class="d-grid mb-3">
            <button type="button" class="btn btn-outline-secondary" :disabled="busy" @click="toggleMode">
                <i class="ti me-1" :class="isTotp ? 'ti-key' : 'ti-device-mobile'"></i>
                {{ isTotp ? t.verify_use_recovery : t.verify_use_totp }}
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
                {{ t.btn_logout }}
            </button>
        </div>
    </GuestLayout>
</template>
