<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { fill } from './clinicStatus.js';

/**
 * Faixa de situação ÚNICA do WhatsApp (no lugar dos alertas empilhados):
 * modo (envio real / simulação) — parceiro Gupshup (credenciais, modo de
 * autenticação, validade do token universal) — número do EasyEye (app,
 * ativo, webhook, saúde). Problemas em destaque (vermelho/amarelo) com a
 * ação direta; tudo certo = uma linha verde discreta.
 */
const props = defineProps({
    simulated: { type: Boolean, default: true },
    partner: { type: Object, default: () => ({}) },
    global: { type: Object, default: null },
    // Última verificação do app do número do EasyEye ({ ok, healthy, error }) ou null.
    health: { type: Object, default: null },
    checking: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

defineEmits(['configure', 'register-webhook', 'verify']);

const { date } = useLocaleFormat();
const ui = computed(() => props.t.ui ?? {});
const m = computed(() => props.t.manager ?? {});

const utDate = computed(() => (props.partner?.ut_expires_at ? date(props.partner.ut_expires_at) : ''));

const authLabel = computed(() =>
    props.partner?.auth_mode === 'universal' ? m.value.auth_universal : m.value.auth_partner_token,
);

const healthBad = computed(() => !!props.health && (!props.health.ok || !props.health.healthy));

/** Problemas, do mais grave ao mais leve (cada um com sua ação, quando há). */
const problems = computed(() => {
    const list = [];

    if (props.simulated) {
        list.push({ key: 'mock', tone: 'warning', icon: 'ti-flask', text: m.value.mock_warning, test: 'mock-warning' });
    } else if (!props.partner?.configured) {
        list.push({
            key: 'partner',
            tone: 'danger',
            icon: 'ti-plug-x',
            text: m.value.partner_missing,
            test: 'partner-missing',
        });
    }

    if (props.partner?.ut_expired || props.partner?.ut_expiring) {
        list.push({
            key: 'ut',
            tone: props.partner.ut_expired ? 'danger' : 'warning',
            icon: 'ti-key',
            text: props.partner.ut_expired
                ? fill(m.value.ut_expired, { date: utDate.value })
                : fill(m.value.ut_expiring, { date: utDate.value, days: props.partner.ut_days_left }),
            test: 'ut-expiring',
        });
    }

    if (!props.global?.has_app) {
        list.push({
            key: 'global',
            tone: 'danger',
            icon: 'ti-building-broadcast-tower',
            text: ui.value.global_missing,
            action: 'configure',
            test: 'global-missing',
        });
    } else {
        if (!props.global.active) {
            list.push({
                key: 'global-inactive',
                tone: 'warning',
                icon: 'ti-building-broadcast-tower',
                text: ui.value.global_inactive,
                action: 'configure',
                test: 'global-inactive',
            });
        }
        if (!props.global.webhook_ok) {
            list.push({
                key: 'webhook',
                tone: 'warning',
                icon: 'ti-webhook',
                text: ui.value.global_webhook_off,
                action: 'register-webhook',
                test: 'global-webhook-off',
            });
        }
        if (healthBad.value) {
            list.push({
                key: 'health',
                tone: props.health.ok ? 'warning' : 'danger',
                icon: 'ti-heart-rate-monitor',
                text: props.health.ok
                    ? ui.value.global_unhealthy
                    : fill(ui.value.global_check_failed, { error: props.health.error ?? '' }),
                action: 'verify',
                test: 'global-unhealthy',
            });
        }
    }

    return list;
});

const allOk = computed(() => problems.value.length === 0);

const actionLabel = (action) =>
    ({
        configure: ui.value.action_configure,
        'register-webhook': ui.value.action_register,
        verify: ui.value.action_verify,
    })[action];

const actionIcon = (action) =>
    ({ configure: 'ti-settings', 'register-webhook': 'ti-rotate', verify: 'ti-plug' })[action];

/** Resumo das três frentes (sempre visível, em texto curto). */
const globalSummary = computed(() => {
    if (!props.global?.has_app) return ui.value.global_not_set;

    const parts = [
        props.global.active ? ui.value.global_ok : ui.value.global_off,
        props.global.webhook_ok ? ui.value.webhook_ok : ui.value.webhook_off,
    ];
    if (props.health) parts.push(healthBad.value ? ui.value.health_bad : ui.value.health_ok);

    return parts.join(' · ');
});

const partnerSummary = computed(() => {
    if (!props.partner?.configured) return ui.value.partner_not_set;

    return utDate.value ? `${authLabel.value} · ${fill(ui.value.ut_until, { date: utDate.value })}` : authLabel.value;
});
</script>

<template>
    <section
        class="wa-status mb-3"
        :class="allOk ? 'wa-status--ok' : 'wa-status--problems'"
        :aria-label="ui.situation_label"
        data-test="wa-status"
    >
        <!-- Problemas (cada um com a ação direta) -->
        <ul v-if="!allOk" class="list-unstyled mb-0">
            <li
                v-for="item in problems"
                :key="item.key"
                class="wa-status__problem d-flex flex-wrap align-items-center gap-2"
                :class="`wa-status__problem--${item.tone}`"
                :data-test="item.test"
                :data-tone="item.tone"
            >
                <i :class="`ti ${item.icon} wa-status__icon`" aria-hidden="true"></i>
                <span class="wa-status__text"
                    ><span class="visually-hidden"
                        >{{ item.tone === 'danger' ? ui.problem_danger : ui.problem_warning }}: </span
                    >{{ item.text }}</span
                >
                <button
                    v-if="item.action"
                    type="button"
                    class="btn btn-sm text-nowrap"
                    :class="item.tone === 'danger' ? 'btn-danger' : 'btn-warning'"
                    :disabled="(item.action === 'register-webhook' && busy) || (item.action === 'verify' && checking)"
                    :data-test="`status-action-${item.action}`"
                    @click="$emit(item.action)"
                >
                    <span
                        v-if="(item.action === 'register-webhook' && busy) || (item.action === 'verify' && checking)"
                        class="spinner-border spinner-border-sm me-1"
                        aria-hidden="true"
                    ></span>
                    <i v-else :class="`ti ${actionIcon(item.action)} me-1`" aria-hidden="true"></i
                    >{{ actionLabel(item.action) }}
                </button>
            </li>
        </ul>

        <!-- Resumo compacto: modo · parceiro · número do EasyEye -->
        <div class="wa-status__summary d-flex flex-wrap align-items-center gap-2 small">
            <span v-if="allOk" class="fw-semibold text-success" data-test="status-ok">
                <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ ui.all_ok }}
            </span>
            <span class="wa-status__chip" :class="simulated ? 'text-warning-emphasis' : 'text-success'">
                <i :class="`ti ${simulated ? 'ti-flask' : 'ti-send'} me-1`" aria-hidden="true"></i
                >{{ simulated ? ui.mode_simulated : ui.mode_real }}
            </span>
            <span class="wa-status__sep" aria-hidden="true">·</span>
            <span
                class="wa-status__chip"
                :class="{ 'text-danger': !partner?.configured && !simulated }"
                :data-test="!simulated && partner?.configured ? 'partner-ok' : 'partner-summary'"
            >
                <i class="ti ti-key me-1" aria-hidden="true"></i>{{ partner?.configured ? m.partner_ok : ui.partner }}:
                {{ partnerSummary }}
            </span>
            <span class="wa-status__sep" aria-hidden="true">·</span>
            <span class="wa-status__chip" data-test="global-summary">
                <i class="ti ti-building-broadcast-tower me-1" aria-hidden="true"></i>{{ ui.global }}:
                {{ globalSummary }}
            </span>
            <button
                v-if="global?.has_app && !healthBad"
                type="button"
                class="btn btn-link btn-sm text-decoration-none p-0 ms-sm-auto"
                :disabled="checking"
                data-test="status-verify"
                @click="$emit('verify')"
            >
                <span v-if="checking" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-plug me-1" aria-hidden="true"></i
                >{{ health ? ui.action_verify : `${ui.action_verify} (${ui.health_unchecked})` }}
            </button>
        </div>
    </section>
