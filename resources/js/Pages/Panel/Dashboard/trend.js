/**
 * Variação de um indicador em relação ao período anterior (Dashboard v2).
 *
 * - `kind: 'pct'` → diferença em PONTOS PERCENTUAIS (64% → 70% = +6 p.p.);
 *   demais → variação relativa (%).
 * - `better: 'up' | 'down'` → a cor é semântica, não a seta: falta subir é
 *   ruim (vermelho), receita subir é bom (verde).
 * - Sem número de um dos lados (null/undefined) → null (a tela não mostra
 *   variação). Anterior zerado e atual > 0 → seta sem percentual
 *   (`unit: 'none'`), porque "+∞%" não informa nada.
 *
 * @param {number|null|undefined} current
 * @param {number|null|undefined} previous
 * @param {{ kind?: 'count'|'money'|'pct', better?: 'up'|'down' }} [options]
 * @returns {{ direction: 'up'|'down'|'flat', tone: 'good'|'bad'|'neutral', value: number|null, unit: '%'|'pp'|'none' }|null}
 */
export function computeDelta(current, previous, { kind = 'count', better = 'up' } = {}) {
    if (!isNumber(current) || !isNumber(previous)) return null;

    const cur = Number(current);
    const prev = Number(previous);

    let value;
    let unit;

    if (kind === 'pct') {
        value = round1(cur - prev);
        unit = 'pp';
    } else if (prev === 0) {
        if (cur === 0) return { direction: 'flat', tone: 'neutral', value: 0, unit: '%' };

        return {
            direction: cur > 0 ? 'up' : 'down',
            tone: toneOf(cur > 0 ? 'up' : 'down', better),
            value: null,
            unit: 'none',
        };
    } else {
        value = round1(((cur - prev) / Math.abs(prev)) * 100);
        unit = '%';
    }

    const direction = value > 0 ? 'up' : value < 0 ? 'down' : 'flat';

    return { direction, tone: toneOf(direction, better), value: Math.abs(value), unit };
}

function toneOf(direction, better) {
    if (direction === 'flat') return 'neutral';

    return direction === better ? 'good' : 'bad';
}

function isNumber(value) {
    return value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value));
}

function round1(value) {
    return Math.round(value * 10) / 10;
}

/**
 * Intervalo curto no idioma do usuário: "1–6 de set." / "Sep 1–6"; meses
 * diferentes → "28 de set. – 2 de out.".
 *
 * @param {{ from: string, to: string }|null|undefined} range datas ISO (YYYY-MM-DD)
 * @param {string} locale  ex.: 'pt-BR'
 */
export function formatRange(range, locale) {
    if (!range?.from || !range?.to) return '';

    const from = parseDate(range.from);
    const to = parseDate(range.to);
    if (!from || !to) return '';

    const format = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' });

    if (typeof format.formatRange === 'function') return format.formatRange(from, to);

    return `${format.format(from)} – ${format.format(to)}`;
}

/** "YYYY-MM-DD" → Date local (sem deslocar o dia pelo fuso). */
export function parseDate(value) {
    if (!value) return null;

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value));
    if (!match) return null;

    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
}
