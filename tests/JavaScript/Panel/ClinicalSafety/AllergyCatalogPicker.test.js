import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { summary, fakeStore } from './fixtures.js';

/**
 * Fase 2 / G1 — alergia com item do catálogo (CMED) OPCIONAL, além da
 * substância digitada: combobox acessível (setas/Enter/Esc,
 * aria-activedescendant), debounce + AbortController, princípio ativo do
 * catálogo só leitura, medicine_id no envio (ao vivo e no paciente novo).
 */
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

const PatientAllergiesPanel = (await import('@/Components/Panel/ClinicalSafety/PatientAllergiesPanel.vue')).default;
const ClinicalSafetyModal = (await import('@/Components/Panel/ClinicalSafety/ClinicalSafetyModal.vue')).default;
const PatientFormSections = (await import('@/Pages/Panel/Patients/PatientFormSections.vue')).default;

const NOVALGINA = {
    id: 'med-nov',
    name: 'NOVALGINA',
    concentration: '1 G',
    active_ingredient: 'DIPIRONA MONOIDRATADA',
    laboratory: 'SANOFI',
};
const ANADOR = { id: 'med-ana', name: 'ANADOR', concentration: '500 MG', active_ingredient: 'DIPIRONA MONOIDRATADA' };

function catalogFetch(items = [NOVALGINA, ANADOR]) {
    return vi.fn((url, options) =>
        Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(items), url, options }),
    );
}

async function typeSearch(w, value) {
    await w.find('[data-testid="allergy-catalog-search"]').setValue(value);
    await vi.advanceTimersByTimeAsync(300);
    await flushPromises();
}

