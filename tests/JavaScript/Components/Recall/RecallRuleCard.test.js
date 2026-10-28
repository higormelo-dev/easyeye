import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import RecallRuleCard from '@/Components/Recall/RecallRuleCard.vue';
import { baseContent, ruleT } from '../../Panel/Manager/RecallProtocols/support/fixtures.js';

function card(content = baseContent(), props = {}) {
    return mount(RecallRuleCard, { props: { content, t: ruleT, ...props } });
}

describe('RecallRuleCard', () => {
    it('mostra os CID com ponto (H401 → H40.1) em cada lista e "Nenhum" para lista vazia', () => {
        const content = baseContent();
        content.entry.cid.weak = [];
        const wrapper = card(content);

        expect(wrapper.get('[data-testid="cid-strong"]').text()).toContain('H40.1');
        expect(wrapper.get('[data-testid="cid-strong"]').text()).toContain('H40.2');
        expect(wrapper.get('[data-testid="cid-exclude"]').text()).toBe('H40.0');
        expect(wrapper.get('[data-testid="cid-weak"]').text()).toBe('Nenhum');
        expect(wrapper.get('[data-testid="medications"]').text()).toBe('Nenhum');
    });

    it('mostra o nome da medicação pelo rótulo, não pela chave', () => {
        const content = baseContent();
        content.entry.medications = ['hydroxychloroquine'];

        expect(card(content).get('[data-testid="medications"]').text()).toBe('Hidroxicloroquina');
    });

    it('tarefa: nome pelo exame ou pelo tipo, intervalo padrão, faixa e prazos', () => {
        const wrapper = card();
        const consulta = wrapper.get('[data-testid="task-consulta"]');
        const campimetria = wrapper.get('[data-testid="task-campimetria"]');

        expect(consulta.text()).toContain('Consulta');
        expect(consulta.text()).toContain('Padrão: 4 meses');
        expect(consulta.text()).toContain('A clínica pode usar de 3 a 6 meses');
        expect(consulta.text()).toContain('Convite 30 dias antes do vencimento');
        expect(consulta.text()).toContain('Tolerância de 30 dias depois do vencimento');
        expect(consulta.text()).toContain('Perdido de vista após 180 dias de atraso');
        expect(consulta.text()).toContain('Obrigatória');
        expect(consulta.text()).toContain('Repete');

        expect(campimetria.text()).toContain('Campo visual (campimetria)');
        expect(campimetria.text()).toContain('Opcional (a clínica escolhe)');
    });

    it('singular e zero: "1 mês", "1 dia", convite só no vencimento, sem tolerância', () => {
        const content = baseContent();
        content.tasks[0].interval = { unit: 'months', default: 1, min: 1, max: 1 };
        content.tasks[0].lead_days = 1;
        content.tasks[1].lead_days = 0;
        content.tasks[1].grace_days = 0;
        const wrapper = card(content);

        expect(wrapper.get('[data-testid="task-consulta"]').text()).toContain('Padrão: 1 mês');
        expect(wrapper.get('[data-testid="task-consulta"]').text()).toContain('Convite 1 dia antes do vencimento');
        expect(wrapper.get('[data-testid="task-campimetria"]').text()).toContain('Convite só no vencimento');
        expect(wrapper.get('[data-testid="task-campimetria"]').text()).toContain('Sem tolerância');
    });

    it('começa depois de N meses e tarefa de uma única vez', () => {
        const content = baseContent();
        content.tasks[1].starts_after_months = 60;
        content.tasks[1].recurs = false;
        const campimetria = card(content).get('[data-testid="task-campimetria"]');

        expect(campimetria.text()).toContain('Começa 60 meses depois do início da condição');
        expect(campimetria.text()).toContain('Uma única vez');
    });

    it('pedido do médico: texto conforme o limite', () => {
        const content = baseContent();

        expect(card(content).get('[data-testid="doctor-order"]').text()).toContain('não passa do máximo');

        content.doctor_order.cap_to_max = false;

        expect(card(content).get('[data-testid="doctor-order"]').text()).toContain('vale como está');
    });

    it('justificativa preserva quebras de linha e nota de mudança só aparece quando existe', () => {
        const content = baseContent();
        const wrapper = card(content);

        expect(wrapper.get('[data-testid="rationale"]').text()).toBe('Linha um.\nLinha dois.');
        expect(wrapper.get('[data-testid="rationale"]').attributes('style')).toContain('pre-line');
        expect(wrapper.find('[data-testid="change-note"]').exists()).toBe(false);

        content.change_note = 'Mudou o intervalo.';

        expect(card(content).get('[data-testid="change-note"]').text()).toBe('Mudou o intervalo.');
    });

    it('referências: ano ou "s/d", selo de evidência e observação', () => {
        const references = card().findAll('[data-testid="reference"]');

        expect(references).toHaveLength(2);
        expect(references[0].text()).toContain('Fonte A. Ophthalmology 2021');
        expect(references[0].text()).toContain('(2021)');
        expect(references[0].text()).toContain('Evidência indireta');
        expect(references[0].text()).toContain('Observação da fonte.');
        expect(references[1].text()).toContain('(s/d)');
        expect(references[1].text()).toContain('Opinião / consenso');
    });

    it('sem tarefas e sem referências mostra os avisos', () => {
        const content = baseContent();
        content.tasks = [];
        content.references = [];
        const wrapper = card(content);

        expect(wrapper.text()).toContain('Nenhuma tarefa.');
        expect(wrapper.text()).toContain('Nenhuma referência.');
    });

    it('as explicações somem no modo compacto', () => {
        expect(card().text()).toContain('Entra como condição ativa.');
        expect(card(baseContent(), { showHints: false }).text()).not.toContain('Entra como condição ativa.');
    });

    it('texto vindo do conteúdo nunca vira HTML', () => {
        const content = baseContent();
        content.rationale = '<script>window.hacked = true</script><b>negrito</b>';
        content.references[0].title = '<img src=x onerror="window.hacked=true">';
        const wrapper = card(content);

        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.get('[data-testid="rationale"]').text()).toContain('<b>negrito</b>');
    });
});
