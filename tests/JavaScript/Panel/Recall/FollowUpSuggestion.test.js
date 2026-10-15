import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import FollowUpSuggestion from '@/Components/Panel/Recall/FollowUpSuggestion.vue';
import followUpT from './support/recall_followup.json';

const suggestion = (overrides = {}) => ({
    protocol_key: 'glaucoma',
    title: 'Glaucoma',
    eye: 'both',
    eye_label: 'Ambos os olhos (AO)',
    interval: 4,
    unit: 'months',
    unit_label: 'meses',
    days: 120,
    ...overrides,
});

const mountIt = (props = {}) =>
    mount(FollowUpSuggestion, {
        props: { suggestions: [suggestion()], maxDays: 181, t: followUpT, current: null, ...props },
    });

describe('Sugestão do campo Retorno', () => {
    it('mostra a frase de cada protocolo (condição, olho, intervalo, dias) e o botão "Usar N dias"', () => {
        const wrapper = mountIt({
            suggestions: [suggestion(), suggestion({ protocol_key: 'dmri', title: 'DMRI', interval: 6, days: 181 })],
        });
        const glaucoma = wrapper.find('[data-testid="follow-up-glaucoma"]');

        expect(wrapper.find('.fw-semibold').text()).toBe('Sugestão dos protocolos de acompanhamento');
        expect(glaucoma.text()).toContain(
            'Glaucoma (Ambos os olhos (AO)): consulta a cada 4 meses (cerca de 120 dias)',
        );
        expect(glaucoma.find('[data-testid="follow-up-use"]').text()).toBe('Usar 120 dias');
        expect(wrapper.find('[data-testid="follow-up-dmri"] [data-testid="follow-up-use"]').text()).toBe(
            'Usar 181 dias',
        );
    });

    it('"Usar" emite os dias — não preenche nada sozinho', async () => {
        const wrapper = mountIt();

        expect(wrapper.emitted('use')).toBeUndefined();

        await wrapper.find('[data-testid="follow-up-use"]').trigger('click');

        expect(wrapper.emitted('use')).toEqual([[120]]);
    });

    it('a sugestão que já está no campo vira "Valor atual" (sem botão); o campo vem como número ou texto', async () => {
        const wrapper = mountIt({ current: 120 });

        expect(wrapper.find('[data-testid="follow-up-use"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Valor atual');

        await wrapper.setProps({ current: '120' });
        expect(wrapper.text()).toContain('Valor atual');

        await wrapper.setProps({ current: 90 });
        expect(wrapper.find('[data-testid="follow-up-use"]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Valor atual');
    });

    it('bloqueado (prontuário assinado): o botão fica desabilitado', () => {
        const wrapper = mountIt({ disabled: true });

        expect(wrapper.find('[data-testid="follow-up-use"]').attributes('disabled')).toBeDefined();
    });

    it('o teto validado aparece; passou dele, o aviso vira o do valor digitado', async () => {
        const wrapper = mountIt({ maxDays: 181, current: 90 });
        const cap = () => wrapper.find('[data-testid="follow-up-cap"]').text();

        expect(cap()).toBe(
            'Retorno acima de 181 dias vale 181 dias na lista de retornos (máximo validado do protocolo).',
        );

        await wrapper.setProps({ current: 181 }); // no limite ainda vale
        expect(cap()).toContain('Retorno acima de 181 dias');

        await wrapper.setProps({ current: 182 });
        expect(cap()).toBe(
            'O valor passa de 181 dias: na lista de retornos ele vale 181 dias (máximo validado do protocolo).',
        );

        await wrapper.setProps({ current: '' });
        expect(cap()).toContain('Retorno acima de 181 dias');
    });

    it('sem teto (protocolo sem cap_to_max), nenhum aviso de limite', () => {
        const wrapper = mountIt({ maxDays: null, current: 999 });

        expect(wrapper.find('[data-testid="follow-up-cap"]').exists()).toBe(false);
    });

    it('valor inválido no campo (0, negativo, decimal, texto) não conta como valor atual nem como acima do teto', () => {
        for (const current of [0, -5, 1.5, 200.5, 'abc', null, undefined]) {
            const wrapper = mountIt({ current });

            expect(wrapper.text()).not.toContain('Valor atual');
            expect(wrapper.find('[data-testid="follow-up-cap"]').text()).toContain('Retorno acima de 181 dias');
        }
    });
});
