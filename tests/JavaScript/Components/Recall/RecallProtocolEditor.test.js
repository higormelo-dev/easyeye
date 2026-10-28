import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import RecallProtocolEditor from '@/Components/Recall/RecallProtocolEditor.vue';
import { baseContent, managerT, options } from '../../Panel/Manager/RecallProtocols/support/fixtures.js';

function editor(content = baseContent(), props = {}) {
    return mount(RecallProtocolEditor, {
        props: { modelValue: content, options, t: managerT, ...props },
        attachTo: document.body,
    });
}

async function lastContent(wrapper) {
    await nextTick();
    const events = wrapper.emitted('update:modelValue');

    return events[events.length - 1][0];
}

const taskKey = (wrapper, index) => wrapper.get(`[data-testid="task-${index}-key"]`);

describe('RecallProtocolEditor', () => {
    it('mostra os valores do conteúdo nos campos', () => {
        const wrapper = editor();

        expect(taskKey(wrapper, 0).element.value).toBe('consulta');
        expect(wrapper.get('[data-testid="task-0-interval-default"]').element.value).toBe('4');
        expect(wrapper.get('[data-testid="task-0-interval-min"]').element.value).toBe('3');
        expect(wrapper.get('[data-testid="task-0-interval-max"]').element.value).toBe('6');
        expect(wrapper.get('[data-testid="task-0-lead_days"]').element.value).toBe('30');
        expect(wrapper.get('[data-testid="cid-strong"]').element.value).toBe('H40.1, H40.2');
        expect(wrapper.get('[data-testid="cid-weak"]').element.value).toBe('H40');
        expect(wrapper.get('[data-testid="rationale"]').element.value).toBe('Linha um.\nLinha dois.');
        expect(wrapper.get('[data-testid="cap-to-max"]').element.checked).toBe(true);
    });

    it('edita sem mexer na prop: devolve uma cópia nova com a mudança', async () => {
        const original = baseContent();
        const wrapper = editor(original);

        await taskKey(wrapper, 0).setValue('consulta_geral');
        const emitted = await lastContent(wrapper);

        expect(emitted.tasks[0].key).toBe('consulta_geral');
        expect(original.tasks[0].key).toBe('consulta');
        expect(emitted).not.toBe(original);
    });

    it('CID: o texto digitado vira lista (maiúsculo, sem ponto/†/*, sem repetição) e o blur formata', async () => {
        const wrapper = editor();
        const field = wrapper.get('[data-testid="cid-strong"]');

        await field.setValue('h40.1, H40.2;  e10.3† h40.1\nH36.0*');

        expect((await lastContent(wrapper)).entry.cid.strong).toEqual(['H401', 'H402', 'E103', 'H360']);

        await field.trigger('blur');

        expect(field.element.value).toBe('H40.1, H40.2, E10.3, H36.0');
    });

    it('medicações: marcar e desmarcar mantém a lista ordenada e sem repetição', async () => {
        const wrapper = editor();

        await wrapper.get('[data-testid="medication-chloroquine"]').setValue(true);
        await wrapper.get('[data-testid="medication-hydroxychloroquine"]').setValue(true);

        expect((await lastContent(wrapper)).entry.medications).toEqual(['chloroquine', 'hydroxychloroquine']);

        await wrapper.get('[data-testid="medication-chloroquine"]').setValue(false);

        expect((await lastContent(wrapper)).entry.medications).toEqual(['hydroxychloroquine']);
    });

    it('adiciona tarefa com os padrões do esquema e respeita o limite', async () => {
        const wrapper = editor();

        await wrapper.get('[data-testid="add-task"]').trigger('click');
        const added = (await lastContent(wrapper)).tasks[2];

        expect(added).toMatchObject({
            key: '',
            kind: 'consultation',
            exam_key: null,
            interval: { unit: 'months', default: 6, min: 3, max: 12 },
            lead_days: 30,
            grace_days: 30,
            lost_after_days: 180,
            starts_after_months: null,
            recurs: true,
            required: true,
        });

        const full = baseContent();
        full.tasks = Array.from({ length: 8 }, (_, i) => ({ ...full.tasks[0], key: `tarefa_${i}` }));

        expect(editor(full).get('[data-testid="add-task"]').attributes('disabled')).toBeDefined();
    });

    it('remove e reordena tarefas', async () => {
        const wrapper = editor();

        await wrapper.findAll('button[aria-label="Subir"]')[1].trigger('click');
        expect((await lastContent(wrapper)).tasks.map((t) => t.key)).toEqual(['campimetria', 'consulta']);

        await wrapper.get('[data-testid="remove-task-0"]').trigger('click');
        expect((await lastContent(wrapper)).tasks.map((t) => t.key)).toEqual(['consulta']);
    });

    it('os botões de subir/descer ficam desabilitados nas pontas', () => {
        const wrapper = editor();

        expect(wrapper.findAll('button[aria-label="Subir"]')[0].attributes('disabled')).toBeDefined();
        expect(wrapper.findAll('button[aria-label="Descer"]')[1].attributes('disabled')).toBeDefined();
        expect(wrapper.findAll('button[aria-label="Subir"]')[1].attributes('disabled')).toBeUndefined();
    });

    it('tipo exame pede o exame; trocar para consulta limpa o exame e some o campo', async () => {
        const wrapper = editor();
        const kind = wrapper.get('#' + taskKey(wrapper, 0).attributes('id').replace('-key', '-kind'));

        expect(wrapper.find('select[id$="tasks-0-exam_key"]').exists()).toBe(false);

        await kind.setValue('exam');
        expect((await lastContent(wrapper)).tasks[0].exam_key).toBe('visual_field');
        expect(wrapper.find('select[id$="tasks-0-exam_key"]').exists()).toBe(true);

        await kind.setValue('consultation');
        expect((await lastContent(wrapper)).tasks[0].exam_key).toBeNull();
        expect(wrapper.find('select[id$="tasks-0-exam_key"]').exists()).toBe(false);
    });

    it('"começa após" vazio vira null e número vira inteiro', async () => {
        const wrapper = editor();
        const field = wrapper.get('[data-testid="task-0-starts_after_months"]');

        await field.setValue('60');
        expect((await lastContent(wrapper)).tasks[0].starts_after_months).toBe(60);

        await field.setValue('');
        expect((await lastContent(wrapper)).tasks[0].starts_after_months).toBeNull();
    });

    it('campos numéricos obrigatórios viram número', async () => {
        const wrapper = editor();

        await wrapper.get('[data-testid="task-0-interval-default"]').setValue('5');
        await wrapper.get('[data-testid="task-0-grace_days"]').setValue('45');
        const emitted = await lastContent(wrapper);

        expect(emitted.tasks[0].interval.default).toBe(5);
        expect(emitted.tasks[0].grace_days).toBe(45);
    });

    it('erros do servidor aparecem no campo, anunciados, com aria-invalid e aria-describedby', () => {
        const wrapper = editor(baseContent(), {
            errors: {
                'content.tasks.0.key': 'Chave inválida',
                'content.tasks.0.interval': 'O mínimo deve ser menor ou igual ao padrão.',
                'content.rationale': 'Campo obrigatório.',
                'content.entry.cid.strong.0': 'CID inválido: XX',
                'content.entry': 'Informe ao menos um CID forte.',
            },
        });

        const key = taskKey(wrapper, 0);
        const keyError = wrapper.get(`#${key.attributes('aria-describedby')}`);

        expect(key.attributes('aria-invalid')).toBe('true');
        expect(key.classes()).toContain('is-invalid');
        expect(keyError.text()).toBe('Chave inválida');
        expect(keyError.attributes('role')).toBe('alert');

        expect(wrapper.get('[data-testid="task-0-interval-default"]').attributes('aria-invalid')).toBe('true');
        expect(wrapper.text()).toContain('O mínimo deve ser menor ou igual ao padrão.');

        const rationale = wrapper.get('[data-testid="rationale"]');
        expect(rationale.attributes('aria-invalid')).toBe('true');
        expect(wrapper.get(`#${rationale.attributes('aria-describedby')}`).text()).toBe('Campo obrigatório.');

        expect(wrapper.get('[data-testid="cid-strong"]').attributes('aria-invalid')).toBe('true');
        expect(wrapper.text()).toContain('CID inválido: XX');
        expect(wrapper.get('[data-testid="entry-error"]').text()).toBe('Informe ao menos um CID forte.');
    });

    it('sem erros, nenhum campo é marcado como inválido', () => {
        const wrapper = editor();

        expect(wrapper.findAll('[aria-invalid="true"]')).toHaveLength(0);
        expect(wrapper.findAll('.invalid-feedback')).toHaveLength(0);
    });

    it('desabilitado, o fieldset trava todos os campos', () => {
        const wrapper = editor(baseContent(), { disabled: true });

        expect(wrapper.get('fieldset[data-testid="recall-editor"]').attributes('disabled')).toBeDefined();
    });

    it('referências: adiciona, edita o ano (vazio = null) e remove', async () => {
        const wrapper = editor();

        await wrapper.get('[data-testid="add-reference"]').trigger('click');
        let emitted = await lastContent(wrapper);
        expect(emitted.references).toHaveLength(3);
        expect(emitted.references[2]).toEqual({ title: '', year: null, evidence: 'medium', note: '' });

        const year = wrapper.get('[data-testid="reference-0"] input[type="number"]');
        await year.setValue('');
        emitted = await lastContent(wrapper);
        expect(emitted.references[0].year).toBeNull();

        await year.setValue('2024');
        expect((await lastContent(wrapper)).references[0].year).toBe(2024);

        await wrapper.get('[data-testid="remove-reference-0"]').trigger('click');
        expect((await lastContent(wrapper)).references.map((r) => r.title)).toEqual(['Fonte B', '']);
    });

    it('nota de mudança: obrigatória só em versão derivada', () => {
        const plain = editor();
        const derived = editor(baseContent(), { requiresChangeNote: true });

        expect(plain.get('[data-testid="change-note"]').attributes('aria-required')).toBeUndefined();
        expect(derived.get('[data-testid="change-note"]').attributes('aria-required')).toBe('true');
    });

    it('quando a página devolve outro conteúdo (ex.: depois de salvar), os campos acompanham sem laço', async () => {
        const wrapper = editor();
        await taskKey(wrapper, 0).setValue('x');
        await nextTick();
        const before = wrapper.emitted('update:modelValue').length;

        const fresh = baseContent();
        fresh.tasks[0].key = 'vindo_do_servidor';
        fresh.entry.cid.strong = ['H405'];
        await wrapper.setProps({ modelValue: fresh });
        await nextTick();

        expect(taskKey(wrapper, 0).element.value).toBe('vindo_do_servidor');
        expect(wrapper.get('[data-testid="cid-strong"]').element.value).toBe('H40.5');
        // O novo valor vem da prop: a sincronização não pode virar um laço de emissões.
        expect(wrapper.emitted('update:modelValue').length).toBeLessThanOrEqual(before + 1);
    });

    it('contador de caracteres restantes da justificativa', () => {
        const wrapper = editor();

        expect(wrapper.text()).toContain(`${2000 - 'Linha um.\nLinha dois.'.length} caracteres restantes`);
    });
});
