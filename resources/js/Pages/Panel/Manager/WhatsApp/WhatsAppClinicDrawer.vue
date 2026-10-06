<script setup>
import { computed } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { automationText, fill, sendingBadge } from './clinicStatus.js';

/**
 * Detalhes do WhatsApp de uma clínica — mesmo drawer de Manager →
 * Medicamentos. Os dados já vêm na linha da lista (sem requisição extra);
 * só a verificação do app próprio chama a Gupshup (botão).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    clinic: { type: Object, default: null },
    // Resultado da verificação do app próprio ({ ok, healthy, error }) e se está verificando.
    health: { type: Object, default: null },
    checking: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

defineEmits(['close', 'configure', 'verify']);

const { number, dateTime } = useLocaleFormat();

const c = computed(() => props.clinic);
const ui = computed(() => props.t.ui ?? {});
const setting = computed(() => c.value?.setting ?? null);
const badge = computed(() => (c.value ? sendingBadge(c.value, ui.value) : null));

const automations = computed(() =>
    c.value
        ? [
              [props.t.toggles.confirmation_enabled, automationText(c.value, 'confirmation', ui.value)],
              [props.t.toggles.survey_enabled, automationText(c.value, 'survey', ui.value)],
          ]
        : [],
);

const stats = computed(() => {
    const s = c.value?.stats;
    if (!s) return [];

    return [
        ['confirmations_sent', number(s.confirmations_sent)],
        ['confirmations_answered', number(s.confirmations_answered)],
        ['surveys_sent', number(s.surveys_sent)],
        ['surveys_answered', number(s.surveys_answered)],
        [
            'survey_average',
            s.survey_average ? fill(ui.value.survey_average_value, { value: number(s.survey_average, 1) }) : '—',
        ],
        ['delivered', number(s.delivered)],
        ['read', number(s.read)],
        ['failed', number(s.failed)],
    ].map(([key, value]) => [props.t.stats[key], value, key]);
});

const hasMessages = computed(() => {
    const s = c.value?.stats;

    return !!s && Object.values(s).some((value) => Number(value) > 0);
});
</script>

<template>
    <OffcanvasPanel :open="open" :width="540" :close-label="t.close" @close="$emit('close')">
        <template #header>
            <div class="min-w-0">
                <h5 class="mb-0 fw-semibold text-break">
                    <i class="ti ti-brand-whatsapp me-2 text-success" aria-hidden="true"></i>{{ c?.name }}
                </h5>
                <div v-if="c" class="mt-1 d-flex gap-2 align-items-center flex-wrap">
                    <span v-if="c.code" class="text-muted small">{{ c.code }}</span>
                    <span class="badge rounded fs-12" :class="badge.cls" data-test="drawer-sending"
                        ><i :class="`${badge.icon} me-1`" aria-hidden="true"></i>{{ badge.label }}</span
                    >
                </div>
            </div>
        </template>

        <template v-if="c" #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ t.close }}</button>
            <button type="button" class="btn btn-primary" data-test="drawer-configure" @click="$emit('configure', c)">
                <i class="ti ti-settings me-1" aria-hidden="true"></i>{{ t.manager.configure }}
            </button>
        </template>

        <template v-if="c">
            <!-- Situação do envio -->
            <div class="wad-section">
                <div class="wad-section__title">
                    <i class="ti ti-send me-1" aria-hidden="true"></i> {{ ui.detail_sending }}
                </div>
                <p class="small mb-2" data-test="drawer-sending-hint">{{ badge.hint }}</p>
                <div v-if="!setting" class="alert alert-light border small mb-0" data-test="drawer-no-setting">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ ui.detail_no_setting }}
                </div>
                <div v-else class="wad-table">
                    <div class="wad-row">
                        <span class="wad-label">{{ ui.detail_integration }}</span
                        ><span class="wad-value">{{ setting.active ? ui.global_ok : ui.global_off }}</span>
                    </div>
                    <div class="wad-row">
                        <span class="wad-label">{{ ui.detail_number }}</span
                        ><span class="wad-value">{{ badge.label }}</span>
                    </div>
                </div>
            </div>

            <!-- App próprio -->
            <div v-if="setting" class="wad-section" data-test="drawer-own-app">
                <div class="wad-section__title">
                    <i class="ti ti-brand-whatsapp me-1" aria-hidden="true"></i> {{ ui.detail_own_app }}
                </div>
                <p v-if="!setting.has_app" class="small text-muted mb-0">{{ ui.detail_no_own_app }}</p>
                <template v-else>
                    <div class="wad-table mb-2">
                        <div class="wad-row">
                            <span class="wad-label">{{ ui.detail_app_id }}</span
                            ><span class="wad-value"
                                ><code>{{ setting.app_id }}</code></span
                            >
                        </div>
                        <div class="wad-row">
                            <span class="wad-label">{{ ui.detail_webhook }}</span>
                            <span class="wad-value">
                                <span v-if="setting.webhook_ok" class="badge badge-soft-success rounded">{{
                                    t.webhook.ok
                                }}</span>
                                <span v-else class="badge badge-soft-warning rounded">{{ t.webhook.pending }}</span>
                                <span v-if="setting.webhook_since" class="ms-1">{{
                                    dateTime(setting.webhook_since)
                                }}</span>
                            </span>
                        </div>
                        <div class="wad-row">
                            <span class="wad-label">{{ ui.detail_health }}</span>
                            <span class="wad-value" aria-live="polite" data-test="drawer-health">
                                <span v-if="checking"
                                    ><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                                    >{{ t.connection.testing }}</span
                                >
                                <span v-else-if="health?.ok && health.healthy" class="text-success">{{
                                    t.connection.healthy
                                }}</span>
                                <span v-else-if="health?.ok" class="text-warning-emphasis">{{
                                    t.connection.unhealthy
                                }}</span>
                                <span v-else-if="health" class="text-danger">{{ health.error }}</span>
                                <span v-else class="text-muted">{{ ui.health_unchecked }}</span>
                            </span>
                        </div>
                    </div>
                    <code class="wad-code d-block border rounded p-2 text-break mb-2">{{ setting.webhook_url }}</code>
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        :disabled="checking"
                        data-test="drawer-verify"
                        @click="$emit('verify', c)"
                    >
                        <i class="ti ti-plug me-1" aria-hidden="true"></i>{{ t.connection.test }}
                    </button>
                </template>
            </div>

            <!-- Automações -->
            <div v-if="setting" class="wad-section">
                <div class="wad-section__title">
                    <i class="ti ti-message-check me-1" aria-hidden="true"></i> {{ ui.detail_automations }}
                </div>
                <div class="wad-table">
                    <div v-for="[label, value] in automations" :key="label" class="wad-row">
                        <span class="wad-label">{{ label }}</span
                        ><span class="wad-value" :class="value ? 'text-success' : ''">{{ value ?? ui.off }}</span>
                    </div>
                </div>
            </div>

            <!-- Estatísticas de 30 dias -->
            <div v-if="setting" class="wad-section" data-test="drawer-stats">
                <div class="wad-section__title">
                    <i class="ti ti-chart-bar me-1" aria-hidden="true"></i> {{ ui.detail_stats }}
                </div>
                <p v-if="!hasMessages" class="small text-muted mb-0">{{ ui.detail_no_stats }}</p>
                <div v-else class="wad-table">
                    <div v-for="[label, value, key] in stats" :key="key" class="wad-row">
                        <span class="wad-label">{{ label }}</span
                        ><span class="wad-value" :class="{ 'text-danger': key === 'failed' && value !== '0' }">{{
                            value
                        }}</span>
                    </div>
                </div>
            </div>

            <!-- Descadastrados -->
            <div v-if="setting" class="wad-section">
                <div class="wad-section__title">
                    <i class="ti ti-user-off me-1" aria-hidden="true"></i> {{ ui.detail_opt_outs }}
                </div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="fs-5 fw-semibold" data-test="drawer-opt-outs">{{
                        number(setting.opt_outs ?? 0)
                    }}</span>
                    <span class="small text-muted">{{ ui.detail_opt_outs_hint }}</span>
                </div>
            </div>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.wad-section {
    margin-bottom: 1.5rem;
}
.wad-section__title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.wad-table {
    display: grid;
    gap: 0.375rem;
}
.wad-row {
    display: grid;
    grid-template-columns: 170px 1fr;
    gap: 0.5rem;
    font-size: 0.875rem;
    align-items: baseline;
}
.wad-label {
    font-weight: 600;
    color: var(--bs-body-color);
}
.wad-value {
    color: var(--bs-secondary-color);
    word-break: break-word;
}
.wad-code {
    font-size: 0.7rem;
    background: var(--bs-tertiary-bg);
}
[data-bs-theme='dark'] .wad-code {
    background: var(--bs-secondary-bg);
}
@media (max-width: 575.98px) {
    .wad-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
}
</style>
