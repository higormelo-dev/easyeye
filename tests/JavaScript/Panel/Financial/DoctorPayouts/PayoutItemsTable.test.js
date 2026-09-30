import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import PayoutItemsTable from '@/Pages/Panel/Financial/DoctorPayouts/PayoutItemsTable.vue';
import { t, itemRows, brl } from './fixtures.js';

/**
 * Divisão na linha do item (E4): sob a regra, o papel e a % do grupo quando
 * o item é dividido e o líquido depois das deduções; executor com 100% do
 * grupo e item sem deduções não repetem informação.
 */

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

const [base] = itemRows;

function mountTable(rows) {
    wrapper = mount(PayoutItemsTable, { props: { rows, t } });

    return wrapper;
}

describe('Financial/DoctorPayouts/PayoutItemsTable — divisão (E4)', () => {
    it('médico fixo: papel, % do grupo e líquido com as deduções', () => {
        const w = mountTable([{
            ...base, row_id: 'r1', beneficiary_role: 'doctor', share_percentage: 60, net: 940, deductions: 60, payout: 338.4,
        }]);

        const rule = w.find('[data-test="item-rule"]');
        expect(rule.find('[data-test="item-share"]').text()).toBe('Participant · 60% of the group');
        expect(rule.find('[data-test="item-net"]').text()).toBe(`Net ${brl(940)} (deductions ${brl(60)})`);
    });

    it('executor com parte do grupo mostra o papel; com 100% (sem divisão) não', () => {
        const w = mountTable([
            { ...base, row_id: 'r1', beneficiary_role: 'executor', share_percentage: 40, net: 1000, deductions: 0 },
            { ...base, row_id: 'r2', key: 'schedule:s2', beneficiary_role: 'executor', share_percentage: 100, net: 1000, deductions: 0 },
        ]);

        const [split, whole] = w.findAll('[data-test="item-row"]');
        expect(split.find('[data-test="item-share"]').text()).toBe('Performer · 40% of the group');
        expect(whole.find('[data-test="item-share"]').exists()).toBe(false);
        // Sem deduções o líquido é o próprio recebido: não repete.
        expect(split.find('[data-test="item-net"]').exists()).toBe(false);
    });

    it('item do regime anterior / regra fixa (sem papel nem líquido): só a regra', () => {
        const w = mountTable([{ ...base, row_id: 'r1', beneficiary_role: null, share_percentage: null, net: null, deductions: 0 }]);

        const rule = w.find('[data-test="item-rule"]');
        expect(rule.text()).toBe('60% of net received');
        expect(rule.find('[data-test="item-share"]').exists()).toBe(false);
        expect(rule.find('[data-test="item-net"]').exists()).toBe(false);
    });

    it('sem regra continua destacado em vermelho', () => {
        const w = mountTable([{ ...itemRows[1], row_id: 'r1' }]);

        const label = w.find('[data-test="item-rule"] span');
        expect(label.text()).toBe('No rule');
        expect(label.classes()).toEqual(expect.arrayContaining(['text-danger', 'fw-medium']));
    });
});

describe('Financial/DoctorPayouts/PayoutItemsTable — conferência (lacunas do pedido)', () => {
    it('regra aplicada: QUAL regra venceu (escopo) e o rótulo conforme o regime (recebido líquido × cobrado)', () => {
        const w = mountTable([
            { ...base, row_id: 'r1', basis: 'receipt', rule_scope: 'All doctors · Consultation · Any payer' },
            { ...base, row_id: 'r2', key: 'schedule:old', basis: 'production' },
        ]);
        const [receipt, production] = w.findAll('[data-test="item-rule"]');

        expect(receipt.find('[data-test="item-rule-scope"]').text()).toBe('All doctors · Consultation · Any payer');
        expect(receipt.find('span').text()).toBe('60% of net received');
        expect(production.find('span').text()).toBe('60% of the charged amount');
    });

    it('regra fixa com recebimento parcial mostra a proporção; esperado (cobrado − glosa) aparece quando difere do recebido', () => {
        const w = mountTable([{
            ...base, row_id: 'r1', basis: 'receipt', status: 'pending', received: 120, expected: 200,
            rule: { calculation: 'fixed', percentage: null, fixed: 100 }, payout: 60,
        }]);

        expect(w.find('[data-test="item-fixed-proportional"]').text()).toBe(`Proportional to receipt: ${brl(120)} of ${brl(200)}`);
        expect(w.find('[data-test="item-expected"]').text()).toBe(`Expected ${brl(200)} (charged − denial)`);
    });

    it('recebido completo: sem proporção nem esperado repetido', () => {
        const w = mountTable([{
            ...base, row_id: 'r1', basis: 'receipt', status: 'pending', received: 200, expected: 200,
            rule: { calculation: 'fixed', percentage: null, fixed: 100 }, payout: 100,
        }]);

        expect(w.find('[data-test="item-fixed-proportional"]').exists()).toBe(false);
        expect(w.find('[data-test="item-expected"]').exists()).toBe(false);
    });

    it('deduções detalhadas por tipo sob o líquido (acumulado do atendimento)', () => {
        const w = mountTable([{
            ...base, row_id: 'r1', net: 880, deductions: 120, deductions_breakdown: { card: 30, tax: 70, admin: 20 },
        }]);

        const detail = w.find('[data-test="item-deductions"]');
        expect(detail.text()).toBe(`Card ${brl(30)} · Tax ${brl(70)} · Admin fee ${brl(20)}`);
        expect(detail.attributes('title')).toBe('Accumulated deductions of the attendance');
    });

    it('coluna Recebimento traz também o valor cobrado do atendimento', () => {
        wrapper = mount(PayoutItemsTable, { props: { rows: [{ ...base, row_id: 'r1' }], t, showReceipt: true } });

        expect(wrapper.find('[data-test="item-billed"]').text()).toBe(`Charged ${brl(300)}`);
    });
});
