import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import RecallContentDiff from '@/Components/Recall/RecallContentDiff.vue';
import { ruleT, baseContent } from '../../Panel/Manager/RecallProtocols/support/fixtures.js';

const diff = (changes, props = {}) => mount(RecallContentDiff, { props: { changes, t: ruleT, ...props } });

describe('RecallContentDiff', () => {
    it('primeira versão: avisa que não há o que comparar', () => {
        const wrapper = diff([], { hasParent: false });

        expect(wrapper.get('[data-testid="diff-first"]').text()).toContain('Primeira versão');
        expect(wrapper.find('ul').exists()).toBe(false);
    });

    it('versão derivada sem mudança nas regras', () => {
        expect(diff([]).get('[data-testid="diff-none"]').text()).toContain('Nenhuma diferença');
    });

    it('CID acrescentado e retirado, com ponto e selo da operação', () => {
        const wrapper = diff([
            { section: 'cid.strong', op: 'added', value: 'H405' },
            { section: 'cid.exclude', op: 'removed', value: 'H400' },
        ]);

        const added = wrapper.get('[data-testid="diff-cid.strong-added"]');
        const removed = wrapper.get('[data-testid="diff-cid.exclude-removed"]');

        expect(added.text()).toContain('Acrescentado');
        expect(added.text()).toContain('H40.5');
        expect(added.get('.recall-tone').classes()).toContain('recall-tone--success');
        expect(removed.text()).toContain('Retirado');
        expect(removed.text()).toContain('H40.0');
        expect(removed.get('.recall-tone').classes()).toContain('recall-tone--danger');
    });

    it('medicação pelo rótulo', () => {
        const wrapper = diff([{ section: 'medication', op: 'added', value: 'chloroquine' }]);

        expect(wrapper.get('[data-testid="diff-medication-added"]').text()).toContain('Cloroquina');
    });

    it('campo de tarefa alterado: rótulo do campo e "de X para Y", com unidade e booleano legíveis', () => {
        const wrapper = diff([
            { section: 'task', op: 'changed', key: 'campimetria', field: 'interval.default', from: 12, to: 9 },
            { section: 'task', op: 'changed', key: 'campimetria', field: 'interval.unit', from: 'months', to: 'weeks' },
            { section: 'task', op: 'changed', key: 'oct', field: 'required', from: false, to: true },
            { section: 'task', op: 'changed', key: 'oct', field: 'exam_key', from: 'oct_macula', to: null },
        ]);

        const text = wrapper.text();

        expect(text).toContain('intervalo padrão: de 12 para 9');
        expect(text).toContain('unidade do intervalo: de meses para semanas');
        expect(text).toContain('obrigatória: de Não para Sim');
        expect(text).toContain('exame: de OCT de mácula para —');
        expect(wrapper.findAll('code').map((c) => c.text())).toContain('campimetria');
    });

    it('tarefa acrescentada mostra o resumo (tipo/exame e intervalo)', () => {
        const task = baseContent().tasks[1];
        const wrapper = diff([{ section: 'task', op: 'added', key: 'campimetria', value: task }]);

        expect(wrapper.get('[data-testid="diff-task-added"]').text()).toContain(
            'Campo visual (campimetria) — 12 meses',
        );
    });

    it('pedido do médico e referência', () => {
        const wrapper = diff([
            { section: 'doctor_order', op: 'changed', field: 'cap_to_max', from: true, to: false },
            { section: 'reference', op: 'changed', key: 'Fonte A', field: 'evidence', from: 'high', to: 'low' },
            { section: 'reference', op: 'added', key: 'Fonte C', value: { title: 'Fonte C' } },
        ]);

        expect(wrapper.text()).toContain('limitar ao máximo validado: de Sim para Não');
        expect(wrapper.text()).toContain('tipo de evidência: de Evidência direta para Opinião / consenso');
        expect(wrapper.get('[data-testid="diff-reference-added"]').text()).toContain('Fonte C');
    });

    it('justificativa: antes e depois lado a lado, sem interpretar HTML', () => {
        const wrapper = diff([{ section: 'rationale', op: 'changed', from: 'antes <b>x</b>', to: 'depois' }]);
        const item = wrapper.get('[data-testid="diff-rationale-changed"]');

        expect(item.text()).toContain('antes <b>x</b>');
        expect(item.text()).toContain('depois');
        expect(item.find('b').exists()).toBe(false);
    });
});
