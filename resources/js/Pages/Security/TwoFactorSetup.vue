<script setup>
import { ref, computed } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import twostepIllustrationImg from '@img/system/auth/twostep-verification-illustration-img.png';

/**
 * Tela de setup de 2FA. Herda o GuestLayout (mesmo wrapper das telas
 * de login/register/forgot-password) por dois motivos:
 *
 *  1) Consistência visual — o usuário está fora do painel autenticado,
 *     em fluxo de "complete sua identidade", igual a verify e select-entity.
 *  2) Reaproveita locale switcher, dark mode toggle e footer sem código novo.
 *
 * Estado:
 *  - step='setup'     → mostra QR + campo de código.
 *  - step='recovery'  → mostra os 10 recovery codes (única exibição).
 */
const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    secret:  { type: String, required: true },
    qr_svg:  { type: String, required: true },
    otpauth: { type: String, required: true },
    t:       { type: Object, default: () => ({}) },
});

const step          = ref('setup');             // 'setup' | 'recovery'
const recoveryCodes = ref([]);
const showManual    = ref(false);
const code          = ref('');
const error         = ref('');
const submitting    = ref(false);

async function confirm() {
    error.value      = '';
    submitting.value = true;

    try {
        const res = await fetch(route('security.two-factor.confirm'), {
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
        recoveryCodes.value = json.recovery_codes ?? [];
        step.value = 'recovery';
    } catch {
        error.value = 'Erro de rede. Tente novamente.';
    } finally {
        submitting.value = false;
    }
}

function regenerate() {
    router.post(route('security.two-factor.setup.store'));
}

function copyRecoveryCodes() {
    const text = recoveryCodes.value.join('\n');
    navigator.clipboard?.writeText(text);
    if (window.showSuccessToast) window.showSuccessToast(props.t.copied ?? 'Códigos copiados.');
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
}

function done() {
    window.location.href = '/';
}

const formattedSecret = computed(() => {
    return props.secret.match(/.{1,4}/g)?.join(' ') ?? props.secret;
});
</script>

<template>
    <Head :title="t.setup_title" />

    <GuestLayout
        layout-mode="illustration"
        :app-name="props.appName"
        :title="step === 'setup' ? t.setup_title : t.recovery_title"
        :subtitle="step === 'setup' ? t.setup_intro : t.recovery_intro"
        :illustration-src="twostepIllustrationImg"
    >

        <!-- ── Step: setup (QR + código) ───────────────────────────────────── -->
        <template v-if="step === 'setup'">
            <div class="mb-3">
                <p class="fw-medium small mb-2">
                    <i class="ti ti-qrcode me-1 text-primary"></i>{{ t.setup_step_1 }}
                </p>
                <div class="d-flex justify-content-center p-3 border rounded bg-white" v-html="qr_svg"></div>
            </div>

            <div class="mb-3">
                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" @click="showManual = !showManual">
                    <i :class="`ti me-1 ${showManual ? 'ti-chevron-up' : 'ti-chevron-down'}`"></i>
                    {{ t.manual_secret }}
                </button>
                <div v-if="showManual" class="mt-2 p-2 bg-light border rounded font-monospace small text-break">
                    {{ formattedSecret }}
                </div>
            </div>

            <p class="fw-medium small mb-2">
                <i class="ti ti-keyboard me-1 text-primary"></i>{{ t.setup_step_2 }}
            </p>
            <form @submit.prevent="confirm">
                <label class="form-label small">{{ t.code_label }}</label>
                <input
                    v-model="code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    class="form-control text-center fw-bold fs-4 font-monospace"
                    :class="{ 'is-invalid': error }"
                    :placeholder="t.code_placeholder"
                    maxlength="7"
                    autofocus
                    :disabled="submitting"
                >
                <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>

                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-link btn-sm" :disabled="submitting" @click="regenerate">
                        <i class="ti ti-refresh me-1"></i>{{ t.btn_regenerate }}
                    </button>
                    <button type="submit" class="btn btn-primary" :disabled="submitting || !code">
                        <span v-if="submitting" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ti ti-check me-1"></i>
                        {{ t.btn_confirm }}
                    </button>
                </div>
            </form>
        </template>

        <!-- ── Step: recovery codes (única exibição) ───────────────────────── -->
        <template v-else-if="step === 'recovery'">
            <div class="alert alert-warning small d-flex align-items-start mb-3">
                <i class="ti ti-alert-triangle me-2 fs-5 mt-1"></i>
                <span>{{ t.recovery_warning }}</span>
            </div>

            <div class="bg-light border rounded p-3 mb-3 font-monospace">
                <ul class="list-unstyled mb-0">
                    <li v-for="rc in recoveryCodes" :key="rc" class="mb-1">{{ rc }}</li>
                </ul>
            </div>

            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyRecoveryCodes">
                    <i class="ti ti-copy me-1"></i>{{ t.btn_copy }}
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" @click="downloadRecoveryCodes">
                    <i class="ti ti-download me-1"></i>{{ t.btn_download }}
                </button>
            </div>

            <button type="button" class="btn btn-primary w-100" @click="done">
                <i class="ti ti-check me-1"></i>{{ t.btn_done }}
            </button>
        </template>

    </GuestLayout>
</template>
