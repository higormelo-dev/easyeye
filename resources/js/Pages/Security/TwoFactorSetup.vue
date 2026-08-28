<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';

/**
 * Setup de 2FA (TOTP). Mesmo desenho da tela "Confirme seu WhatsApp"
 * (Auth/VerifyPhone.vue): título/subtítulo, parágrafos de apoio, alert de
 * feedback, input grande centralizado e botões full-width.
 *
 * Etapas:
 *  - step='setup'    → QR code + secret manual (mostrar/copiar) + código.
 *  - step='recovery' → 10 recovery codes (ÚNICA exibição): copiar, baixar
 *                      e "Já guardei" → redirect devolvido pelo backend.
 *
 * Esta página roda no shell `guest-app` (sem Ziggy/@routes): os endpoints
 * são caminhos literais, como na referência. `confirm` responde JSON puro
 * (fetch); `regenerate` é um POST Inertia que redireciona para o próprio
 * setup com um secret novo (mesmo componente, props atualizadas).
 */
const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    secret:  { type: String, required: true },
    qr_svg:  { type: String, required: true },
    otpauth: { type: String, required: true },
    t:       { type: Object, default: () => ({}) },
});

const step          = ref('setup');   // 'setup' | 'recovery'
const recoveryCodes = ref([]);
const redirectUrl   = ref('');
const showManual    = ref(false);
const code          = ref('');
const busy          = ref(false);
const feedback      = ref('');
const isError       = ref(false);

const logoutForm     = useForm({});
const regenerateForm = useForm({});

const isSetup = computed(() => step.value === 'setup');

/** Secret em grupos de 4 para digitação manual no app autenticador. */
const formattedSecret = computed(() => {
    return props.secret.match(/.{1,4}/g)?.join(' ') ?? props.secret;
});

