import { describe, it, expect } from 'vitest';
import {
    addMonthsNoOverflow,
    choice,
    daysUntil,
    extendedEnd,
    savingsPercent,
    toDateInput,
} from '@/utils/billingPeriods.js';

/**
 * Prévias do manager precisam bater com o servidor
 * (SubscriptionManagementService::extendedEnd e PlanPricing::savingsPercent).
 */
describe('billingPeriods', () => {
    const now = new Date(2027, 0, 10, 12, 0, 0); // 10/01/2027 12:00 local

    it('soma ao término que ainda está no futuro', () => {
        expect(toDateInput(extendedEnd(new Date(2027, 1, 20), 'months', 3, now))).toBe('2027-05-20');
        expect(toDateInput(extendedEnd('2027-01-25T10:00:00', 'days', 10, now))).toBe('2027-02-04');
    });

    it('término vencido ou ausente conta a partir de hoje', () => {
        expect(toDateInput(extendedEnd(new Date(2026, 11, 1), 'days', 5, now))).toBe('2027-01-15');
        expect(toDateInput(extendedEnd(null, 'years', 1, now))).toBe('2028-01-10');
    });

    it('meses não transbordam (31/01 + 1 mês = último dia de fevereiro)', () => {
        expect(toDateInput(addMonthsNoOverflow(new Date(2027, 0, 31), 1))).toBe('2027-02-28');
        expect(toDateInput(addMonthsNoOverflow(new Date(2028, 0, 31), 1))).toBe('2028-02-29');
        expect(toDateInput(extendedEnd(new Date(2027, 0, 31, 23, 59), 'months', 1, now))).toBe('2027-02-28');
    });

    it('economia sobre o mensal arredonda para baixo e ignora ciclo mensal ou sem mensal', () => {
        expect(savingsPercent(299.9, 2878.99, 12)).toBe(20);
        expect(savingsPercent(299.9, 1619.46, 6)).toBe(10);
        expect(savingsPercent(100, 1250, 12)).toBe(0); // mais caro que o mensal
        expect(savingsPercent(100, 100, 1)).toBe(0);
        expect(savingsPercent(null, 2878.99, 12)).toBe(0);
    });

    it('plural no formato do Laravel com placeholders', () => {
        expect(choice('Falta :days dia|Faltam :days dias', 1, { days: 1 })).toBe('Falta 1 dia');
        expect(choice('Falta :days dia|Faltam :days dias', 12, { days: 12 })).toBe('Faltam 12 dias');
        expect(choice('Venceu há :days dia|Venceu há :days dias', -3, { days: 3 })).toBe('Venceu há 3 dias');
        expect(choice('sem plural', 5)).toBe('sem plural');
    });

    it('dias até a data contam dias de calendário', () => {
        expect(daysUntil(new Date(2027, 0, 10, 23, 0), now)).toBe(0);
        expect(daysUntil('2027-01-12T01:00:00', now)).toBe(2);
        expect(daysUntil(new Date(2027, 0, 7), now)).toBe(-3);
        expect(daysUntil(null, now)).toBeNull();
    });
});
