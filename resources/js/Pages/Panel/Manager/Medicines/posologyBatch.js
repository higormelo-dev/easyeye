/**
 * Utilitários do lote "Gerar posologia com IA" (Manager → Medicamentos).
 */

/** Filtros aplicados na lista → parâmetros da prévia/início (sem vazios). */
export function batchFilterParams(filters = {}) {
    const params = {};

    for (const key of ['search', 'source', 'status', 'cmed_situation', 'posology', 'sort', 'direction']) {
        if (filters[key]) params[key] = filters[key];
    }
    if (filters.ophthalmic) params.ophthalmic = 1;

    return params;
}

const CMED_SITUATION_LABEL = { marketed: 'cmed_marketed', not_marketed: 'not_marketed', left_list: 'cmed_left_list' };

/** Filtros em texto (chips do modal de confirmação). */
export function filterChips(filters = {}, t = {}) {
    const chips = [];

    if (filters.search) chips.push((t.batch_filter_search ?? ':term').replace(':term', filters.search));
    if (filters.source) chips.push(t[`source_${filters.source}`] ?? filters.source);
    if (filters.cmed_situation) chips.push(t[CMED_SITUATION_LABEL[filters.cmed_situation]] ?? filters.cmed_situation);
    if (filters.status) chips.push(t[`status_${filters.status}`] ?? filters.status);
    if (filters.posology) chips.push(t[`posology_filter_${filters.posology}`] ?? filters.posology);
    if (filters.ophthalmic) chips.push(t.filter_ophthalmic);

    return chips;
}

/** Custo em dólar com até 4 casas (chamadas de IA custam frações de centavo). */
export function usd(value, locale = 'pt-BR') {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    }).format(Number(value));
}

/** Custo em real com até 4 casas (mesmo motivo do usd()). */
export function brl(value, locale = 'pt-BR') {
    if (value === null || value === undefined || Number.isNaN(Number(value))) return '—';

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    }).format(Number(value));
}

/**
 * Tempo restante pelo ritmo desde o início (grupos/segundo). null enquanto
 * não há base (nenhum grupo processado ainda).
 *
 * @returns {number|null} segundos
 */
export function etaSeconds(batch, nowMs = Date.now()) {
    if (!batch?.started_at || !batch.processed_groups || batch.processed_groups >= batch.total_groups) return null;

    const elapsed = (nowMs - new Date(batch.started_at).getTime()) / 1000;
    if (!(elapsed > 0)) return null;

    return Math.round((elapsed / batch.processed_groups) * (batch.total_groups - batch.processed_groups));
}

/** Duração curta no idioma do usuário ("2 min", "1 h 5 min", "40 s"). */
export function shortDuration(seconds, locale = 'pt-BR') {
    if (seconds === null || seconds === undefined) return '—';

    const unit = (value, u) =>
        new Intl.NumberFormat(locale, { style: 'unit', unit: u, unitDisplay: 'short' }).format(value);
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);

    if (h > 0) return m > 0 ? `${unit(h, 'hour')} ${unit(m, 'minute')}` : unit(h, 'hour');
    if (m > 0) return unit(m, 'minute');

    return unit(Math.max(1, seconds), 'second');
}
