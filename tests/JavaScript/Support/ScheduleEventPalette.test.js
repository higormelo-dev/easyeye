import { describe, expect, it } from 'vitest';
import { resolveEventPalette, bestTextColor } from '@/Support/eventPalette.js';
// Calculadora independente: testa o par entregue à UI, não só a tabela interna.
const luminance = (hex) =>
    hex
        .slice(1)
        .match(/../g)
        .map((c) => parseInt(c, 16) / 255)
        .map((c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
        .reduce((sum, c, i) => sum + c * [0.2126, 0.7152, 0.0722][i], 0);
const ratio = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);
describe('Paleta da agenda — texto pequeno AA', () => {
    it.each([
        ['primary', '#0b5ed7'],
        ['secondary', '#4b5563'],
        ['success', '#15803d'],
        ['info', '#0e7490'],
        ['warning', '#92400e'],
        ['danger', '#b91c1c'],
        ['dark', '#212529'],
        ['purple', '#6d28d9'],
        ['orange', '#c2410c'],
        ['teal', '#0f766e'],
    ])('%s mantém o par aprovado e contraste ≥ 4,5:1', (status, color) => {
        const result = resolveEventPalette({ badge: `bg-${status} text-dark` });
        expect(result).toEqual({ backgroundColor: color, textColor: '#ffffff' });
        expect(ratio(result.backgroundColor, result.textColor)).toBeGreaterThanOrEqual(4.5);
    });
    it('hex legados migram e cores arbitrárias nunca produzem texto sem contraste', () => {
        for (const color of ['#0aa2c0', '#ea580c', '#b7791f', '#fff', '#000', '#818181', '#008a8a', 'invalid']) {
            const result = resolveEventPalette({ color });
            expect(ratio(result.backgroundColor, result.textColor)).toBeGreaterThanOrEqual(4.5);
        }
        expect(resolveEventPalette({ color: '#0aa2c0' }).backgroundColor).toBe('#0e7490');
        expect(bestTextColor('#ffffff')).toBe('#111827');
        expect(bestTextColor('#000000')).toBe('#ffffff');
    });
});
