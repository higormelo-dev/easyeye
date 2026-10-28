import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import RecallTimelineExamples from '@/Components/Recall/RecallTimelineExamples.vue';
import { ruleT, exampleRows } from '../../Panel/Manager/RecallProtocols/support/fixtures.js';

const examples = (rows = exampleRows(), anchor = '2026-01-15') =>
    mount(RecallTimelineExamples, { props: { examples: rows, anchor, t: ruleT } });

describe('RecallTimelineExamples', () => {
    it('mostra a data do exemplo e as cinco datas calculadas, no formato do idioma', () => {
        const wrapper = examples();
        const row = wrapper.get('[data-testid="example-consulta"]');

        expect(wrapper.text()).toContain('Se o último atendimento foi em 15/01/2026');
        expect(row.text()).toContain('Consulta');
        expect(row.text()).toContain('15/05/2026');
        expect(row.text()).toContain('15/04/2026');
        expect(row.text()).toContain('14/06/2026');
        expect(row.text()).toContain('11/11/2026');
    });

    it('a coluna "Começa em" só existe quando alguma tarefa tem início adiado', () => {
        expect(examples().text()).not.toContain('Começa em');

        const rows = exampleRows();
        rows[0].starts_at = '2031-01-15';
        const wrapper = examples(rows);

        expect(wrapper.text()).toContain('Começa em');
        expect(wrapper.text()).toContain('15/01/2031');
    });

    it('exame aparece pelo nome do exame', () => {
        const rows = exampleRows();
        rows[0].kind = 'exam';
        rows[0].exam_key = 'oct_macula';
        rows[0].key = 'oct';

        expect(examples(rows).get('[data-testid="example-oct"]').text()).toContain('OCT de mácula');
    });
});
