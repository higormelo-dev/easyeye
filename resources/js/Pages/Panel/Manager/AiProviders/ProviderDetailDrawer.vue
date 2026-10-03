<script setup>
import { computed, ref, watch } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import ProviderLgpdSection from './ProviderLgpdSection.vue';
import ProviderSetupHelp from './ProviderSetupHelp.vue';
import { lgpdBadge, providerRole, providerStatus } from './providerStatus.js';

/**
 * Detalhes de um provedor de IA (mesmo drawer das demais listagens do
 * manager): chave (só se está definida + 4 últimos caracteres — o valor fica
 * no .env), modelo usado (escolha entre os modelos ativos com preço),
 * proteção de dados (LGPD), endereço da API, como configurar e teste de conexão.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    provider: { type: Object, default: null },
    modelOptions: { type: Array, default: () => [] }, // modelos ativos com preço deste provedor
    testing: { type: Boolean, default: false },
    testResult: { type: Object, default: null }, // {ok, message, latency_ms}
    lgpd: { type: Object, default: () => ({ checked_at: null, mechanisms: [] }) },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'test', 'saved']);

const p = computed(() => props.provider);
const status = computed(() => (p.value ? providerStatus(p.value, props.t) : null));
const role = computed(() => (p.value ? providerRole(p.value, props.t) : null));
const lgpdSeal = computed(() => (p.value ? lgpdBadge(p.value, props.t) : null));

// Modelo: '' = padrão do servidor (.env). Só o que o seletor consegue mostrar.
const model = ref('');
const savingModel = ref(false);

watch(
    () => [props.open, p.value?.code, p.value?.model, p.value?.model_source, props.modelOptions.join('|')],
    () => {
        const current = p.value?.model_source === 'panel' ? p.value.model : '';
        model.value = props.modelOptions.includes(current) ? current : '';
    },
    { immediate: true },
);

const currentChoice = computed(() =>
    p.value?.model_source === 'panel' && props.modelOptions.includes(p.value.model) ? p.value.model : '',
);
const modelChanged = computed(() => model.value !== currentChoice.value);

function tr(key, fallback, replace = {}) {
    let text = props.t[key] ?? fallback;
    for (const [k, v] of Object.entries(replace)) text = text.replace(`:${k}`, v);
    return text;
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function saveModel() {
    if (!p.value || savingModel.value || !modelChanged.value) return;
    savingModel.value = true;
    try {
        const res = await fetch(route('manager.ai-providers.model', p.value.code), {
            method: 'PATCH',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ model: model.value || null }),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            window.showErrorToast?.(json.message ?? Object.values(json.errors ?? {}).flat()[0] ?? '');
            return;
        }
        window.showSuccessToast?.(json.message);
        emit('saved');
    } finally {
        savingModel.value = false;
    }
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="560" :close-label="t.close" @close="$emit('close')">
        <template #header>
            <div class="min-w-0">
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-sparkles me-2 text-primary" aria-hidden="true"></i>{{ p?.label }}
                </h5>
                <div v-if="p" class="mt-1 d-flex gap-2 align-items-center flex-wrap">
                    <span class="badge rounded fs-12 fw-medium" :class="status.cls" :title="status.hint"
                        ><i :class="`ti ${status.icon} me-1`" aria-hidden="true"></i>{{ status.label }}</span
                    >
                    <span v-if="role" class="badge badge-soft-primary rounded fs-12 fw-medium"
                        ><i :class="`ti ${role.icon} me-1`" aria-hidden="true"></i>{{ role.label }}</span
                    >
                    <span
                        v-if="lgpdSeal"
                        class="badge rounded fs-12 fw-medium"
                        :class="lgpdSeal.cls"
                        :title="lgpdSeal.hint"
                        data-drawer-lgpd
                        ><i :class="`ti ${lgpdSeal.icon} me-1`" aria-hidden="true"></i>{{ lgpdSeal.label }}</span
                    >
                    <span v-if="p.compatible" class="badge badge-soft-info rounded fs-12 fw-medium">{{
                        t.driver_compatible
                    }}</span>
                </div>
            </div>
        </template>

        <template v-if="p" #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ tr('close', 'Fechar') }}</button>
            <button
                type="button"
                class="btn btn-outline-primary"
                :disabled="!p.configured || testing"
                data-drawer-test
                @click="$emit('test', p.code)"
            >
                <span v-if="testing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-plug-connected me-1" aria-hidden="true"></i
                >{{ tr('test_connection', 'Testar conexão') }}
            </button>
        </template>

        <template v-if="p">
            <!-- Chave de API -->
            <div class="apd-section">
                <div class="apd-section__title">
                    <i class="ti ti-key me-1"></i> {{ tr('section_key', 'Chave de API') }}
                </div>
                <div class="apd-table">
                    <div class="apd-row">
                        <span class="apd-label">{{ tr('field_key_status', 'Situação') }}</span>
                        <span class="apd-value">
                            <span
                                v-if="p.has_key"
                                class="badge badge-soft-success rounded text-success border border-success fs-12"
                                :title="t.key_hint_title"
                                data-drawer-key
                                >{{ tr('key_defined', 'Definida') }} · {{ p.key_hint }}</span
                            >
                            <span v-else class="badge badge-soft-secondary rounded fs-12">{{
                                tr('key_missing', 'Não definida')
                            }}</span>
                        </span>
                    </div>
                    <div class="apd-row">
                        <span class="apd-label">{{ tr('field_env_var', 'Variável no .env') }}</span>
                        <span class="apd-value"
                            ><code>{{ p.key_env }}</code></span
                        >
                    </div>
                    <div class="apd-row">
                        <span class="apd-label">{{ tr('field_key_where', 'Onde gerar a chave') }}</span>
                        <span class="apd-value"
                            ><a :href="p.keys_url" target="_blank" rel="noopener noreferrer" class="text-break">{{
                                p.keys_url
                            }}</a></span
                        >
                    </div>
                </div>
            </div>

            <!-- Modelo usado -->
            <div class="apd-section">
                <div class="apd-section__title">
                    <i class="ti ti-cpu me-1"></i> {{ tr('section_model', 'Modelo usado') }}
                </div>
                <div class="apd-table mb-2">
                    <div class="apd-row">
                        <span class="apd-label">{{ tr('field_model_current', 'Modelo em uso') }}</span>
                        <span class="apd-value"
                            ><code>{{ p.model ?? '—' }}</code></span
                        >
                    </div>
                    <div v-if="p.model_source" class="apd-row">
                        <span class="apd-label">{{ tr('field_model_source', 'Definido por') }}</span>
                        <span class="apd-value">{{
                            p.model_source === 'panel'
                                ? tr('model_source_panel', 'Escolhido no painel')
                                : `${tr('model_source_env', 'Padrão do .env')} (${p.model_env})`
                        }}</span>
                    </div>
                </div>
                <div
                    v-if="status.code === 'no_price' || status.code === 'unlisted'"
                    class="alert alert-warning py-2 small mb-2"
                >
                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ status.hint }}
                </div>
                <template v-if="p.configured">
                    <div v-if="modelOptions.length" class="d-flex flex-wrap align-items-end gap-2">
                        <div class="flex-grow-1">
                            <label class="form-label small fw-semibold mb-1" for="apd-model">{{
                                tr('model_select_label', 'Modelo deste provedor')
                            }}</label>
                            <select id="apd-model" v-model="model" class="form-select" data-drawer-model>
                                <option value="">{{ tr('model_env_fallback', 'Padrão do servidor (.env)') }}</option>
                                <option v-for="m in modelOptions" :key="m" :value="m">{{ m }}</option>
                            </select>
                        </div>
                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="!modelChanged || savingModel"
                            data-drawer-model-save
                            @click="saveModel"
                        >
                            <span
                                v-if="savingModel"
                                class="spinner-border spinner-border-sm me-1"
                                aria-hidden="true"
                            ></span>
                            {{ tr('model_save', 'Salvar modelo') }}
                        </button>
                    </div>
                    <p v-else class="text-muted small mb-0">{{ t.model_no_options }}</p>
                    <p class="form-text mb-0">{{ t.model_hint }}</p>
                </template>
            </div>

            <!-- Proteção de dados (LGPD) -->
            <div v-if="p.lgpd" class="apd-section">
                <div class="apd-section__title">
                    <i class="ti ti-shield-lock me-1"></i> {{ tr('section_lgpd', 'Proteção de dados (LGPD)') }}
                </div>
                <ProviderLgpdSection :provider="p" :lgpd="lgpd" :t="t" @saved="$emit('saved')" />
            </div>

            <!-- Endereço da API -->
            <div class="apd-section">
                <div class="apd-section__title">
                    <i class="ti ti-world me-1"></i> {{ tr('section_endpoint', 'Endereço da API') }}
                </div>
                <div class="apd-table">
                    <div class="apd-row">
                        <span class="apd-label">{{ tr('field_url', 'Endereço') }}</span>
                        <span class="apd-value text-break">{{ p.base_url ?? '—' }}</span>
                    </div>
                    <div class="apd-row">
                        <span class="apd-label">{{ tr('field_env_var', 'Variável no .env') }}</span>
                        <span class="apd-value"
                            ><code>{{ p.base_url_env }}</code></span
                        >
                    </div>
                </div>
                <div v-if="p.base_url && !p.base_url_secure" class="text-danger small mt-2" data-drawer-insecure>
                    <i class="ti ti-lock-open me-1" aria-hidden="true"></i>{{ t.insecure_url }}
                </div>
            </div>

            <!-- Teste de conexão -->
            <div class="apd-section">
                <div class="apd-section__title">
                    <i class="ti ti-plug-connected me-1"></i> {{ tr('section_test', 'Teste de conexão') }}
                </div>
                <div
                    v-if="testResult"
                    class="small"
                    :class="testResult.ok ? 'text-success' : 'text-danger'"
                    role="status"
                    data-drawer-test-result
                >
                    <i :class="`ti ${testResult.ok ? 'ti-circle-check' : 'ti-circle-x'} me-1`" aria-hidden="true"></i
                    >{{ testResult.message }}
                    <span v-if="testResult.latency_ms">({{ testResult.latency_ms }} ms)</span>
                </div>
                <p v-else class="text-muted small mb-0">{{ tr('test_never', 'Ainda não testado nesta visita.') }}</p>
            </div>

            <!-- Como configurar -->
            <div class="apd-section mb-0">
                <div class="apd-section__title">
                    <i class="ti ti-settings me-1"></i> {{ tr('section_setup', 'Como configurar') }}
                </div>
                <ProviderSetupHelp :provider="p" :show-title="false" :t="t" />
            </div>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.apd-section {
    margin-bottom: 1.5rem;
}
.apd-section__title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.apd-table {
    display: grid;
    gap: 0.375rem;
}
.apd-row {
    display: grid;
    grid-template-columns: 170px 1fr;
    gap: 0.5rem;
    font-size: 0.875rem;
    align-items: baseline;
}
.apd-label {
    font-weight: 600;
    color: var(--bs-body-color);
}
.apd-value {
    color: var(--bs-secondary-color);
    word-break: break-word;
}
@media (max-width: 575.98px) {
    .apd-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
}
</style>
