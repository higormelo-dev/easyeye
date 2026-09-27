import { describe, it, expect } from 'vitest';
import { cleanParams, tablePriceInfo, withQuery } from '@/Pages/Panel/Financial/Billing/billingHelpers.js';

describe('billingHelpers', () => {
    it('cleanParams tira vazios (a URL só leva filtro aplicado)', () => {
        expect(cleanParams({ a: '1', b: '', c: null, d: undefined, e: 0 })).toEqual({ a: '1', e: 0 });
    });

    it('withQuery acrescenta parâmetros a URL absoluta ou relativa, codificados', () => {
        expect(withQuery('/glosas', { search: 'GUI-1 %' })).toBe('/glosas?search=GUI-1+%25');
        expect(withQuery('https://x.test/glosas?tab=all', { search: 'A' })).toBe('https://x.test/glosas?tab=all&search=A');
        expect(withQuery('/glosas', { search: '' })).toBe('/glosas');
    });

    it('tablePriceInfo: único preço, faixa de preços diferentes ou nenhum', () => {
        expect(tablePriceInfo([{ suggested_price: 150 }, { suggested_price: 150 }, { suggested_price: null }]))
            .toEqual({ min: 150, max: 150, single: 150, priced: 2 });
        expect(tablePriceInfo([{ suggested_price: 150 }, { suggested_price: 180 }]))
            .toEqual({ min: 150, max: 180, single: null, priced: 2 });
        expect(tablePriceInfo([{ suggested_price: null }])).toBeNull();
        expect(tablePriceInfo([])).toBeNull();
    });
});