describe('PatientAllergiesPanel — item do catálogo (opcional)', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        globalThis.fetch = catalogFetch();
    });
    afterEach(() => vi.useRealTimers());

    it('sem URL de busca (perfil sem a rota) não mostra o seletor — só a substância livre', async () => {
        const w = mount(PatientAllergiesPanel, { props: { store: fakeStore(summary({ can_review: false }), vi) } });
        await w.find('[data-testid="allergy-add"]').trigger('click');

        expect(w.find('[data-testid="allergy-substance"]').exists()).toBe(true);
        expect(w.find('[data-testid="allergy-catalog-search"]').exists()).toBe(false);
    });

    it('busca com debounce, teclado escolhe o item; princípio ativo do catálogo (só leitura) e medicine_id no envio', async () => {
        const store = fakeStore(summary({ can_review: false }), vi);
        const w = mount(PatientAllergiesPanel, {
            props: { store, searchUrl: '/medicines/search' },
            attachTo: document.body,
        });
        await w.find('[data-testid="allergy-add"]').trigger('click');

        const input = w.find('[data-testid="allergy-catalog-search"]');
        expect(input.attributes('role')).toBe('combobox');
        expect(input.attributes('aria-expanded')).toBe('false');

        await input.setValue('nov');
        expect(globalThis.fetch).not.toHaveBeenCalled(); // debounce
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/medicines/search?q=nov');
        expect(input.attributes('aria-expanded')).toBe('true');
        const listbox = w.find('[role="listbox"]');
        expect(input.attributes('aria-controls')).toBe(listbox.attributes('id'));
        expect(w.findAll('[data-testid="allergy-catalog-result"]')).toHaveLength(2);

        await input.trigger('keydown', { key: 'ArrowDown' });
        const first = w.findAll('[data-testid="allergy-catalog-result"]')[0];
        expect(input.attributes('aria-activedescendant')).toBe(first.attributes('id'));
        expect(first.attributes('aria-selected')).toBe('true');

        await input.trigger('keydown', { key: 'Enter' });
        expect(store.addAllergy).not.toHaveBeenCalled(); // Enter na busca não envia

        const linked = w.find('[data-testid="allergy-catalog-linked"]');
        expect(linked.text()).toContain('NOVALGINA 1 G');
        const ingredient = w.find('[data-testid="allergy-ingredient"]');
        expect(ingredient.element.value).toBe('DIPIRONA MONOIDRATADA');
        expect(ingredient.attributes('readonly')).toBeDefined();
        // Substância vazia é preenchida com o nome — continua editável.
        expect(w.find('[data-testid="allergy-substance"]').element.value).toBe('NOVALGINA');

        await w.find('[data-testid="allergy-save"]').trigger('click');
        await flushPromises();
        const payload = store.addAllergy.mock.calls[0][0];
        expect(payload).toMatchObject({
            substance: 'NOVALGINA',
            medicine_id: 'med-nov',
            active_ingredient: 'DIPIRONA MONOIDRATADA',
        });
        expect(payload).not.toHaveProperty('medicine_label');
        w.unmount();
    });

    it('a substância digitada não é trocada pelo item; desvincular libera o princípio ativo e manda medicine_id nulo', async () => {
        const store = fakeStore(summary({ can_review: false }), vi);
        const w = mount(PatientAllergiesPanel, { props: { store, searchUrl: '/medicines/search' } });
        await w.find('[data-testid="allergy-add"]').trigger('click');
        await w.find('[data-testid="allergy-substance"]').setValue('Dipirona (Novalgina)');

        await typeSearch(w, 'novalg');
        await w.findAll('[data-testid="allergy-catalog-result"]')[0].trigger('mousedown');
        expect(w.find('[data-testid="allergy-substance"]').element.value).toBe('Dipirona (Novalgina)');

        await w.find('[data-testid="allergy-catalog-unlink"]').trigger('click');
        expect(w.find('[data-testid="allergy-catalog-linked"]').exists()).toBe(false);
        expect(w.find('[data-testid="allergy-ingredient"]').attributes('readonly')).toBeUndefined();

        await w.find('[data-testid="allergy-save"]').trigger('click');
        await flushPromises();
        expect(store.addAllergy.mock.calls[0][0]).toMatchObject({
            substance: 'Dipirona (Novalgina)',
            medicine_id: null,
        });
    });

    it('nova busca aborta a anterior (AbortController); Esc fecha a lista', async () => {
        const w = mount(PatientAllergiesPanel, {
            props: { store: fakeStore(summary({ can_review: false }), vi), searchUrl: '/medicines/search' },
        });
        await w.find('[data-testid="allergy-add"]').trigger('click');

        let firstSignal = null;
        globalThis.fetch = vi.fn((url, options) => {
            firstSignal ??= options.signal;
            return new Promise(() => {}); // fica pendente
        });
        await typeSearch(w, 'dip');
        globalThis.fetch = catalogFetch();
        await typeSearch(w, 'dipirona');

        expect(firstSignal.aborted).toBe(true);
        const input = w.find('[data-testid="allergy-catalog-search"]');
        expect(input.attributes('aria-expanded')).toBe('true');
        await input.trigger('keydown', { key: 'Escape' });
        expect(input.attributes('aria-expanded')).toBe('false');
    });

    it('nada no catálogo: avisa que a substância digitada continua valendo', async () => {
        globalThis.fetch = catalogFetch([]);
        const w = mount(PatientAllergiesPanel, {
            props: { store: fakeStore(summary({ can_review: false }), vi), searchUrl: '/medicines/search' },
        });
        await w.find('[data-testid="allergy-add"]').trigger('click');
        await typeSearch(w, 'xyz');

        expect(w.find('[role="listbox"]').text()).toContain('a substância digitada continua valendo');
    });

    it('paciente novo (pendente): o item do catálogo vai na lista do cadastro com medicine_id', async () => {
        const w = mount(PatientAllergiesPanel, {
            props: { mode: 'pending', allergies: [], denied: false, searchUrl: '/medicines/search' },
        });
        await w.find('[data-testid="allergy-add"]').trigger('click');
        await typeSearch(w, 'nov');
        await w.findAll('[data-testid="allergy-catalog-result"]')[0].trigger('mousedown');
        await w.find('[data-testid="allergy-save"]').trigger('click');

        const [list] = w.emitted('update:allergies').at(-1);
        expect(list[0]).toMatchObject({
            substance: 'NOVALGINA',
            medicine_id: 'med-nov',
            active_ingredient: 'DIPIRONA MONOIDRATADA',
        });
    });

    it('corrigir alergia vinculada mantém o vínculo (rótulo do servidor) e o princípio ativo na lista', async () => {
        const store = fakeStore(
            summary({
                can_review: true,
                allergies: [
                    {
                        id: 'al-nov',
                        category: 'medication',
                        substance: 'Novalgina',
                        medicine_id: 'med-nov',
                        medicine_label: 'NOVALGINA 1 G',
                        active_ingredient: 'DIPIRONA MONOIDRATADA',
                        status: 'reported',
                        active: true,
                    },
                ],
            }),
            vi,
        );
        const w = mount(PatientAllergiesPanel, { props: { store, searchUrl: '/medicines/search' } });

        expect(w.find('[data-testid="allergy-list"]').text()).toContain('Princípio ativo: DIPIRONA MONOIDRATADA');
        expect(w.find('[data-testid="allergy-list"]').text()).toContain('do catálogo');

        await w.find('[aria-label^="Corrigir"]').trigger('click');
        expect(w.find('[data-testid="allergy-catalog-linked"]').text()).toContain('NOVALGINA 1 G');
        await w.find('[data-testid="allergy-save"]').trigger('click');
        await flushPromises();
        expect(store.updateAllergy).toHaveBeenCalledWith('al-nov', expect.objectContaining({ medicine_id: 'med-nov' }));
    });
});

describe('o seletor chega às telas', () => {
    it('prontuário: "Revisar alergias e medicações" passa a busca do catálogo para o bloco de alergias', () => {
        const w = mount(ClinicalSafetyModal, {
            props: { open: true, store: fakeStore(summary(), vi), searchUrl: '/medicines/search' },
            global: {
                stubs: {
                    CenteredModal: { template: '<div><slot name="header" /><slot /><slot name="footer" /></div>' },
                },
            },
        });

        expect(w.findComponent(PatientAllergiesPanel).props('searchUrl')).toBe('/medicines/search');
    });

    it('cadastro do paciente (aba Clínico, paciente novo): bloco de alergias recebe a rota de busca', () => {
        const form = reactive({ covenant_id: '', allergies: [], allergies_denied: false, medications: [], errors: {} });
        const w = mount(PatientFormSections, {
            props: { form, section: 'clinical' },
            global: { stubs: { SearchSelect: true }, directives: { mask: {} } },
        });

        expect(w.findComponent(PatientAllergiesPanel).props('searchUrl')).toBe('/_routes/panel.medicines.search');
    });
});
