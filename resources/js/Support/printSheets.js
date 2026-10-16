/**
 * Impressão de exames "N por folha" (Fase 5): cada folha A4 leva exatamente
 * N imagens numa grade fixa, com quebra de página depois dela. Diferente do
 * modo "colunas" do modal de impressão (grade contínua, a quebra cai onde o
 * navegador quiser), aqui o médico escolhe quantas imagens cabem numa folha.
 *
 * Com "OD/OE lado a lado" cada linha da grade é um par — OD na coluna da
 * esquerda, OE na da direita —, então a folha nunca mistura os olhos nem
 * troca a ordem entre uma folha e outra.
 *
 * Funções puras (sem DOM): a tela só renderiza o que sai daqui.
 */

export const SHEET_SIZES = [1, 2, 4, 6];

const EYE_OD = 1;
const EYE_OE = 2;

/**
 * Grade (colunas × linhas) de uma folha com `perSheet` imagens.
 *
 * - Sem pares: retrato empilha (2 → 1×2, 6 → 2×3); paisagem aproveita a
 *   largura (2 → 2×1, 6 → 3×2).
 * - Com pares OD/OE: 2 colunas sempre (OD | OE), as linhas são os pares — uma
 *   folha de 1 imagem não tem par e fica 1×1.
 */
export function sheetGrid(perSheet, orientation = 'portrait', pairOdOe = false) {
    const n = SHEET_SIZES.includes(perSheet) ? perSheet : 1;
    if (n === 1) return { cols: 1, rows: 1 };

    if (pairOdOe) return { cols: 2, rows: n / 2 };

    if (orientation === 'landscape') {
        return { 1: { cols: 1, rows: 1 }, 2: { cols: 2, rows: 1 }, 4: { cols: 2, rows: 2 }, 6: { cols: 3, rows: 2 } }[
            n
        ];
    }

    return { 1: { cols: 1, rows: 1 }, 2: { cols: 1, rows: 2 }, 4: { cols: 2, rows: 2 }, 6: { cols: 2, rows: 3 } }[n];
}

/**
 * Linhas [esquerda, direita] para o modo lado a lado: dentro de cada grupo
 * (mesma sessão de exame), o k-ésimo OD fica com o k-ésimo OE; o que sobra de
 * um olho fica sozinho na própria linha, com a outra célula vazia (`null`),
 * para o olho certo nunca mudar de coluna. Imagem sem olho definido (AO) vai
 * para a coluna da esquerda, sozinha. A ordem dos grupos é a de 1ª aparição.
 *
 * @param {Array<object>} exams
 * @param {(exam: object) => string} groupKeyOf
 * @returns {Array<[object|null, object|null]>}
 */
export function pairRows(exams, groupKeyOf) {
    const groups = new Map();
    for (const exam of exams) {
        const key = groupKeyOf(exam);
        if (!groups.has(key)) groups.set(key, { od: [], oe: [], other: [] });
        const bucket = groups.get(key);
        if (exam.laterality === EYE_OD) bucket.od.push(exam);
        else if (exam.laterality === EYE_OE) bucket.oe.push(exam);
        else bucket.other.push(exam);
    }

    const rows = [];
    for (const { od, oe, other } of groups.values()) {
        const pairs = Math.max(od.length, oe.length);
        for (let i = 0; i < pairs; i += 1) rows.push([od[i] ?? null, oe[i] ?? null]);
        for (const exam of other) rows.push([exam, null]);
    }

    return rows;
}

/**
 * Divide as células em folhas de `perSheet` posições. A última folha é
 * completada com `null` para a grade manter o tamanho das células (a imagem
 * sozinha não estica para ocupar a folha toda).
 *
 * @param {Array<object|null>} cells
 * @returns {Array<Array<object|null>>}
 */
export function paginate(cells, perSheet) {
    const size = SHEET_SIZES.includes(perSheet) ? perSheet : 1;
    const sheets = [];
    for (let i = 0; i < cells.length; i += size) {
        const sheet = cells.slice(i, i + size);
        while (sheet.length < size) sheet.push(null);
        sheets.push(sheet);
    }

    return sheets;
}

/**
 * Folhas prontas para renderizar.
 *
 * @param {Array<object>} exams    exames já na ordem e sem vídeo
 * @param {{perSheet:number, orientation?:string, pairOdOe?:boolean, groupKeyOf:(exam:object)=>string}} options
 * @returns {{grid:{cols:number,rows:number}, sheets:Array<Array<object|null>>}}
 */
export function buildSheets(exams, { perSheet, orientation = 'portrait', pairOdOe = false, groupKeyOf }) {
    const paired = pairOdOe && perSheet > 1;
    const grid = sheetGrid(perSheet, orientation, paired);
    const cells = paired ? pairRows(exams, groupKeyOf).flat() : exams.slice();

    return { grid, sheets: paginate(cells, perSheet) };
}