function setFeedback(message, error = false) {
    feedback.value = message;
    isError.value  = error;
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

// ── Etapa 1: confirmar código do app ─────────────────────────────────────────

async function confirm() {
    if (code.value.length !== 6 || busy.value) return;

    const { ok, status, data } = await post('/security/two-factor/confirm', { code: code.value });

    if (!ok) {
        setFeedback(errorMessage(status, data), true);
        code.value = '';
        return;
    }

    recoveryCodes.value = data.recovery_codes ?? [];
    redirectUrl.value   = data.redirect ?? '/panel/dashboard';
    code.value          = '';
    step.value          = 'recovery';
    setFeedback(data.message ?? props.t.enabled ?? 'Autenticação em dois fatores ativada com sucesso.');
}

function regenerate() {
    showManual.value = false;
    code.value       = '';
    feedback.value   = '';

    regenerateForm.post('/security/two-factor/setup', {
        preserveScroll: true,
        onSuccess: () => setFeedback(props.t.regenerated ?? 'Novo QR code gerado. Escaneie novamente.'),
        onError:   () => setFeedback(props.t.network_error ?? 'Erro de rede. Tente novamente.', true),
    });
}

async function copyToClipboard(text) {
    try {
        if (!navigator.clipboard?.writeText) {
            throw new Error('clipboard-unavailable');
        }
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        return false;
    }
}

async function copySecret() {
    const copied = await copyToClipboard(props.secret);
    setFeedback(
        copied
            ? (props.t.secret_copied ?? 'Código copiado.')
            : (props.t.copy_failed ?? 'Não foi possível copiar. Selecione e copie manualmente.'),
        !copied,
    );
}

// ── Etapa 2: recovery codes ──────────────────────────────────────────────────

async function copyRecoveryCodes() {
    const copied = await copyToClipboard(recoveryCodes.value.join('\n'));
    setFeedback(
        copied
            ? (props.t.copied ?? 'Códigos copiados.')
            : (props.t.copy_failed ?? 'Não foi possível copiar. Selecione e copie manualmente.'),
        !copied,
    );
}

function downloadRecoveryCodes() {
    const text = recoveryCodes.value.join('\n');
    const blob = new Blob([text], { type: 'text/plain' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'easyeye-recovery-codes.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    setFeedback(props.t.downloaded ?? 'Arquivo gerado. Guarde-o em local seguro.');
}

function done() {
    window.location.href = redirectUrl.value || '/panel/dashboard';
}

function logout() {
    logoutForm.post('/logout');
}
</script>

<template>
    <Head :title="isSetup ? t.setup_title : t.recovery_title" />

    <GuestLayout
        :app-name="appName"
        :title="isSetup ? t.setup_title : t.recovery_title"
        :subtitle="isSetup ? t.setup_subtitle : t.recovery_subtitle"
    >

        <!-- ── Etapa: setup (QR + secret manual + código) ─────────────────── -->
        <template v-if="isSetup">
            <p class="text-muted mb-1" style="font-size:.9rem;">
                {{ t.setup_intro }}
            </p>
            <p class="text-muted mb-4" style="font-size:.9rem;">
                {{ t.setup_help }}
            </p>

            <div v-if="feedback" class="alert mb-4" :class="isError ? 'alert-danger' : 'alert-success'">
                <i class="ti me-1" :class="isError ? 'ti-alert-circle' : 'ti-circle-check'"></i> {{ feedback }}
            </div>

            <p class="text-muted mb-2" style="font-size:.9rem;">
                <i class="ti ti-qrcode me-1"></i>{{ t.setup_step_1 }}
            </p>
            <!-- bg-white proposital: o QR precisa de fundo branco também no dark mode -->
            <div class="d-flex justify-content-center p-3 border rounded bg-white mb-3" v-html="qr_svg"></div>

            <div class="mb-4">
                <button
                    type="button"
                    class="btn btn-link text-muted p-0 text-decoration-none"
                    style="font-size:.875rem;"
                    :aria-expanded="showManual"
                    @click="showManual = !showManual"
                >
                    <i class="ti me-1" :class="showManual ? 'ti-chevron-up' : 'ti-chevron-down'"></i>
                    {{ t.manual_secret }}
                </button>

                <div v-if="showManual" class="mt-2">
                    <div
                        class="border rounded bg-body-tertiary p-3 font-monospace fw-semibold text-center text-break user-select-all"
                        style="letter-spacing:.15em; font-size:1rem;"
                    >
                        {{ formattedSecret }}
                    </div>
                    <div class="d-grid mt-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="copySecret">
                            <i class="ti ti-copy me-1"></i> {{ t.btn_copy_secret }}
                        </button>
                    </div>
                </div>
            </div>

            <p class="text-muted mb-2" style="font-size:.9rem;">
                <i class="ti ti-keyboard me-1"></i>{{ t.setup_step_2 }}
            </p>

            <form @submit.prevent="confirm">
                <div class="mb-3">
                    <input
                        v-model="code"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        autocomplete="one-time-code"
                        class="form-control form-control-lg text-center fw-semibold"
                        style="letter-spacing:.5em;"
                        :placeholder="t.code_placeholder"
                        autofocus
                        :aria-label="t.code_aria_label"
                    >
                </div>

                <div class="d-grid mb-3">
                    <button
                        type="submit"
                        class="btn btn-primary fw-semibold"
                        :disabled="busy || regenerateForm.processing || code.length !== 6"
                    >
                        <i v-if="busy" class="ti ti-loader-2 ee-spin me-1"></i>
                        <i v-else class="ti ti-shield-check me-1"></i>
                        {{ t.btn_confirm }}
                    </button>
                </div>
            </form>

            <div class="d-grid mb-3">
                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    :disabled="busy || regenerateForm.processing"
                    @click="regenerate"
                >
                    <i v-if="regenerateForm.processing" class="ti ti-loader-2 ee-spin me-1"></i>
                    <i v-else class="ti ti-refresh me-1"></i>
                    {{ t.btn_regenerate }}
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
        </template>

        <!-- ── Etapa: recovery codes (única exibição) ─────────────────────── -->
        <template v-else>
            <p class="text-muted mb-4" style="font-size:.9rem;">
                {{ t.recovery_intro }}
            </p>

            <div v-if="feedback" class="alert mb-4" :class="isError ? 'alert-danger' : 'alert-success'">
                <i class="ti me-1" :class="isError ? 'ti-alert-circle' : 'ti-circle-check'"></i> {{ feedback }}
            </div>

            <div class="alert alert-warning mb-4">
                <i class="ti ti-alert-triangle me-1"></i> {{ t.recovery_warning }}
            </div>

            <div class="border rounded bg-body-tertiary p-3 mb-3 font-monospace user-select-all">
                <div class="row row-cols-2 g-2 text-center">
                    <div v-for="rc in recoveryCodes" :key="rc" class="col fw-semibold" style="letter-spacing:.08em;">
                        {{ rc }}
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-outline-secondary flex-fill" @click="copyRecoveryCodes">
                    <i class="ti ti-copy me-1"></i> {{ t.btn_copy }}
                </button>
                <button type="button" class="btn btn-outline-secondary flex-fill" @click="downloadRecoveryCodes">
                    <i class="ti ti-download me-1"></i> {{ t.btn_download }}
                </button>
            </div>

            <div class="d-grid">
                <button type="button" class="btn btn-primary fw-semibold" @click="done">
                    <i class="ti ti-circle-check me-1"></i> {{ t.btn_done }}
                </button>
            </div>
        </template>

    </GuestLayout>
</template>
