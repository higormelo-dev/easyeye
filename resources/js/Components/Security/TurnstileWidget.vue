<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Cloudflare Turnstile (captcha) — renderização explícita
 * (https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/):
 * carrega https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit
 * só quando há site key, desenha o widget e emite o token (vale 5 minutos e
 * uma única validação — o servidor valida em /siteverify). `reset()` pede um
 * token novo (ex.: depois de um envio recusado).
 */
const props = defineProps({
    siteKey: { type: String, required: true },
    label: { type: String, default: '' },
    language: { type: String, default: 'auto' },
});

const emit = defineEmits(['token']);

const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

const container = ref(null);
const failed = ref(false);
let widgetId = null;

function loadTurnstile() {
    if (typeof window === 'undefined') return Promise.reject(new Error('no_window'));
    if (window.turnstile) return Promise.resolve(window.turnstile);

    window.__eeTurnstile ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCRIPT_URL;
        script.async = true;
        script.defer = true;
        script.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error('turnstile_missing')));
        script.onerror = () => {
            window.__eeTurnstile = undefined;
            script.remove();
            reject(new Error('turnstile_load_failed'));
        };
        document.head.appendChild(script);
    });

    return window.__eeTurnstile;
}

onMounted(async () => {
    try {
        const turnstile = await loadTurnstile();
        widgetId = turnstile.render(container.value, {
            sitekey: props.siteKey,
            language: props.language,
            theme: 'auto',
            callback: (token) => emit('token', token),
            'expired-callback': () => emit('token', ''),
            'error-callback': () => emit('token', ''),
        });
    } catch {
        failed.value = true;
    }
});

onBeforeUnmount(() => {
    try {
        if (widgetId !== null) window.turnstile?.remove(widgetId);
    } catch {
        /* já removido */
    }
    widgetId = null;
});

defineExpose({
    reset() {
        emit('token', '');
        try {
            if (widgetId !== null) window.turnstile?.reset(widgetId);
        } catch {
            /* sem widget */
        }
    },
});
</script>

<template>
    <div class="ee-turnstile" data-test="turnstile" role="group" :aria-label="label">
        <div ref="container"></div>
        <p v-if="failed" class="small text-danger mb-0" role="alert">{{ label }}</p>
    </div>
</template>
