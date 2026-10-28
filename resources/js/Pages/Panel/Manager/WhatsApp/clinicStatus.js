/**
 * Manager → WhatsApp: textos e selos derivados de uma linha de clínica
 * (`sending` vem do servidor: own | global | none — mesma regra de
 * WhatsAppSetting::canSend()). Compartilhado por tabela, cards e gaveta.
 */

export function fill(text, values = {}) {
    return Object.entries(values).reduce((acc, [key, value]) => acc.replaceAll(`:${key}`, value ?? ''), text ?? '');
}

const SENDING_STYLE = {
    own: { cls: 'badge-soft-success', icon: 'ti ti-brand-whatsapp' },
    global: { cls: 'badge-soft-info', icon: 'ti ti-building-broadcast-tower' },
    none: { cls: 'bg-body-secondary text-body-secondary', icon: 'ti ti-message-off' },
};

/** Por que a clínica não envia (só para `sending === 'none'`). */
export function noneReason(clinic) {
    const setting = clinic?.setting;
    if (!setting) return 'unconfigured';
    if (!setting.active) return setting.has_app ? 'own_inactive' : 'inactive';

    return 'global_unavailable';
}

/** Selo "número usado": rótulo, classe, ícone e explicação (dica/leitor de tela). */
export function sendingBadge(clinic, ui) {
    const key = SENDING_STYLE[clinic?.sending] ? clinic.sending : 'none';
    const hint = key === 'none' ? (ui.none_reason?.[noneReason(clinic)] ?? '') : (ui.sending_hint?.[key] ?? '');

    return { key, label: ui.sending?.[key] ?? key, hint, ...SENDING_STYLE[key] };
}

/** "24 h antes" ou "Desligada" (confirmação/pesquisa só valem com a integração ativa). */
export function automationText(clinic, kind, ui) {
    const setting = clinic?.setting;
    const on = kind === 'confirmation' ? setting?.confirmation_enabled : setting?.survey_enabled;
    if (!setting?.active || !on) return null;

    return kind === 'confirmation'
        ? fill(ui.hours_before, { hours: setting.confirmation_hours_before })
        : fill(ui.hours_after, { hours: setting.survey_delay_hours });
}

/** Enviadas e respondidas (confirmações + pesquisas) nos últimos 30 dias. */
export function sentAnswered(clinic) {
    const s = clinic?.stats;
    if (!s) return null;

    return {
        sent: (s.confirmations_sent ?? 0) + (s.surveys_sent ?? 0),
        answered: (s.confirmations_answered ?? 0) + (s.surveys_answered ?? 0),
    };
}
