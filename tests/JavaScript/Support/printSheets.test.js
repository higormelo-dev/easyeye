import { describe, it, expect } from 'vitest';
import { SHEET_SIZES, buildSheets, canPairOdOe, paginate, pairRows, sheetGrid } from '@/Support/printSheets.js';

const od = (id, session = 's1') => ({ id, laterality: 1, session });
const oe = (id, session = 's1') => ({ id, laterality: 2, session });
const ao = (id, session = 's1') => ({ id, laterality: 3, session });
const groupKeyOf = (exam) => exam.session;
const ids = (cells) => cells.map((c) => c?.id ?? null);

describe('printSheets — grade da folha', () => {
    it('oferece todos os tamanhos de 1 a 16 aprovados', () => {
        expect(SHEET_SIZES).toEqual([1, 2, 4, 6, 8, 9, 12, 15, 16]);
    });

    it('retrato empilha, paisagem aproveita a largura', () => {
        expect(sheetGrid(1, 'portrait')).toEqual({ cols: 1, rows: 1 });
        expect(sheetGrid(2, 'portrait')).toEqual({ cols: 1, rows: 2 });
        expect(sheetGrid(4, 'portrait')).toEqual({ cols: 2, rows: 2 });
        expect(sheetGrid(6, 'portrait')).toEqual({ cols: 2, rows: 3 });
        expect(sheetGrid(8, 'portrait')).toEqual({ cols: 2, rows: 4 });
        expect(sheetGrid(9, 'portrait')).toEqual({ cols: 3, rows: 3 });
        expect(sheetGrid(12, 'portrait')).toEqual({ cols: 3, rows: 4 });
        expect(sheetGrid(15, 'portrait')).toEqual({ cols: 3, rows: 5 });
        expect(sheetGrid(16, 'portrait')).toEqual({ cols: 4, rows: 4 });

        expect(sheetGrid(2, 'landscape')).toEqual({ cols: 2, rows: 1 });
        expect(sheetGrid(4, 'landscape')).toEqual({ cols: 2, rows: 2 });
        expect(sheetGrid(6, 'landscape')).toEqual({ cols: 3, rows: 2 });
        expect(sheetGrid(8, 'landscape')).toEqual({ cols: 4, rows: 2 });
        expect(sheetGrid(9, 'landscape')).toEqual({ cols: 3, rows: 3 });
        expect(sheetGrid(12, 'landscape')).toEqual({ cols: 4, rows: 3 });
        expect(sheetGrid(15, 'landscape')).toEqual({ cols: 5, rows: 3 });
        expect(sheetGrid(16, 'landscape')).toEqual({ cols: 4, rows: 4 });
    });

    it('com OD/OE lado a lado são sempre 2 colunas (OD | OE), nas duas orientações', () => {
        for (const orientation of ['portrait', 'landscape']) {
            expect(sheetGrid(2, orientation, true)).toEqual({ cols: 2, rows: 1 });
            expect(sheetGrid(4, orientation, true)).toEqual({ cols: 2, rows: 2 });
            expect(sheetGrid(6, orientation, true)).toEqual({ cols: 2, rows: 3 });
            expect(sheetGrid(8, orientation, true)).toEqual({ cols: 2, rows: 4 });
            expect(sheetGrid(12, orientation, true)).toEqual({ cols: 2, rows: 6 });
            expect(sheetGrid(16, orientation, true)).toEqual({ cols: 2, rows: 8 });
        }
        // 1 imagem por folha não tem par.
        expect(sheetGrid(1, 'portrait', true)).toEqual({ cols: 1, rows: 1 });
    });

    it('não pareia folhas ímpares, preservando a grade e a ordem linear', () => {
        expect(canPairOdOe(9)).toBe(false);
        expect(canPairOdOe(15)).toBe(false);
        expect(sheetGrid(9, 'portrait', true)).toEqual({ cols: 3, rows: 3 });
        expect(sheetGrid(15, 'landscape', true)).toEqual({ cols: 5, rows: 3 });
    });

    it('valor fora da lista cai em 1 por folha', () => {
        expect(sheetGrid(5)).toEqual({ cols: 1, rows: 1 });
        expect(sheetGrid(null)).toEqual({ cols: 1, rows: 1 });
    });
});

