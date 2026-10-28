/**
 * Atalhos de período para filtros (Hoje, Ontem, Últimos 7 dias, Mês atual,
 * Mês anterior, Ano atual). Datas sempre como 'YYYY-MM-DD', calculadas a partir
 * do "hoje" da clínica (o servidor manda `today`) — nunca do fuso do navegador:
 * aritmética em UTC, sem hora, para não "voltar um dia" perto da meia-noite.
 */

export const PERIOD_PRESETS = ['today', 'yesterday', 'last7', 'month', 'last_month', 'year'];

export const CUSTOM_PRESET = 'custom';

/** Menor data aceita — a mesma do backend (App\Support\ReportPeriod: ano ≥ 1900). */
export const MIN_DATE = '1900-01-01';

const YMD = /^(\d{4})-(\d{2})-(\d{2})$/;

/** 'YYYY-MM-DD' existente (2026-02-30 não passa) → Date em UTC; senão null. */
export function parseYmd(value) {
    const match = YMD.exec(String(value ?? ''));
    if (!match) return null;

    const [, y, m, d] = match.map(Number);
    const date = new Date(Date.UTC(y, m - 1, d));

    return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d ? date : null;
}

export function toYmd(date) {
    const y = String(date.getUTCFullYear()).padStart(4, '0');
    const m = String(date.getUTCMonth() + 1).padStart(2, '0');
    const d = String(date.getUTCDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
}

/** Hoje no relógio local, como 'YYYY-MM-DD' (fallback quando o servidor não manda). */
export function localToday() {
    const now = new Date();

    return toYmd(new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())));
}

function addDays(date, days) {
    return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate() + days));
}

/** Intervalo { from, to } do atalho, ou null (atalho desconhecido / hoje inválido). */
export function presetRange(preset, today) {
    const base = parseYmd(today);
    if (!base) return null;

    const y = base.getUTCFullYear();
    const m = base.getUTCMonth();

    switch (preset) {
        case 'today':
            return { from: toYmd(base), to: toYmd(base) };
        case 'yesterday': {
            const day = toYmd(addDays(base, -1));

            return { from: day, to: day };
        }
        case 'last7':
            return { from: toYmd(addDays(base, -6)), to: toYmd(base) };
        case 'month':
            return { from: toYmd(new Date(Date.UTC(y, m, 1))), to: toYmd(base) };
        case 'last_month':
            return { from: toYmd(new Date(Date.UTC(y, m - 1, 1))), to: toYmd(new Date(Date.UTC(y, m, 0))) };
        case 'year':
            return { from: toYmd(new Date(Date.UTC(y, 0, 1))), to: toYmd(base) };
        default:
            return null;
    }
}

/** Qual atalho gera exatamente este intervalo (ou 'custom'). */
export function detectPreset(from, to, today, presets = PERIOD_PRESETS) {
    for (const preset of presets) {
        const range = presetRange(preset, today);
        if (range && range.from === from && range.to === to) return preset;
    }

    return CUSTOM_PRESET;
}

/**
 * Ano ainda sendo digitado: o <input type=date> do Chrome dispara `change` a
 * cada dígito do ano (0002 → 0020 → 0202 → 2026). Ano < 1000 = incompleto.
 */
export function isPartialYear(value) {
    const date = parseYmd(value);

    return date !== null && date.getUTCFullYear() < 1000;
}

/**
 * Valida o intervalo: datas existentes (a partir de 1900), início ≤ fim e, se
 * houver `max`, nenhuma depois dele. Devolve null se válido ou o motivo:
 * 'invalid_date' | 'invalid_range' | 'after_max'.
 */
export function rangeError(from, to, max = '') {
    if (!parseYmd(from) || !parseYmd(to) || from < MIN_DATE || to < MIN_DATE) return 'invalid_date';
    if (from > to) return 'invalid_range';
    if (max && parseYmd(max) && (from > max || to > max)) return 'after_max';

    return null;
}
