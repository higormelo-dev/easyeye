/**
 * Documentos do atendimento (Fase 2 / Bloco B) — utilitários da TELA:
 * textos (`t_documents` = lang/<locale>/clinical_documents.php), datas no
 * idioma e o corpo do pedido de exames para "Salvar como modelo". Regras
 * (faixa da data alternativa, CID com autorização, kits por convênio) valem
 * no servidor — aqui só se repete o que a tela precisa mostrar.
 */
import { usePage } from '@inertiajs/vue3';

/** Textos dos documentos — prop explícita vence a prop da página. */
export function documentTexts(explicit = null) {
    if (explicit && Object.keys(explicit).length) return explicit;
    try {
        return usePage()?.props?.t_documents ?? usePage()?.props?.t ?? {};
    } catch {
        return {};
    }
}

/** Lê `a.b.c` de um objeto de textos, com fallback. */
export function td(texts, path, fallback = '') {
    const value = String(path)
        .split('.')
        .reduce((acc, key) => (acc && typeof acc === 'object' ? acc[key] : undefined), texts);
    return typeof value === 'string' ? value : fallback;
}

/** Substitui `:name` pelos valores. */
export function fill(text, replacements = {}) {
    return Object.entries(replacements).reduce((acc, [key, value]) => acc.replaceAll(`:${key}`, value ?? ''), text);
}

/** "2026-10-07" → data curta no idioma da tela (sem fuso: é data de calendário). */
export function formatIsoDate(iso, locale = 'pt-BR') {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '';
    const [y, m, d] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat(locale, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(y, m - 1, d)));
}

/** Hora atual "HH:MM" (fim do comparecimento). */
export function currentTime(now = new Date()) {
    return `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
}

/**
 * Data do documento para o servidor: vazio ou hoje = sem data alternativa.
 * Fora da faixa a tela avisa, mas quem recusa é o servidor (422).
 */
export function documentDatePayload(value, today) {
    const v = String(value ?? '').trim();
    return v && v !== today ? { document_date: v } : {};
}

/** A data está na faixa [min, max] (strings ISO comparam em ordem). */
export function dateInRange(value, config) {
    if (!value) return true;
    if (!config?.min || !config?.max) return true;
    return value >= config.min && value <= config.max;
}

/** Linhas de texto do pedido (corpo do modelo pessoal e prévia). */
export function requestSummary(items = []) {
    return items
        .filter((item) => item && item.checked !== false && item.name)
        .map((item) => `- ${item.name}${item.code ? ` (TUSS ${item.code})` : ''}`)
        .join('\n');
}
