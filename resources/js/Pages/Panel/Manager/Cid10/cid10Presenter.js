/**
 * Textos derivados de uma linha do catálogo CID-10 (tabela, cards e
 * detalhes usam os mesmos).
 */

/** Dica do uso: "3 prontuário(s) · 1 exame(s) · 2 clínica(s)". */
export function usageHint(code, t, number) {
    return (t.usage_hint ?? '')
        .replace(':records', number(code.usage?.records ?? 0))
        .replace(':exams', number(code.usage?.exams ?? 0))
        .replace(':clinics', number(code.usage?.clinics ?? 0));
}

/**
 * Em uso (prontuário, exame ou vínculo de clínica) pelo agregado da tela —
 * excluir e trocar o código ficam bloqueados (o servidor confere ao vivo).
 */
export function usageLocked(code) {
    return (code.usage?.total ?? 0) + (code.usage?.links ?? 0) > 0;
}