describe('printSheets — pares OD | OE', () => {
    it('o k-ésimo OD fica com o k-ésimo OE do mesmo grupo, OD sempre à esquerda', () => {
        // OE vem antes na lista: a coluna não pode depender da ordem de entrada.
        const rows = pairRows([oe('oe1'), od('od1'), oe('oe2'), od('od2')], groupKeyOf);

        expect(rows.map((row) => ids(row))).toEqual([
            ['od1', 'oe1'],
            ['od2', 'oe2'],
        ]);
    });

    it('olho sem par fica sozinho, na coluna do próprio olho', () => {
        const rows = pairRows([od('od1'), od('od2'), oe('oe1')], groupKeyOf);

        expect(rows.map((row) => ids(row))).toEqual([
            ['od1', 'oe1'],
            ['od2', null],
        ]);

        const onlyOe = pairRows([oe('oe1')], groupKeyOf);
        expect(onlyOe.map((row) => ids(row))).toEqual([[null, 'oe1']]);
    });

    it('grupos diferentes nunca dividem a mesma linha; ordem do 1º grupo visto', () => {
        const rows = pairRows([od('b-od', 'B'), od('a-od', 'A'), oe('b-oe', 'B'), oe('a-oe', 'A')], groupKeyOf);

        expect(rows.map((row) => ids(row))).toEqual([
            ['b-od', 'b-oe'],
            ['a-od', 'a-oe'],
        ]);
    });

    it('imagem sem olho definido (AO) vai sozinha na coluna da esquerda, depois dos pares do grupo', () => {
        const rows = pairRows([ao('ao1'), od('od1'), oe('oe1')], groupKeyOf);

        expect(rows.map((row) => ids(row))).toEqual([
            ['od1', 'oe1'],
            ['ao1', null],
        ]);
    });
});

describe('printSheets — paginação', () => {
    it('corta em folhas de N e completa a última com células vazias', () => {
        const sheets = paginate(
            ['a', 'b', 'c', 'd', 'e'].map((id) => ({ id })),
            4,
        );

        expect(sheets).toHaveLength(2);
        expect(ids(sheets[0])).toEqual(['a', 'b', 'c', 'd']);
        expect(ids(sheets[1])).toEqual(['e', null, null, null]);
    });

    it('lista vazia não gera folha', () => {
        expect(paginate([], 4)).toEqual([]);
    });

    it('buildSheets: par nunca atravessa a quebra de folha (6 por folha, 4 pares)', () => {
        const exams = [];
        for (let i = 1; i <= 4; i += 1) {
            exams.push(od(`od${i}`, `s${i}`), oe(`oe${i}`, `s${i}`));
        }

        const { grid, sheets } = buildSheets(exams, { perSheet: 6, pairOdOe: true, groupKeyOf });

        expect(grid).toEqual({ cols: 2, rows: 3 });
        expect(sheets).toHaveLength(2);
        expect(ids(sheets[0])).toEqual(['od1', 'oe1', 'od2', 'oe2', 'od3', 'oe3']);
        expect(ids(sheets[1])).toEqual(['od4', 'oe4', null, null, null, null]);
    });

    it('buildSheets sem pares mantém a ordem recebida e usa a grade da orientação', () => {
        const exams = [od('a'), oe('b'), od('c')];

        const portrait = buildSheets(exams, { perSheet: 2, orientation: 'portrait', groupKeyOf });
        expect(portrait.grid).toEqual({ cols: 1, rows: 2 });
        expect(portrait.sheets.map(ids)).toEqual([
            ['a', 'b'],
            ['c', null],
        ]);

        const landscape = buildSheets(exams, { perSheet: 2, orientation: 'landscape', groupKeyOf });
        expect(landscape.grid).toEqual({ cols: 2, rows: 1 });
    });

    it('1 por folha ignora o pedido de pares (não há como parear uma imagem só)', () => {
        const { grid, sheets } = buildSheets([od('a'), oe('b')], { perSheet: 1, pairOdOe: true, groupKeyOf });

        expect(grid).toEqual({ cols: 1, rows: 1 });
        expect(sheets.map(ids)).toEqual([['a'], ['b']]);
    });

    it('folhas ímpares com pedido de pares preservam a ordem e completam a última folha', () => {
        const exams = [oe('oe1'), od('od1'), oe('oe2')];

        for (const perSheet of [9, 15]) {
            const { sheets } = buildSheets(exams, { perSheet, pairOdOe: true, groupKeyOf });
            expect(ids(sheets[0]).slice(0, 3)).toEqual(['oe1', 'od1', 'oe2']);
            expect(sheets[0]).toHaveLength(perSheet);
            expect(sheets[0].at(-1)).toBeNull();
        }
    });
});
