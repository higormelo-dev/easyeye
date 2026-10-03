<script setup>
import { computed, ref } from 'vue';

/**
 * "Como configurar" de um provedor de IA: a chave fica SÓ no .env do
 * servidor — aqui o admin vê quais variáveis definir (nunca valores).
 */
const props = defineProps({
    provider: { type: Object, required: true }, // card de providerCards()
    // Dentro do drawer o título da seção já nomeia o bloco.
    showTitle: { type: Boolean, default: true },
    t: { type: Object, default: () => ({}) },
});

const copied = ref(false);

// Sem endereço (o Azure não tem padrão: um por recurso) o endereço vira obrigatório.
const needsUrl = computed(() => !props.provider.base_url);
const envLines = computed(() =>
    [
        `${props.provider.key_env}=`,
        needsUrl.value ? `${props.provider.base_url_env}=` : null,
        props.provider.lgpd?.region_env
            ? `${props.provider.lgpd.region_env}=${props.provider.lgpd.data_region ?? ''}`
            : null,
    ]
        .filter(Boolean)
        .join('\n'),
);
const optionalLines = computed(() =>
    [
        `${props.provider.model_env}=${props.provider.model ?? ''}`,
        needsUrl.value ? null : `${props.provider.base_url_env}=${props.provider.base_url ?? ''}`,
    ].filter(Boolean),
);
const note = computed(
    () =>
        ({ azure_openai: props.t.setup_azure_note, maritaca: props.t.setup_maritaca_note })[props.provider.code] ??
        null,
);

function tr(key, fallback, replace = {}) {
    let text = props.t[key] ?? fallback;
    for (const [k, v] of Object.entries(replace)) text = text.replace(`:${k}`, v);
    return text;
}

async function copy() {
    try {
        await navigator.clipboard.writeText(envLines.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <div class="provider-setup small" :data-setup-for="provider.code">
        <h6 v-if="showTitle" class="fw-semibold mb-2">
            {{ tr('setup_title', 'Configurar :provider', { provider: provider.label }) }}
        </h6>
        <p class="text-muted mb-2">{{ tr('setup_intro', 'A chave fica só no .env do servidor.') }}</p>
        <p v-if="note" class="mb-2" data-setup-note>
            <i class="ti ti-info-circle me-1 text-primary" aria-hidden="true"></i>{{ note }}
        </p>
        <ol class="ps-3 mb-2">
            <li class="mb-1">
                {{ tr('setup_step_key', 'Gere a chave no painel do provedor:') }}
                <a :href="provider.keys_url" target="_blank" rel="noopener noreferrer" class="text-break">{{
                    provider.keys_url
                }}</a>
            </li>
            <li class="mb-1">
                {{ tr('setup_step_env', 'Adicione ao .env do servidor:') }}
                <div v-if="needsUrl" class="text-muted">{{ t.setup_required_url }}</div>
                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                    <code class="bg-body-tertiary border rounded px-2 py-1 text-break" style="white-space: pre-line">{{
                        envLines
                    }}</code>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" @click="copy">
                        <i class="ti me-1" :class="copied ? 'ti-check' : 'ti-copy'" aria-hidden="true"></i
                        >{{ copied ? tr('copied', 'Copiado!') : tr('copy', 'Copiar') }}
                    </button>
                </div>
            </li>
            <li class="mb-1">{{ tr('setup_step_cache', 'Recarregue a configuração (php artisan config:cache).') }}</li>
            <li>{{ tr('setup_step_sync', 'Clique em "Sincronizar agora".') }}</li>
        </ol>
        <p class="text-muted mb-1">{{ tr('setup_optional', 'Opcionais: modelo e endereço da API.') }}</p>
        <pre
            class="bg-body-tertiary border rounded px-2 py-1 mb-0 text-wrap text-break"
        ><code>{{ optionalLines.join('\n') }}</code></pre>
    </div>
</template>
