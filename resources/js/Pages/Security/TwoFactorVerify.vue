<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import twostepIllustrationImg from '@img/system/auth/twostep-verification-illustration-img.png';

/**
 * Tela de verificação 2FA exibida no fluxo de login (após senha,
 * antes de liberar acesso a rotas protegidas pelo middleware `2fa`).
 *
 * Toggle entre código TOTP (6 dígitos) e recovery code (XXXX-XXXX)
 * para o caso em que o usuário perdeu acesso ao app autenticador.
 *
 * Herda GuestLayout — mesma identidade visual de Login/Register.
 */
const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    t:       { type: Object, default: () => ({}) },
});

const mode  = ref('totp');   // 'totp' | 'recovery'
const code  = ref('');
const error = ref('');
const saving = ref(false);

async function submit() {
    error.value  = '';
    saving.value = true;

    try {
        const res = await fetch(route('security.two-factor.verify.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ code: code.value }),
        });

        const json = await res.json();

        if (!res.ok) {
            error.value = json.message ?? 'Código inválido.';
            return;
        }

        window.location.href = json.redirect ?? '/';
    } catch {
        error.value = 'Erro de rede. Tente novamente.';
    } finally {
        saving.value = false;
    }
}

function toggleMode() {
    mode.value  = mode.value === 'totp' ? 'recovery' : 'totp';
    code.value  = '';
    error.value = '';
}
</script>

<template>
    <Head :title="t.verify_title" />

    <GuestLayout
        layout-mode="illustration"
        :app-name="props.appName"
        :title="t.verify_title"
        :subtitle="t.verify_intro"
        :illustration-src="twostepIllustrationImg"
    >
        <form @submit.prevent="submit">
            <label class="form-label small">
                {{ mode === 'totp' ? t.code_label : t.recovery_code_label }}
            </label>
            <input
                v-model="code"
                type="text"
                :inputmode="mode === 'totp' ? 'numeric' : 'text'"
                :autocomplete="mode === 'totp' ? 'one-time-code' : 'off'"
                class="form-control text-center fw-bold fs-4 font-monospace"
                :class="{ 'is-invalid': error }"
                :placeholder="mode === 'totp' ? t.code_placeholder : 'XXXX-XXXX'"
                :maxlength="mode === 'totp' ? 7 : 20"
                autofocus
                :disabled="saving"
            >
            <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>

            <div class="d-flex justify-content-between align-items-center mt-4">
                <button type="button" class="btn btn-link btn-sm p-0" @click="toggleMode" :disabled="saving">
                    {{ mode === 'totp' ? t.verify_use_recovery : t.verify_use_totp }}
                </button>
                <button type="submit" class="btn btn-primary" :disabled="saving || !code">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="ti ti-shield-check me-1"></i>
                    {{ t.btn_verify }}
                </button>
            </div>
        </form>
    </GuestLayout>
</template>
