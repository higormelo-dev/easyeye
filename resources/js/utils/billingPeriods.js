/**
 * Regras de ciclo e período de assinatura no navegador — prévias para o
 * manager (o servidor é a fonte da verdade). Espelham:
 *  - SubscriptionManagementService::extendedEnd (somar período: a partir do
 *    término futuro, ou de hoje se já venceu; meses não "transbordam");
 *  - PlanPricing::savingsPercent (economia sobre o mensal, arredondada para baixo).
 */

/** Soma meses sem transbordar: 31/01 + 1 mês = 28/02 (ou 29 no bissexto). */
export function addMonthsNoOverflow(date, months) {
    const out = new Date(date.getTime());
    const day = out.getDate();

    out.setDate(1);
    out.setMonth(out.getMonth() + months);

    const lastDay = new Date(out.getFullYear(), out.getMonth() + 1, 0).getDate();
    out.setDate(Math.min(day, lastDay));

    return out;
}

/**
 * Novo término ao adicionar `quantity` dias/meses/anos.
 * @param {string|Date|null} currentEnd término atual (ISO) ou null
 * @param {'days'|'months'|'years'} unit
 * @param {number} quantity
 * @param {Date} [now]
 * @returns {Date}
 */
export function extendedEnd(currentEnd, unit, quantity, now = new Date()) {
    const end = currentEnd ? new Date(currentEnd) : null;
    const base = end && !Number.isNaN(end.getTime()) && end > now ? end : new Date(now.getTime());
    const qty = Number(quantity) || 0;

    if (unit === 'days') {
        const out = new Date(base.getTime());
        out.setDate(out.getDate() + qty);

        return out;
    }

    return addMonthsNoOverflow(base, unit === 'years' ? qty * 12 : qty);
}

/** Economia (%) de pagar `price` por `months` meses em vez do mensal; 0 sem mensal. */
export function savingsPercent(monthly, price, months) {
    const m = Number(monthly);
    const p = Number(price);

    if (!m || m <= 0 || months <= 1 || Number.isNaN(p)) return 0;

    const full = m * months;

    return Math.max(0, Math.floor(((full - p) / full) * 100 + 1e-9));
}

/** Data local no formato do <input type="date"> (YYYY-MM-DD), sem fuso. */
export function toDateInput(date) {
    if (!date) return '';

    const d = date instanceof Date ? date : new Date(date);
    if (Number.isNaN(d.getTime())) return '';

    const pad = (n) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Dias inteiros de hoje até `date` (negativo = já passou); null sem data. */
export function daysUntil(date, now = new Date()) {
    if (!date) return null;

    const d = new Date(date);
    if (Number.isNaN(d.getTime())) return null;

    const startOfDay = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();

    return Math.round((startOfDay(d) - startOfDay(now)) / 86_400_000);
}

/**
 * Plural no formato das traduções do Laravel ("um|vários") + :placeholders.
 * @param {string} text  "Falta :days dia|Faltam :days dias"
 * @param {number} count
 * @param {Record<string, string|number>} [params]
 */
export function choice(text, count, params = {}) {
    const parts = String(text ?? '').split('|');
    const chosen = Math.abs(count) === 1 ? parts[0] : (parts[1] ?? parts[0]);

    return chosen.replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, (match, name) =>
        Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match,
    );
}
