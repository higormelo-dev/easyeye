import { describe, it, expect } from 'vitest';
import { detectPreset, isPartialYear, parseYmd, presetRange, rangeError } from '@/utils/periodPresets.js';

describe('periodPresets', () => {
    it('calcula cada atalho a partir do "hoje" da clínica', () => {
        const today = '2026-03-10';

        expect(presetRange('today', today)).toEqual({ from: '2026-03-10', to: '2026-03-10' });
        expect(presetRange('yesterday', today)).toEqual({ from: '2026-03-09', to: '2026-03-09' });
        expect(presetRange('last7', today)).toEqual({ from: '2026-03-04', to: '2026-03-10' });
        expect(presetRange('month', today)).toEqual({ from: '2026-03-01', to: '2026-03-10' });
        expect(presetRange('last_month', today)).toEqual({ from: '2026-02-01', to: '2026-02-28' });
        expect(presetRange('year', today)).toEqual({ from: '2026-01-01', to: '2026-03-10' });
    });

    it('vira mês/ano corretamente (1º de janeiro, ano bissexto)', () => {
        expect(presetRange('yesterday', '2026-01-01')).toEqual({ from: '2025-12-31', to: '2025-12-31' });
        expect(presetRange('last_month', '2026-01-15')).toEqual({ from: '2025-12-01', to: '2025-12-31' });
        expect(presetRange('last_month', '2028-03-05')).toEqual({ from: '2028-02-01', to: '2028-02-29' });
    });

    it('atalho desconhecido ou "hoje" inválido → null', () => {
        expect(presetRange('decade', '2026-03-10')).toBeNull();
        expect(presetRange('today', '2026-02-30')).toBeNull();
        expect(presetRange('today', '')).toBeNull();
    });

    it('detecta o atalho do intervalo, senão "custom"', () => {
        expect(detectPreset('2026-03-01', '2026-03-10', '2026-03-10')).toBe('month');
        expect(detectPreset('2026-03-02', '2026-03-10', '2026-03-10')).toBe('custom');
        expect(detectPreset('2026-03-10', '2026-03-10', '2026-03-10', ['month'])).toBe('custom');
    });

    it('valida datas inexistentes, início > fim e limite máximo', () => {
        expect(parseYmd('2026-02-30')).toBeNull();
        expect(rangeError('2026-03-01', '2026-03-10')).toBeNull();
        expect(rangeError('2026-03-11', '2026-03-10')).toBe('invalid_range');
        expect(rangeError('abc', '2026-03-10')).toBe('invalid_date');
        expect(rangeError('2026-03-01', '2026-03-12', '2026-03-10')).toBe('after_max');
        expect(rangeError('2026-03-01', '2026-03-10', '2026-03-10')).toBeNull();
        expect(rangeError('1899-12-31', '2026-03-10')).toBe('invalid_date');
    });

    it('ano com menos de 4 dígitos significativos é digitação em andamento', () => {
        expect(isPartialYear('0202-03-01')).toBe(true);
        expect(isPartialYear('2026-03-01')).toBe(false);
        expect(isPartialYear('')).toBe(false);
    });
});
