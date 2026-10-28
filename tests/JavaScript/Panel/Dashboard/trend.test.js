import { describe, it, expect } from 'vitest';
import { computeDelta, formatRange, parseDate } from '@/Pages/Panel/Dashboard/trend.js';

/**
 * Variação dos indicadores do Dashboard vs. o mesmo período do mês anterior:
 * a cor é semântica (falta subir = ruim, receita subir = bom), percentuais
 * variam em pontos percentuais e anterior zerado não vira "+∞%".
 */
describe('computeDelta', () => {
    it('contagem/moeda: variação relativa; subir é bom por padrão', () => {
        expect(computeDelta(120, 100)).toEqual({ direction: 'up', tone: 'good', value: 20, unit: '%' });
        expect(computeDelta(80, 100, { kind: 'money' })).toEqual({
            direction: 'down',
            tone: 'bad',
            value: 20,
            unit: '%',
        });
        expect(computeDelta(5040, 4860)).toMatchObject({ direction: 'up', value: 3.7 });
    });

    it('cor semântica invertida: falta/glosa subir é ruim (vermelho), cair é bom', () => {
        expect(computeDelta(12, 8, { better: 'down' })).toMatchObject({ direction: 'up', tone: 'bad' });
        expect(computeDelta(3.4, 18.8, { kind: 'pct', better: 'down' })).toEqual({
            direction: 'down',
            tone: 'good',
            value: 15.4,
            unit: 'pp',
        });
    });

    it('percentual: diferença em pontos percentuais (não % de %)', () => {
        expect(computeDelta(70, 64, { kind: 'pct' })).toEqual({ direction: 'up', tone: 'good', value: 6, unit: 'pp' });
    });

    it('igual: neutro; anterior zerado: seta sem percentual; sem número: null', () => {
        expect(computeDelta(10, 10)).toMatchObject({ direction: 'flat', tone: 'neutral', value: 0 });
        expect(computeDelta(0, 0)).toMatchObject({ direction: 'flat', tone: 'neutral' });
        expect(computeDelta(24, 0)).toEqual({ direction: 'up', tone: 'good', value: null, unit: 'none' });
        expect(computeDelta(null, 10)).toBeNull();
        expect(computeDelta(10, undefined)).toBeNull();
        expect(computeDelta(50, null, { kind: 'pct' })).toBeNull();
    });
});

describe('formatRange e parseDate', () => {
    it('intervalo curto no idioma, sem deslocar o dia pelo fuso', () => {
        const range = { from: '2026-09-01', to: '2026-09-06' };
        const pt = formatRange(range, 'pt-BR');
        const en = formatRange(range, 'en');

        expect(pt).toContain('1');
        expect(pt).toContain('6');
        expect(pt).toMatch(/set/);
        expect(en).toMatch(/Sep/);
        expect(formatRange(null, 'pt-BR')).toBe('');
        expect(parseDate('2026-10-06').getDate()).toBe(6);
        expect(parseDate('x')).toBeNull();
    });
});
