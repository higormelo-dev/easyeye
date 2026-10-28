import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { useViewMode } from '@/composables/useViewMode.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

describe('useViewMode', () => {
    beforeEach(() => window.localStorage.clear());
    afterEach(() => vi.restoreAllMocks());

    it('usa o fallback sem preferência salva e persiste a escolha', () => {
        const { view, setView } = useViewMode('x_view');
        expect(view.value).toBe('table');

        setView('cards');
        expect(view.value).toBe('cards');
        expect(window.localStorage.getItem('x_view')).toBe('cards');
        expect(useViewMode('x_view').view.value).toBe('cards');
    });

    it('ignora valor inválido salvo ou pedido', () => {
        window.localStorage.setItem('x_view', 'grid');
        const { view, setView } = useViewMode('x_view');
        expect(view.value).toBe('table');

        setView('grid');
        expect(view.value).toBe('table');
    });

    it('não quebra com storage bloqueado', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });

        const { view, setView } = useViewMode('x_view', 'cards');
        expect(view.value).toBe('cards');

        setView('table');
        expect(view.value).toBe('table');
    });
});

describe('useLocaleFormat (locale padrão pt-BR)', () => {
    const { money, number, date } = useLocaleFormat();

    it('formata moeda, número e data no padrão local', () => {
        expect(money(1234.5).replace(/\s/g, ' ')).toBe('R$ 1.234,50');
        expect(number(3.5, 1)).toBe('3,5');
        expect(number(1500)).toBe('1.500');
        expect(date('2026-09-26')).toBe('26/09/2026');
    });

    it('valor vazio ou inválido vira travessão', () => {
        expect(money(null)).toBe('—');
        expect(money('')).toBe('—');
        expect(number('abc')).toBe('—');
        expect(date(null)).toBe('—');
    });

    it('data que não é ISO volta como veio', () => {
        expect(date('26/09/2026')).toBe('26/09/2026');
    });
});