</template>

<style scoped>
.wa-status {
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    background: var(--bs-body-bg);
}
.wa-status--ok {
    border-color: rgba(var(--bs-success-rgb), 0.35);
    background: rgba(var(--bs-success-rgb), 0.05);
}
.wa-status__problem {
    padding: 0.55rem 0.875rem;
    border-bottom: 1px solid var(--bs-border-color);
    border-left: 4px solid transparent;
}
.wa-status__problem:first-child {
    border-top-left-radius: var(--bs-border-radius);
}
.wa-status__problem--danger {
    border-left-color: var(--bs-danger);
    background: rgba(var(--bs-danger-rgb), 0.07);
}
.wa-status__problem--warning {
    border-left-color: var(--bs-warning);
    background: rgba(var(--bs-warning-rgb), 0.1);
}
.wa-status__problem--danger .wa-status__icon {
    color: var(--bs-danger);
}
.wa-status__problem--warning .wa-status__icon {
    color: var(--bs-warning-text-emphasis);
}
.wa-status__icon {
    font-size: 1.15rem;
}
.wa-status__text {
    /* base 0: o texto longo quebra ao lado do ícone, não numa linha nova */
    flex: 1 1 0;
    min-width: 12rem;
    font-size: 0.85rem;
}
.wa-status__summary {
    padding: 0.5rem 0.875rem;
    color: var(--bs-secondary-color);
}
.wa-status__sep {
    opacity: 0.6;
}

[data-bs-theme='dark'] .wa-status--ok {
    background: rgba(var(--bs-success-rgb), 0.1);
}
[data-bs-theme='dark'] .wa-status__problem--danger {
    background: rgba(var(--bs-danger-rgb), 0.16);
}
[data-bs-theme='dark'] .wa-status__problem--warning {
    background: rgba(var(--bs-warning-rgb), 0.14);
}

@media (max-width: 575.98px) {
    .wa-status__sep {
        display: none;
    }
    .wa-status__summary {
        flex-direction: column;
        align-items: flex-start !important;
    }
    .wa-status__problem .btn {
        width: 100%;
    }
}
</style>
