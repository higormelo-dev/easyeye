import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PatientAllergiesPanel from '@/Components/Panel/ClinicalSafety/PatientAllergiesPanel.vue';
import { summary, fakeStore } from './fixtures.js';

/**
 * Bloco de alergias (Fase 2 / A1): recepção registra "informada"; só o
 * médico (can_review) confirma/descarta; paciente novo leva a lista junto.
 */
describe('PatientAllergiesPanel — recepção', () => {
    it('não oferece confirmar/descartar; registra sem escolher situação; mostra o aviso', async () => {
        const store = fakeStore(summary({ can_review: false }), vi);
        const w = mount(PatientAllergiesPanel, { props: { store } });

        expect(w.text()).toContain('Você registra como "informada pelo paciente"');
        expect(w.find('[data-testid="allergy-confirm"]').exists()).toBe(false);
        expect(w.find('[data-testid="allergy-discard"]').exists()).toBe(false);

        await w.find('[data-testid="allergy-add"]').trigger('click');
        expect(w.text()).not.toContain('Registrar como');
        await w.find('[data-testid="allergy-substance"]').setValue('Látex');
        await w.find('[data-testid="allergy-save"]').trigger('click');
        await flushPromises();

        expect(store.addAllergy).toHaveBeenCalledTimes(1);
        const payload = store.addAllergy.mock.calls[0][0];
        expect(payload.substance).toBe('Látex');
        expect(payload).not.toHaveProperty('status');
    });

    it('recepção corrige só a informada (a confirmada não tem "Corrigir")', () => {
        const w = mount(PatientAllergiesPanel, { props: { store: fakeStore(summary({ can_review: false }), vi) } });
        const items = w.findAll('[data-testid="allergy-list"] > li');

        expect(items[0].text()).toContain('Dipirona');
        expect(items[0].find('[aria-label^="Corrigir"]').exists()).toBe(false);
        expect(items[1].find('[aria-label^="Corrigir"]').exists()).toBe(true);
    });

    it('erro do servidor aparece no bloco', async () => {
        const store = fakeStore(summary({ can_review: false }), vi);
        store.addAllergy = vi.fn(() =>
            Promise.resolve({
                ok: false,
                errors: { substance: ['Esta alergia já está registrada'] },
                message: 'Esta alergia já está registrada',
            }),
        );
        const w = mount(PatientAllergiesPanel, { props: { store } });

        await w.find('[data-testid="allergy-add"]').trigger('click');
        await w.find('[data-testid="allergy-substance"]').setValue('Dipirona');
        await w.find('[data-testid="allergy-save"]').trigger('click');
        await flushPromises();

        expect(w.find('[role="alert"]').text()).toContain('já está registrada');
    });
});

describe('PatientAllergiesPanel — médico', () => {
    it('confirma a informada e descarta com motivo; histórico mostra a descartada', async () => {
        const store = fakeStore(summary(), vi);
        const w = mount(PatientAllergiesPanel, { props: { store } });

        await w.find('[data-testid="allergy-confirm"]').trigger('click');
        expect(store.confirmAllergy).toHaveBeenCalledWith('al-pen');

        await w.findAll('[data-testid="allergy-discard"]')[1].trigger('click');
        await w.find('input[maxlength="500"]').setValue('Teste de provocação negativo');
        await w.find('[data-testid="allergy-discard-confirm"]').trigger('click');
        expect(store.discardAllergy).toHaveBeenCalledWith('al-pen', 'Teste de provocação negativo');

        await w.find('button[aria-expanded]').trigger('click');
        expect(w.text()).toContain('Camarão');
        expect(w.text()).toContain('Teste negativo');
    });

    it('registra como confirmada por padrão (ou informada, se escolher)', async () => {
        const store = fakeStore(summary(), vi);
        const w = mount(PatientAllergiesPanel, { props: { store } });

        await w.find('[data-testid="allergy-add"]').trigger('click');
        await w.find('[data-testid="allergy-substance"]').setValue('Iodo');
        await w.find('[data-testid="allergy-save"]').trigger('click');
        await flushPromises();
        expect(store.addAllergy.mock.calls[0][0].status).toBe('confirmed');
    });

    it('"nega alergias" só sem alergia ativa', async () => {
        const store = fakeStore(summary({ allergy_state: 'unknown', allergies: [] }), vi);
        const w = mount(PatientAllergiesPanel, { props: { store } });

        const deny = w.find('[data-testid="allergy-deny"]');
        expect(deny.attributes('aria-pressed')).toBe('false');
        await deny.trigger('click');
        expect(store.setDenied).toHaveBeenCalledWith(true);

        expect(
            mount(PatientAllergiesPanel, { props: { store: fakeStore(summary(), vi) } })
                .find('[data-testid="allergy-deny"]')
                .exists(),
        ).toBe(false);
    });
});

describe('PatientAllergiesPanel — paciente novo (vai junto com o cadastro)', () => {
    it('adiciona na lista local e desfaz o "nega"', async () => {
        const w = mount(PatientAllergiesPanel, { props: { mode: 'pending', allergies: [], denied: true } });

        await w.find('[data-testid="allergy-add"]').trigger('click');
        await w.find('[data-testid="allergy-substance"]').setValue('Iodo');
        await w.find('[data-testid="allergy-save"]').trigger('click');

        const [list] = w.emitted('update:allergies').at(-1);
        expect(list).toHaveLength(1);
        expect(list[0]).toMatchObject({ substance: 'Iodo', category: 'medication' });
        expect(list[0]).not.toHaveProperty('status');
        expect(w.emitted('update:denied').at(-1)).toEqual([false]);
    });
});

describe('PatientAllergiesPanel — vínculo com o catálogo', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    it('trocar pra um remédio SEM princípio ativo no catálogo limpa o do remédio anterior (17ª revisão Codex, P2)', async () => {
        globalThis.fetch = vi
            .fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () =>
                    Promise.resolve([
                        { id: 'med-amx', name: 'AMOXIL', active_ingredient: 'AMOXICILINA TRI-HIDRATADA' },
                    ]),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () =>
                    Promise.resolve([{ id: 'med-x', name: 'REMEDIO SEM INGREDIENTE', active_ingredient: null }]),
            });
        const store = fakeStore(summary({ can_review: false }), vi);
        const w = mount(PatientAllergiesPanel, { props: { store, searchUrl: '/medicines/search' } });

        await w.find('[data-testid="allergy-add"]').trigger('click');
        await w.find('[data-testid="allergy-catalog-search"]').setValue('amox');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
        await w.find('[data-testid="allergy-catalog-result"]').trigger('mousedown');

        expect(w.find('[data-testid="allergy-ingredient"]').element.value).toBe('AMOXICILINA TRI-HIDRATADA');

        // Corrigiu: tirou o vínculo e escolheu outro remédio, sem princípio ativo próprio.
        await w.find('[data-testid="allergy-catalog-unlink"]').trigger('click');
        await w.find('[data-testid="allergy-catalog-search"]').setValue('rem');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
        await w.find('[data-testid="allergy-catalog-result"]').trigger('mousedown');

        const ingredient = w.find('[data-testid="allergy-ingredient"]');
        expect(ingredient.element.value).toBe('');
        expect(ingredient.attributes('readonly')).toBeDefined();

        await w.find('[data-testid="allergy-save"]').trigger('click');
        await flushPromises();
        expect(store.addAllergy.mock.calls[0][0]).toMatchObject({ medicine_id: 'med-x', active_ingredient: '' });
    });
});
