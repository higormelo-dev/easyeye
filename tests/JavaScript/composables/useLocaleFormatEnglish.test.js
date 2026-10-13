import { describe, it, expect, vi } from 'vitest';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * EE-14: locale 'en' (prop Inertia `locale`) tem que mudar o FORMATO
 * (separador decimal, ordem mês/dia, ano com 2 dígitos — convenção ICU do
 * próprio 'en' p/ dateStyle:'short') sem mudar a moeda do dado (clínica no
 * Brasil continua em BRL mesmo com a UI em inglês — ver comentário no
 * próprio useLocaleFormat.js). Arquivo separado de
 * tests/JavaScript/composables/viewAndFormat.test.js (que cobre o padrão
 * pt-BR) porque vi.mock é por arquivo — não dá pra alternar locale no
 * mesmo arquivo com um mock estático.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en' } }),
}));

describe('useLocaleFormat (locale en)', () => {
    const { money, number, date, dateTime } = useLocaleFormat();

    it('formata moeda em BRL mas no formato en (separador de milhar/decimal invertido)', () => {
        expect(money(1234.5)).toBe('R$1,234.50');
    });

    it('formata número no padrão en (separador de milhar/decimal invertido)', () => {
        expect(number(3.5, 1)).toBe('3.5');
        expect(number(1500)).toBe('1,500');
    });

    it('formata data no padrão en (mês/dia/ano)', () => {
        expect(date('2026-09-26')).toBe('9/26/26');
    });

    it('formata data e hora no padrão en', () => {
        expect(dateTime('2026-09-26T14:30:00')).toBe('9/26/26, 2:30 PM');
    });
});
