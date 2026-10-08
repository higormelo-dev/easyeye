import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PatientMedicationsPanel from '@/Components/Panel/ClinicalSafety/PatientMedicationsPanel.vue';
import { summary, fakeStore } from './fixtures.js';

/** Medicações em uso (Fase 2 / A2): lista do paciente com busca no catálogo CMED. */
describe('PatientMedicationsPanel', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve([
                        {
                            id: 'med-top',
                            name: 'TOPAMAX',
                            concentration: '25 MG',
                            active_ingredient: 'TOPIRAMATO',
                            laboratory: 'JANSSEN',
                        },
                    ]),
            }),
        );
    });

    it('busca no catálogo, escolhe o item (princípio ativo do catálogo, só leitura) e grava com medicine_id', async () => {
        const store = fakeStore(summary({ can_review: false }), vi);
        const w = mount(PatientMedicationsPanel, { props: { store, searchUrl: '/medicines/search' } });

        await w.find('[data-testid="medication-add"]').trigger('click');
        await w.find('[data-testid="medication-search"]').setValue('topam');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][0]).toBe('/medicines/search?q=topam');
        await w.find('[data-testid="medication-result"]').trigger('mousedown');

        const ingredient = w.find('[data-testid="medication-ingredient"]');
        expect(ingredient.element.value).toBe('TOPIRAMATO');
        expect(ingredient.attributes('readonly')).toBeDefined();

        await w.find('[data-testid="medication-save"]').trigger('click');
        await flushPromises();
        expect(store.addMedication.mock.calls[0][0]).toMatchObject({
            medicine_id: 'med-top',
            name: 'TOPAMAX 25 MG',
            active_ingredient: 'TOPIRAMATO',
        });
    });

    it('digitar por cima do catálogo escolhido limpa o princípio ativo (2ª revisão Codex)', async () => {
        const store = fakeStore(summary({ can_review: false }), vi);
        const w = mount(PatientMedicationsPanel, { props: { store, searchUrl: '/medicines/search' } });

        await w.find('[data-testid="medication-add"]').trigger('click');
        await w.find('[data-testid="medication-search"]').setValue('topam');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
        await w.find('[data-testid="medication-result"]').trigger('mousedown');

        const ingredient = w.find('[data-testid="medication-ingredient"]');
        expect(ingredient.element.value).toBe('TOPIRAMATO');

        // Médico digita por cima (override manual) — troca para outro
        // medicamento: o princípio ativo do item anterior (só-leitura, vem do
        // catálogo) não pode sobrar junto com o nome novo.
        await w.find('[data-testid="medication-search"]').setValue('Xarelto');
        expect(ingredient.element.value).toBe('');

        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
        await w.find('[data-testid="medication-save"]').trigger('click');
        await flushPromises();

        expect(store.addMedication.mock.calls[0][0]).toMatchObject({
            medicine_id: null,
            name: 'Xarelto',
            active_ingredient: '',
        });
    });

    it('nome livre quando não está no catálogo', async () => {
        const store = fakeStore(summary({ can_review: false }), vi);
        const w = mount(PatientMedicationsPanel, { props: { store, searchUrl: '/medicines/search' } });

        await w.find('[data-testid="medication-add"]').trigger('click');
        await w.find('[data-testid="medication-search"]').setValue('Fórmula manipulada');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
        await w.find('[data-testid="medication-use-typed"]').trigger('mousedown');
        await w.find('[data-testid="medication-save"]').trigger('click');
        await flushPromises();

        expect(store.addMedication.mock.calls[0][0]).toMatchObject({ medicine_id: null, name: 'Fórmula manipulada' });
    });

    it('recepção: sem confirmar/suspender; "informada, não confirmada" visível; médico: confirma e suspende', async () => {
        const reception = mount(PatientMedicationsPanel, {
            props: { store: fakeStore(summary({ can_review: false }), vi) },
        });
        expect(reception.find('[data-testid="medication-confirm"]').exists()).toBe(false);
        expect(reception.find('[data-testid="medication-suspend"]').exists()).toBe(false);
        expect(reception.text()).toContain('Não confirmada pelo médico');

        const store = fakeStore(summary(), vi);
        const doctor = mount(PatientMedicationsPanel, { props: { store } });
        await doctor.find('[data-testid="medication-confirm"]').trigger('click');
        expect(store.confirmMedication).toHaveBeenCalledWith('m-2');
        await doctor.findAll('[data-testid="medication-suspend"]')[0].trigger('click');
        expect(store.setMedicationStatus).toHaveBeenCalledWith('m-1', 'suspended');
    });
});
