<script setup>
import { computed, ref } from 'vue';

/**
 * Modelos do WhatsApp (Manager → WhatsApp): prévia de cada template no
 * visual do WhatsApp com valores de exemplo, texto no formato da Meta
 * ({{1}}, {{2}}…) para copiar no cadastro, variáveis e botões. Dados vêm de
 * WhatsAppController::templateCatalog() (config + lang, pt_BR e en).
 */
const props = defineProps({
    templates: { type: Array, default: () => [] },
    // Idiomas liberados para envio (WHATSAPP_TEMPLATE_LANGUAGES).
    enabledLanguages: { type: Array, default: () => ['pt_BR'] },
    t: { type: Object, required: true },
});

const LANGUAGES = ['pt_BR', 'en'];
const language = ref('pt_BR');
const GROUPS = ['patients', 'registration', 'saas'];

const groups = computed(() =>
    GROUPS.map((key) => ({
        key,
        label: props.t.template_groups?.[key] ?? key,
        items: props.templates.filter((tpl) => (tpl.group ?? 'patients') === key),
    })).filter((group) => group.items.length > 0),
);

const languageOff = computed(() => !props.enabledLanguages.includes(language.value));

function text(tpl) {
    return tpl.texts?.[language.value] ?? tpl.texts?.pt_BR ?? {};
}

function categoryLabel(category) {
    return props.t.template_categories?.[category] ?? category;
}

const BUTTON_ICONS = { quick_reply: 'ti-arrow-back-up', url: 'ti-external-link', otp: 'ti-copy' };

// Copiar (nome ou texto para a Meta), com "Copiado!" no próprio botão.
const copied = ref('');
let copiedTimer = null;

async function copy(value, id) {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = id;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => (copied.value = ''), 2000);
    } catch {
        // Navegador sem permissão de área de transferência: o texto continua visível para copiar à mão.
    }
}
</script>

