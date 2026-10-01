/**
 * Utilitários da Conciliação de glosas (Pages/Panel/Financial/Tiss/*).
 * Regras de transição continuam no servidor (flags is_actionable,
 * can_be_submitted, can_be_resolved); aqui só apresentação.
 */

const DAY_MS = 86400000;

/** Ícone por status da glosa (badge com ícone + texto, nunca só cor). */
export const GLOSA_ICONS = {
    open: 'ti-alert-circle',
    appealed: 'ti-message-circle-up',
    partial_reversed: 'ti-arrow-back-up',
    reversed: 'ti-circle-check',
    maintained: 'ti-lock',
    cancelled: 'ti-circle-x',
};

/** badge-soft-* é legível nos dois temas; 'light' (Cancelada) vira secondary. */
export function softBadge(color) {
    return `badge-soft-${!color || color === 'light' ? 'secondary' : color}`;
}

/** Dias entre `today` e a data (YYYY-MM-DD), sem fuso: positivo = futuro. */
export function daysUntil(isoDate, today) {
    if (!isoDate || !today) return null;

    return Math.round((Date.parse(`${isoDate}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) / DAY_MS);
}

/**
 * Prazo para recorrer — só relevante enquanto a glosa está em aberto.
 * Cor + ícone + TEXTO ("vence em 3 dias", "vencida há 2 dias"), nunca só cor.
 *
 * @param {object} glosa
 * @param {string} today   YYYY-MM-DD da clínica (servidor)
 * @param {number} dueSoonDays janela do "vencendo"
 * @param {(key: string, params?: object) => string} tx
 */
export function deadlineBadge(glosa, today, dueSoonDays, tx) {
    if (!glosa?.is_actionable) return null;
    if (!glosa.deadline) return { cls: 'badge-soft-secondary', icon: 'ti-calendar-off', text: tx('deadline_none') };

    const days = daysUntil(glosa.deadline, today);
    if (days === null) return null;
    if (days < -1)
        return {
            cls: 'badge-soft-danger',
            icon: 'ti-alert-triangle',
            text: tx('deadline_overdue_days', { days: -days }),
        };
    if (days === -1) return { cls: 'badge-soft-danger', icon: 'ti-alert-triangle', text: tx('deadline_overdue_one') };
    if (days === 0) return { cls: 'badge-soft-danger', icon: 'ti-alarm', text: tx('deadline_today') };
    if (days === 1) return { cls: 'badge-soft-warning', icon: 'ti-clock', text: tx('deadline_tomorrow') };

    const soon = days <= Number(dueSoonDays ?? 5);

    return {
        cls: soon ? 'badge-soft-warning' : 'badge-soft-info',
        icon: soon ? 'ti-clock' : 'ti-calendar',
        text: tx('deadline_in_days', { days }),
    };
}

/** Recurso mais recente (a lista vem em ordem de criação). */
export function latestAppeal(glosa) {
    const appeals = glosa?.appeals ?? [];

    return appeals.length ? appeals[appeals.length - 1] : null;
}

/** Recurso que ainda aceita ação (marcar como enviado ou registrar decisão). */
export function activeAppeal(glosa) {
    return glosa?.appeals?.find((a) => a.can_be_submitted || a.can_be_resolved) ?? null;
}

/** Recurso enviado/em análise com prazo de resposta da operadora. */
export function awaitingResponse(appeal) {
    return ['submitted', 'in_analysis'].includes(appeal?.status) && Boolean(appeal?.deadline);
}

/**
 * Próxima ação da glosa (botão principal da linha): recorrer (aberta), marcar
 * o recurso como enviado, registrar a decisão — ou só ver os detalhes.
 *
 * @returns {{ kind: 'appeal'|'submit'|'resolve'|'details', appeal: object|null }}
 */
export function nextAction(glosa) {
    if (glosa?.is_actionable) return { kind: 'appeal', appeal: null };

    const appeal = activeAppeal(glosa);
    if (appeal?.can_be_submitted) return { kind: 'submit', appeal };
    if (appeal?.can_be_resolved) return { kind: 'resolve', appeal };

    return { kind: 'details', appeal: null };
}

/** Como o usuário reconhece a glosa: nº da guia, código GUI ou o código do motivo. */
export function glosaRef(glosa) {
    return glosa?.guide_number || glosa?.claim_code || glosa?.reason_code || '—';
}
