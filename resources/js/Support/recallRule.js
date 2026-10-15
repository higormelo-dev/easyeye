/**
 * Utilitários dos componentes de REGRA de protocolo de retorno (Fase 6): textos por
 * caminho, CID, e o rótulo do intervalo. Os componentes de regra são compartilhados
 * entre o Manager, a página do Revisor Clínico e a tela de adoção da clínica; cada
 * página passa o seu dicionário de textos (`recall_rule` + rótulos dos enums).
 */

/**
 * Cria `tx(path, params, fallback)` sobre um dicionário aninhado (objeto ou getter).
 * `:nome` é substituído por params.nome; um texto "a|b" escolhe `a` quando params.count
 * é 1 e `b` nos demais casos (plural). Caminho que não existe aparece como o próprio
 * caminho — erro visível em vez de campo vazio.
 */
export function makeTx(textsOrGetter) {
    return function tx(path, params = {}, fallback = '') {
        const texts = typeof textsOrGetter === 'function' ? textsOrGetter() : textsOrGetter;
        let value = String(path)
            .split('.')
            .reduce((acc, key) => (acc && typeof acc === 'object' ? acc[key] : undefined), texts ?? {});

        if (typeof value !== 'string') return fallback || String(path);

        if (value.includes('|') && Object.prototype.hasOwnProperty.call(params, 'count')) {
            const [one, other] = value.split('|');
            value = Number(params.count) === 1 ? one : (other ?? one);
        }

        return value.replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, (match, name) =>
            Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match,
        );
    };
}

/** "H401" → "H40.1" (o ponto depois do 3º caractere, como o CID-10 se escreve). */
export function cidLabel(code) {
    const value = String(code ?? '');
    return value.length > 3 ? `${value.slice(0, 3)}.${value.slice(3)}` : value;
}

/**
 * Texto digitado ("h40.1, H40.2  e103†") → lista de códigos no formato do protocolo
 * (maiúsculo, sem ponto/†/*, sem repetição). Aceita vírgula, ponto e vírgula, espaço e
 * quebra de linha como separador; o servidor valida o formato de verdade.
 */
export function parseCidList(text) {
    const seen = new Set();

    String(text ?? '')
        .split(/[\s,;]+/)
        .map((part) => part.replace(/[.†*]/g, '').toUpperCase())
        .filter(Boolean)
        .forEach((code) => seen.add(code));

    return [...seen];
}

/** Lista de códigos → texto para o campo ("H40.1, H40.2"). */
export function cidListToText(codes) {
    return (Array.isArray(codes) ? codes : []).map(cidLabel).join(', ');
}

/**
 * Estado inicial de uma tarefa nova no editor (os mesmos padrões operacionais do
 * esquema do servidor; o servidor continua sendo quem valida).
 */
export function blankTask() {
    return {
        key: '',
        kind: 'consultation',
        exam_key: null,
        interval: { unit: 'months', default: 6, min: 3, max: 12 },
        lead_days: 30,
        grace_days: 30,
        lost_after_days: 180,
        starts_after_months: null,
        recurs: true,
        required: true,
    };
}

/** Referência nova no editor. */
export function blankReference() {
    return { title: '', year: null, evidence: 'medium', note: '' };
}

/** Cópia profunda de um conteúdo (o editor nunca altera a prop). */
export function cloneContent(content) {
    return JSON.parse(JSON.stringify(content ?? {}));
}

/**
 * Selo neutro (sem cor de estado). `text-bg-secondary` NÃO serve neste template: o secundário é o
 * verde-água, e branco sobre ele dá contraste 1,9:1. Texto de ênfase sobre o fundo terciário passa
 * nos dois temas.
 */
export const NEUTRAL_BADGE = 'bg-body-tertiary text-body-emphasis border';

/** Classe do selo de cada estado de versão (cor + texto: a cor nunca é a única pista). */
export function stateBadgeClass(state) {
    return (
        {
            draft: NEUTRAL_BADGE,
            in_review: 'text-bg-info',
            validated: 'text-bg-primary',
            released: 'text-bg-success',
            superseded: NEUTRAL_BADGE,
            suspended: 'text-bg-warning',
            declined: 'text-bg-danger',
            withdrawn: NEUTRAL_BADGE,
            retired: 'text-bg-dark',
        }[state] ?? NEUTRAL_BADGE
    );
}
