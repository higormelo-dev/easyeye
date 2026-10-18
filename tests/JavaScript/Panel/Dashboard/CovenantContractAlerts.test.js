import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import CovenantContractAlerts from '@/Pages/Panel/Dashboard/CovenantContractAlerts.vue';

const t = {
    covenant_contracts_title: 'Contratos',
    covenant_contracts_expired_badge: 'Vencido',
    covenant_contracts_expired: 'Venceu em :date',
    covenant_contracts_today: 'Vence hoje (:date)',
    covenant_contracts_expiring: 'Vence em :days dias (:date)',
    covenant_contracts_more: '+ :count convênios',
    covenant_contracts_open: 'Ver convênios',
};
describe('CovenantContractAlerts', () => {
    it('mostra vencido, excedente e link apenas quando permitido', () => {
        const wrapper = mount(CovenantContractAlerts, {
            props: {
                t,
                data: {
                    url: '/convenios',
                    overflow: 2,
                    items: [{ id: '1', name: 'Plano A', expired: true, ends_at_label: '1 de janeiro', days_until: -1 }],
                },
            },
        });
        expect(wrapper.get('[role="alert"]').text()).toContain('Vencido');
        expect(wrapper.text()).toContain('+ 2 convênios');
        expect(wrapper.get('a').attributes('href')).toBe('/convenios');
    });
});
