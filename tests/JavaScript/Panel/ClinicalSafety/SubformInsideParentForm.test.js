import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import PatientAllergiesPanel from '@/Components/Panel/ClinicalSafety/PatientAllergiesPanel.vue';
import PatientMedicationsPanel from '@/Components/Panel/ClinicalSafety/PatientMedicationsPanel.vue';
import PersonalTemplatesBar from '@/Components/Panel/Documents/PersonalTemplatesBar.vue';
import { summary, fakeStore } from './fixtures.js';

/**
 * Revisão Codex P2-3 — os painéis ficam DENTRO do <form> do cadastro do
 * paciente (gaveta Pacientes e Agenda). Antes eram <form> aninhados: o submit
 * subia e salvar a alergia salvava o paciente inteiro (e fechava a gaveta).
 */
function insideParentForm(component, props) {
    const parentSubmit = vi.fn();
    const Host = defineComponent({
        setup: () => () =>
            h('form', { 'data-testid': 'parent', onSubmit: (e) => (e.preventDefault(), parentSubmit()) }, [
                h(component, props),
                h('button', { type: 'submit', 'data-testid': 'parent-save' }, 'Salvar paciente'),
            ]),
    });
    const wrapper = mount(Host, { attachTo: document.body });
    return { wrapper, parentSubmit };
}

let mounted = null;
afterEach(() => {
    mounted?.unmount();
    mounted = null;
});

describe('Painéis clínicos dentro do formulário do paciente', () => {
    it('não há <form> dentro do <form> do paciente', async () => {
        const { wrapper } = insideParentForm(PatientAllergiesPanel, { store: fakeStore(summary(), vi) });
        mounted = wrapper;
        await wrapper.find('[data-testid="allergy-add"]').trigger('click');

        expect(wrapper.findAll('form')).toHaveLength(1);
    });

    it('salvar a alergia não envia o formulário do paciente', async () => {
        const store = fakeStore(summary(), vi);
        const { wrapper, parentSubmit } = insideParentForm(PatientAllergiesPanel, { store });
        mounted = wrapper;

        await wrapper.find('[data-testid="allergy-add"]').trigger('click');
        await wrapper.find('[data-testid="allergy-substance"]').setValue('Látex');
        wrapper.find('[data-testid="allergy-save"]').element.click();
        await flushPromises();

        expect(store.addAllergy).toHaveBeenCalledTimes(1);
        expect(parentSubmit).not.toHaveBeenCalled();

        // O botão do próprio formulário segue funcionando.
        wrapper.find('[data-testid="parent-save"]').element.click();
        expect(parentSubmit).toHaveBeenCalledTimes(1);
    });

    it('Enter no campo salva só a alergia (sem envio implícito do pai)', async () => {
        const store = fakeStore(summary(), vi);
        const { wrapper, parentSubmit } = insideParentForm(PatientAllergiesPanel, { store });
        mounted = wrapper;

        await wrapper.find('[data-testid="allergy-add"]').trigger('click');
        const input = wrapper.find('[data-testid="allergy-substance"]');
        await input.setValue('Iodo');
        const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        input.element.dispatchEvent(event);
        await flushPromises();

        expect(store.addAllergy).toHaveBeenCalledTimes(1);
        expect(store.addAllergy.mock.calls[0][0].substance).toBe('Iodo');
        // preventDefault no Enter = o navegador não envia o formulário do paciente.
        expect(event.defaultPrevented).toBe(true);
        expect(parentSubmit).not.toHaveBeenCalled();
    });

    it('Enter na busca do catálogo com a lista aberta escolhe o item — não salva', async () => {
        const store = fakeStore(summary(), vi);
        const { wrapper } = insideParentForm(PatientAllergiesPanel, { store, searchUrl: '/medicines' });
        mounted = wrapper;

        await wrapper.find('[data-testid="allergy-add"]').trigger('click');
        await wrapper.find('[data-testid="allergy-catalog-search"]').trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(store.addAllergy).not.toHaveBeenCalled();
    });

    it('medicação: salvar e Enter não enviam o formulário do paciente', async () => {
        const store = fakeStore(summary(), vi);
        const { wrapper, parentSubmit } = insideParentForm(PatientMedicationsPanel, { store });
        mounted = wrapper;

        await wrapper.find('[data-testid="medication-add"]').trigger('click');
        expect(wrapper.findAll('form')).toHaveLength(1);
        await wrapper.find('[data-testid="medication-search"]').setValue('Secotex');
        wrapper.find('[data-testid="medication-save"]').element.click();
        await flushPromises();

        expect(store.addMedication).toHaveBeenCalledTimes(1);
        expect(parentSubmit).not.toHaveBeenCalled();
    });

    it('modelo pessoal: confirmar o nome não envia o formulário em volta', async () => {
        globalThis.fetch = vi.fn((url, init) =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve(init?.method === 'POST' ? { data: { id: 'n1' } } : { data: [] }),
            }),
        );
        const { wrapper, parentSubmit } = insideParentForm(PersonalTemplatesBar, {
            url: '/tpl',
            itemUrlTemplate: '/tpl/__ID__',
            build: () => ({ body: 'x' }),
        });
        mounted = wrapper;
        await flushPromises();

        await wrapper.find('[data-testid="personal-templates-save-as"]').trigger('click');
        expect(wrapper.findAll('form')).toHaveLength(1);
        const input = wrapper.find('[data-testid="personal-templates-name"]');
        await input.setValue('Meu modelo');
        input.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
        await flushPromises();

        expect(globalThis.fetch.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(true);
        expect(parentSubmit).not.toHaveBeenCalled();
    });
});
