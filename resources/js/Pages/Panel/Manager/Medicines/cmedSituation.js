/**
 * Situação do medicamento na lista de preços da CMED (coluna "Situação na
 * CMED"), separada do status no catálogo (Ativo/Inativo = aparece no
 * receituário). Código calculado no servidor (MedicinesController::cmedSituation);
 * aqui só rótulo, explicação (tooltip) e cor — mesma regra em tabela, cards e
 * gaveta. Item curado (manual): null (não se aplica).
 */
const STYLES = {
    marketed: { label: 'cmed_marketed', hint: 'cmed_marketed_hint', icon: 'ti-building-store', cls: 'badge-soft-info' },
    not_marketed: {
        label: 'not_marketed',
        hint: 'not_marketed_hint',
        icon: 'ti-alert-triangle',
        cls: 'badge-soft-warning border border-warning',
    },
    left_list: {
        label: 'cmed_left_list',
        hint: 'cmed_left_list_hint',
        icon: 'ti-archive',
        cls: 'badge-soft-secondary',
    },
};

export function cmedSituation(medicine, t) {
    const style = STYLES[medicine?.cmed_situation];
    if (!style) return null;

    return {
        code: medicine.cmed_situation,
        label: t[style.label],
        hint: t[style.hint],
        icon: style.icon,
        cls: style.cls,
    };
}
