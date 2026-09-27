/**
 * Ações em lote da Tabela de Preços (Reajustar % e Copiar de outro convênio):
 * cálculo puro, aplicado só na grade — nada vai ao servidor até o "Salvar".
 *
 * Dinheiro em centavos inteiros (BigInt) para o arredondamento não herdar erro
 * de ponto flutuante: 0,15 × 1,5 = 0,225 → 0,23 (em float daria 0,22).
 */

/** Menor percentual aceito no reajuste. */
export const ADJUST_PERCENT_MIN = 0.01;

/** Maior aumento aceito (%). */
export const ADJUST_INCREASE_MAX = 1000;

/** Maior redução aceita (%): 100% zeraria todos os preços. */
export const ADJUST_DECREASE_MAX = 99.99;

/** Quantas linhas de exemplo a prévia mostra. */
export const PREVIEW_EXAMPLES = 3;

const PERCENT_SCALE = 10000n; // percentual em centésimos: 7,5% → 750 / 10000

export const isBlankPrice = (value) => value === '' || value === null || value === undefined;

/** Teto do percentual conforme o tipo de reajuste ('increase' | 'decrease'). */
export function adjustPercentLimit(direction) {
    return direction === 'decrease' ? ADJUST_DECREASE_MAX : ADJUST_INCREASE_MAX;
}

/** Percentual (sempre positivo; o sinal vem do tipo) dentro dos limites? */
export function isValidAdjustPercent(value, direction) {
    return typeof value === 'number' && Number.isFinite(value)
        && value >= ADJUST_PERCENT_MIN && value <= adjustPercentLimit(direction);
}

/**
 * Preço × (1 + percent/100), arredondado a 2 casas (meio para cima); nunca
 * negativo. `percent` com sinal: 10 = +10%, -7.5 = −7,5%.
 *
 * @param {number} price
 * @param {number} percent
 * @returns {number}
 */
export function adjustPrice(price, percent) {
    const cents  = BigInt(Math.round(Number(price) * 100));
    const factor = PERCENT_SCALE + BigInt(Math.round(Number(percent) * 100));

    if (cents <= 0n || factor <= 0n) return 0;

    // (centavos × fator) / 10000, arredondando meio para cima em inteiros.
    const rounded = (cents * factor * 2n + PERCENT_SCALE) / (PERCENT_SCALE * 2n);

    return Number(rounded) / 100;
}

/**
 * Linhas que mudam com o reajuste. Só preço próprio é reajustado: linha sem
 * preço (vazia ou usando o padrão do sistema) conta em `skipped`.
 *
 * @param {Array<{ row: object, index: number }>} entries
 * @param {number} percent com sinal
 * @returns {{ changes: Array<{ index: number, row: object, from: number, to: number }>, skipped: number }}
 */
export function planAdjustment(entries, percent) {
    const changes = [];
    let skipped   = 0;

    for (const { row, index } of entries) {
        if (isBlankPrice(row.price)) {
            skipped += 1;
            continue;
        }

        const from = Number(row.price);
        const to   = adjustPrice(from, percent);

        if (to !== from) changes.push({ index, row, from, to });
    }

    return { changes, skipped };
}

/**
 * Preços do convênio de origem aplicados à grade. Sem `overwrite`, só as
 * linhas sem preço próprio recebem o preço copiado (as outras contam em
 * `kept`); procedimento sem preço na origem conta em `missing`.
 *
 * @param {Array<{ row: object, index: number }>} entries
 * @param {Record<string, number>|null} sourcePrices procedure_id → preço
 * @param {{ overwrite?: boolean }} options
 * @returns {{ changes: Array<{ index: number, row: object, from: number|null, to: number }>, kept: number, missing: number }}
 */
export function planCopy(entries, sourcePrices, { overwrite = false } = {}) {
    const changes = [];
    let kept      = 0;
    let missing   = 0;

    for (const { row, index } of entries) {
        const source = sourcePrices?.[row.procedure_id];

        if (isBlankPrice(source) || !Number.isFinite(Number(source))) {
            missing += 1;
            continue;
        }

        const to   = Math.round(Number(source) * 100) / 100;
        const from = isBlankPrice(row.price) ? null : Number(row.price);

        if (from === to) continue;

        if (from !== null && !overwrite) {
            kept += 1;
            continue;
        }

        changes.push({ index, row, from, to });
    }

    return { changes, kept, missing };
}
