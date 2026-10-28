<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CopyField from './CopyField.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Pix na tela do EasyEye: QR Code (PNG em base64 do gateway, a imagem dele
 * ou, sem nenhum dos dois, gerado aqui a partir do copia-e-cola), o código
 * copia-e-cola com "Copiar" e a validade. Vencido, pede um código novo
 * (`refresh`). A confirmação chega pelo tempo real (CheckoutFlow).
 */
const props = defineProps({
    pix: { type: Object, required: true },
    amount: { type: [Number, String], default: null },
    ui: { type: Object, default: () => ({}) },
});

defineEmits(['refresh']);

const { money, dateTime } = useLocaleFormat();
const { tx } = useTrans(() => props.ui);

const generatedQr = ref('');

const safeImageUrl = computed(() => {
    try {
        const url = new URL(String(props.pix.qr_image_url ?? ''));

        return url.protocol === 'https:' ? url.href : '';
    } catch {
        return '';
    }
});

const qrSrc = computed(() => {
    if (props.pix.qr_code_base64) return `data:image/png;base64,${props.pix.qr_code_base64}`;
    if (safeImageUrl.value) return safeImageUrl.value;

    return generatedQr.value;
});

async function generateQr() {
    generatedQr.value = '';
    if (props.pix.qr_code_base64 || safeImageUrl.value || !props.pix.copy_paste) return;
    try {
        const QRCode = (await import('qrcode')).default;
        generatedQr.value = await QRCode.toDataURL(props.pix.copy_paste, { margin: 1, width: 240 });
    } catch {
        generatedQr.value = '';
    }
}

// Validade: relógio local (só para mostrar "expirou"; nada vai ao servidor).
const now = ref(Date.now());
let clock = null;
const expiresAt = computed(() => {
    const time = props.pix.expires_at ? new Date(props.pix.expires_at).getTime() : NaN;

    return Number.isNaN(time) ? null : time;
});
const expired = computed(() => expiresAt.value !== null && now.value >= expiresAt.value);

onMounted(() => {
    generateQr();
    clock = setInterval(() => (now.value = Date.now()), 15000);
});
watch(() => [props.pix.copy_paste, props.pix.qr_code_base64, props.pix.qr_image_url], generateQr);
onBeforeUnmount(() => clearInterval(clock));
</script>

<template>
    <div class="ee-pix" data-test="checkout-pix">
        <p class="small text-muted mb-3">{{ ui.pix_steps }}</p>

        <div
            v-if="expired"
            class="alert alert-warning text-warning-emphasis small"
            role="alert"
            data-test="pix-expired"
        >
            <p class="mb-2">{{ ui.pix_expired }}</p>
            <button type="button" class="btn btn-sm btn-primary" @click="$emit('refresh')">
                <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ ui.pix_new_code }}
            </button>
        </div>

        <template v-else>
            <div class="row g-3 align-items-center">
                <div v-if="qrSrc" class="col-12 col-sm-auto text-center">
                    <img
                        :src="qrSrc"
                        :alt="tx('pix_qr_alt', { amount: money(amount) })"
                        class="ee-pix__qr img-fluid border rounded p-1 bg-white"
                        width="220"
                        height="220"
                        data-test="pix-qr"
                    />
                </div>
                <div class="col min-w-0">
                    <CopyField
                        v-if="pix.copy_paste"
                        :label="ui.pix_copy_label"
                        :value="pix.copy_paste"
                        :copy-label="ui.copy"
                        :copied-label="ui.copied"
                        :failed-label="ui.copy_failed"
                        multiline
                    />
                    <p v-if="expiresAt" class="small text-muted mt-2 mb-0" data-test="pix-expires">
                        <i class="ti ti-clock me-1" aria-hidden="true"></i
                        >{{ tx('pix_expires_at', { date: dateTime(pix.expires_at) }) }}
                    </p>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.ee-pix__qr {
    width: 220px;
    max-width: 100%;
    height: auto;
}
</style>
