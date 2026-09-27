import { describe, it, expect } from 'vitest';
import { currencySymbol, formatMoneyInput, localeSeparators, parseMoneyInput } from '@/utils/money.js';

describe('money (entrada de valores no formato do idioma)', () => {
    it('lê os separadores do Intl, não fixos', () => {
        expect(localeSeparators('pt-BR')).toEqual({ group: '.', decimal: ',' });
        expect(localeSeparators('en')).toEqual({ group: ',', decimal: '.' });
        expect(currencySymbol('pt-BR', 'BRL')).toBe('R$');
    });

    it('pt-BR: interpreta vírgula decimal, milhar e o "ponto decimal" digitado no balcão', () => {
        expect(parseMoneyInput('1.234,56', 'pt-BR')).toBe(1234.56);
        expect(parseMoneyInput('1234,5', 'pt-BR')).toBe(1234.5);
        expect(parseMoneyInput('R$ 1.234,56', 'pt-BR')).toBe(1234.56);
        expect(parseMoneyInput('1.234', 'pt-BR')).toBe(1234);
        expect(parseMoneyInput('10.5', 'pt-BR')).toBe(10.5);
        expect(parseMoneyInput('1,234.56', 'pt-BR')).toBe(1234.56);
        expect(parseMoneyInput('0,005', 'pt-BR')).toBe(0.01);
    });

    it('en: vírgula de milhar e ponto decimal', () => {
        expect(parseMoneyInput('1,234.56', 'en')).toBe(1234.56);
        expect(parseMoneyInput('10,5', 'en')).toBe(10.5);
        expect(parseMoneyInput('1,234', 'en')).toBe(1234);
    });

    it('vazio/ilegível vira null e sinal só com allowNegative', () => {
        expect(parseMoneyInput('', 'pt-BR')).toBeNull();
        expect(parseMoneyInput('   ', 'pt-BR')).toBeNull();
        expect(parseMoneyInput('abc', 'pt-BR')).toBeNull();
        expect(parseMoneyInput(null, 'pt-BR')).toBeNull();
        expect(parseMoneyInput('-10,00', 'pt-BR')).toBe(10);
        expect(parseMoneyInput('-10,00', 'pt-BR', { allowNegative: true })).toBe(-10);
    });

    it('formata com 2 casas no idioma, sem símbolo', () => {
        expect(formatMoneyInput(1234.5, 'pt-BR')).toBe('1.234,50');
        expect(formatMoneyInput('1234.5', 'en')).toBe('1,234.50');
        expect(formatMoneyInput(null, 'pt-BR')).toBe('');
        expect(formatMoneyInput('abc', 'pt-BR')).toBe('');
    });
});