<template>
    <div class="card" data-test="wa-templates">
        <div class="card-header bg-transparent d-flex flex-wrap align-items-center gap-2">
            <div class="flex-grow-1 min-w-0">
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-message-2 me-1 text-success" aria-hidden="true"></i>{{ t.templates_title }}
                </h5>
                <p class="small text-muted mb-0">{{ t.templates_hint }}</p>
            </div>
            <div class="btn-group btn-group-sm" role="group" :aria-label="t.template_preview_language">
                <button
                    v-for="lang in LANGUAGES"
                    :key="lang"
                    type="button"
                    class="btn"
                    :class="language === lang ? 'btn-primary' : 'btn-outline-primary'"
                    :aria-pressed="language === lang"
                    :data-test="`wa-lang-${lang}`"
                    @click="language = lang"
                >
                    {{ t.template_languages?.[lang] ?? lang }}
                </button>
            </div>
        </div>

        <div class="card-body">
            <div v-if="languageOff" class="alert alert-warning small py-2" data-test="wa-lang-off">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.template_language_off }}
            </div>

            <section v-for="group in groups" :key="group.key" class="mb-4" :data-group="group.key">
                <h6 class="text-uppercase small fw-semibold text-muted mb-2">
                    {{ group.label }} <span class="badge bg-light text-body ms-1">{{ group.items.length }}</span>
                </h6>

                <div class="wa-grid">
                    <article
                        v-for="tpl in group.items"
                        :key="tpl.key"
                        class="wa-card border rounded-3 p-3 d-flex flex-column gap-2"
                        :data-template="tpl.key"
                    >
                        <header class="d-flex align-items-start gap-2">
                            <div class="flex-grow-1 min-w-0">
                                <code class="d-block text-truncate" :title="tpl.name">{{ tpl.name }}</code>
                                <span
                                    class="badge mt-1"
                                    :class="
                                        tpl.category === 'AUTHENTICATION'
                                            ? 'bg-warning-subtle text-warning-emphasis'
                                            : 'bg-success-subtle text-success-emphasis'
                                    "
                                    >{{ categoryLabel(tpl.category) }}</span
                                >
                            </div>
                            <button
                                type="button"
                                class="btn btn-sm btn-light"
                                :title="copied === `${tpl.key}:name` ? t.template_copied : t.template_copy_name"
                                :aria-label="t.template_copy_name"
                                @click="copy(tpl.name, `${tpl.key}:name`)"
                            >
                                <i
                                    :class="['ti', copied === `${tpl.key}:name` ? 'ti-check text-success' : 'ti-copy']"
                                    aria-hidden="true"
                                ></i>
                            </button>
                        </header>

                        <!-- Prévia no visual do WhatsApp -->
                        <div class="wa-chat rounded-3 p-2">
                            <div class="wa-bubble" data-test="wa-preview">
                                <p class="mb-0 wa-text">{{ text(tpl).preview }}</p>
                                <p v-if="text(tpl).footer" class="mb-0 mt-1 wa-footer">{{ text(tpl).footer }}</p>
                            </div>
                            <div v-if="text(tpl).buttons?.length" class="wa-buttons" data-test="wa-buttons">
                                <span v-for="(button, i) in text(tpl).buttons" :key="i" class="wa-button">
                                    <i :class="['ti', BUTTON_ICONS[button.type] ?? 'ti-point']" aria-hidden="true"></i>
                                    {{ button.label }}
                                </span>
                            </div>
                        </div>

                        <p v-if="tpl.category === 'AUTHENTICATION'" class="small text-muted mb-0">
                            <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.template_auth_note }}
                        </p>
                        <p v-else-if="text(tpl).buttons?.some((b) => b.type === 'url')" class="small text-muted mb-0">
                            <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.template_url_note }}
                        </p>

                        <div class="small">
                            <div class="fw-semibold mb-1">{{ t.template_variables }}</div>
                            <ul v-if="text(tpl).params?.length" class="list-unstyled mb-0 wa-params">
                                <li v-for="param in text(tpl).params" :key="param.placeholder">
                                    <code>{{ param.placeholder }}</code> {{ param.label }}
                                    <span class="text-muted"
                                        >— {{ (t.template_example ?? ':value').replace(':value', param.example) }}</span
                                    >
                                </li>
                            </ul>
                            <p v-else class="text-muted mb-0">{{ t.template_no_variables }}</p>
                        </div>

                        <button
                            v-if="tpl.category !== 'AUTHENTICATION'"
                            type="button"
                            class="btn btn-sm btn-outline-secondary mt-auto align-self-start d-inline-flex align-items-center gap-1"
                            data-test="wa-copy-meta"
                            @click="copy(text(tpl).meta, `${tpl.key}:meta`)"
                        >
                            <i
                                :class="[
                                    'ti',
                                    copied === `${tpl.key}:meta` ? 'ti-check text-success' : 'ti-clipboard-text',
                                ]"
                                aria-hidden="true"
                            ></i>
                            {{ copied === `${tpl.key}:meta` ? t.template_copied : t.template_copy_meta }}
                        </button>
                    </article>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.wa-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
}

.wa-card {
    background: var(--bs-body-bg);
}

/* Fundo de conversa e balão de mensagem recebida (cores do WhatsApp). */
.wa-chat {
    background: #efeae2;
}

[data-bs-theme='dark'] .wa-chat {
    background: #0b141a;
}

.wa-bubble {
    background: #fff;
    color: #111b21;
    border-radius: 0 0.5rem 0.5rem 0.5rem;
    padding: 0.5rem 0.625rem;
    box-shadow: 0 1px 0.5px rgba(11, 20, 26, 0.13);
    font-size: 0.875rem;
}

[data-bs-theme='dark'] .wa-bubble {
    background: #202c33;
    color: #e9edef;
}

.wa-text {
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.wa-footer {
    font-size: 0.75rem;
    color: #667781;
}

[data-bs-theme='dark'] .wa-footer {
    color: #8696a0;
}

.wa-buttons {
    display: grid;
    gap: 2px;
    margin-top: 2px;
}

.wa-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    background: #fff;
    color: #027eb5;
    border-radius: 0.5rem;
    padding: 0.4rem 0.5rem;
    font-size: 0.8125rem;
    font-weight: 500;
    box-shadow: 0 1px 0.5px rgba(11, 20, 26, 0.13);
}

[data-bs-theme='dark'] .wa-button {
    background: #202c33;
    color: #53bdeb;
}

.wa-params li + li {
    margin-top: 0.125rem;
}

@media (max-width: 575.98px) {
    .wa-grid {
        grid-template-columns: 1fr;
    }
}
</style>
