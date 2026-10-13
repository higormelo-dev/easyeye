import { describe, it, expect, vi, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import Index from '@/Pages/Panel/EyeImages/Index.vue';

/**
 * Modal de impressão (Fase 5 / 5a): reordenar manual (setas), agrupar
 * OD/OE (mesma fonte da verdade examGroupKey() da galeria) e legenda ad hoc
 * (nunca persistida — reseta a cada abertura). Monta a página inteira via
 * shallowMount (mesmo padrão de ReceiptReviewDeepLink.test.js): o modal de
 * impressão é template inline do próprio Index.vue, não um componente filho.
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /><slot name="top-actions" /></div>' } }));

const baseProps = {
    entity: { id: 'entity-1' },
    patients: [],
    urls: { patient_urls: '/patient/__ID__/urls', image_url: '/exam/__ID__/url' },
};
const wrappers = [];

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

async function render(propsOverride = {}) {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({ json: async () => ({ urls: {}, url: '' }) })),
    );
    const wrapper = shallowMount(Index, {
        props: { ...baseProps, ...propsOverride },
        global: { stubs: { Teleport: true }, renderStubDefaultSlot: true },
    });
    wrappers.push(wrapper);
    await flushPromises();
    return wrapper;
}

// B vem antes de A em printExams (ordem de inserção) — exercita que o
// agrupamento respeita "1º grupo visto", só reordenando OD/OE DENTRO do grupo.
const examBOe = {
    id: 'b-oe',
    laterality: 2,
    exam_id: 'type-1',
    entity_integrator_equipment_id: 'eq-1',
    created_at: '2026-02-12T12:00:00Z',
};
const examAOe = { id: 'a-oe', laterality: 2, exam_session_id: 'sess-1', created_at: '2026-02-12T12:00:00Z' };
const examBOd = {
    id: 'b-od',
    laterality: 1,
    exam_id: 'type-1',
    entity_integrator_equipment_id: 'eq-1',
    created_at: '2026-02-12T12:00:00Z',
};
const examAOd = { id: 'a-od', laterality: 1, exam_session_id: 'sess-1', created_at: '2026-02-12T12:00:00Z' };

describe('EyeImages — modal de impressão: reordenar / agrupar OD-OE / legenda (Fase 5)', () => {
    it('abrir o modal copia os exames (não reordena a lista original do paciente)', async () => {
        const wrapper = await render();
        const original = [examBOe, examAOe];

        wrapper.vm.openPrintModal(original, false);
        wrapper.vm.movePrintExam(wrapper.vm.printExams[0], 1);

        expect(wrapper.vm.printExams.map((e) => e.id)).toEqual(['a-oe', 'b-oe']);
        expect(original.map((e) => e.id)).toEqual(['b-oe', 'a-oe']);
    });

    it('movePrintExam troca a posição com o vizinho e respeita os limites do array', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([examBOe, examAOe, examBOd], false);

        wrapper.vm.movePrintExam(wrapper.vm.printExams[0], -1); // já é o 1º: no-op
        expect(wrapper.vm.printExams.map((e) => e.id)).toEqual(['b-oe', 'a-oe', 'b-od']);

        wrapper.vm.movePrintExam(wrapper.vm.printExams[2], 1); // já é o último: no-op
        expect(wrapper.vm.printExams.map((e) => e.id)).toEqual(['b-oe', 'a-oe', 'b-od']);

        wrapper.vm.movePrintExam(wrapper.vm.printExams[0], 1);
        expect(wrapper.vm.printExams.map((e) => e.id)).toEqual(['a-oe', 'b-oe', 'b-od']);
    });

    it('"Agrupar OD/OE" reordena por grupo (1º grupo visto primeiro) com OD antes de OE dentro do grupo', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([examBOe, examAOe, examBOd, examAOd], false);

        expect(wrapper.vm.printGroupOdOe).toBe(false);
        expect(wrapper.vm.printExamsDisplay.map((e) => e.id)).toEqual(['b-oe', 'a-oe', 'b-od', 'a-od']);

        wrapper.vm.printGroupOdOe = true;
        await flushPromises();

        expect(wrapper.vm.printExamsDisplay.map((e) => e.id)).toEqual(['b-od', 'b-oe', 'a-od', 'a-oe']);
        // Ordem CRUA (manual) fica intocada — só a exibição/impressão reagrupa.
        expect(wrapper.vm.printExams.map((e) => e.id)).toEqual(['b-oe', 'a-oe', 'b-od', 'a-od']);
    });

    it('legenda ad hoc: digitar não persiste nada e reseta ao reabrir o modal', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([examBOe], false);

        wrapper.vm.printCaptions[examBOe.id] = 'Achado incidental';
        expect(wrapper.vm.printCaptions[examBOe.id]).toBe('Achado incidental');

        wrapper.vm.openPrintModal([examBOe], false);
        expect(wrapper.vm.printCaptions[examBOe.id]).toBeUndefined();
    });

    it('[GAP] legenda longa/multilinha: textarea (rows=1, sem resize) nunca imprime — versão só-impressão (pre-wrap) mostra o texto inteiro (achado via revisão Codex)', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([examBOe], false);
        await flushPromises();

        const longCaption = 'Achado incidental relevante\nsegunda linha que não cabe numa textarea de 1 linha só';
        wrapper.vm.printCaptions[examBOe.id] = longCaption;
        await flushPromises();

        // A caixa editável nunca pode ir pro papel — rows="1"+resize:none
        // corta tudo que não seja a 1ª linha na impressão (achado via
        // revisão Codex); d-print-none garante isso.
        const textarea = wrapper.find('textarea');
        expect(textarea.exists()).toBe(true);
        expect(textarea.classes()).toContain('d-print-none');

        // Versão só-impressão (invisível na tela, d-none) mostra o texto
        // INTEIRO — a quebra de linha vem do CSS (pre-wrap), não precisa
        // estar intocada no texto extraído do DOM.
        const printOnly = wrapper.find('.d-print-block');
        expect(printOnly.exists()).toBe(true);
        expect(printOnly.text()).toContain('Achado incidental relevante');
        expect(printOnly.text()).toContain('segunda linha que não cabe numa textarea de 1 linha só');
    });

    it('[GAP] legenda vazia: versão só-impressão não renderiza nada (sem caixa vazia ocupando espaço no papel)', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([examBOe], false);
        await flushPromises();

        expect(wrapper.find('.d-print-block').exists()).toBe(false);
    });

    it('reabrir o modal desliga "Agrupar OD/OE" (volta pro modo manual)', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([examBOe, examAOe], false);
        wrapper.vm.printGroupOdOe = true;

        wrapper.vm.openPrintModal([examBOe, examAOe], false);

        expect(wrapper.vm.printGroupOdOe).toBe(false);
    });
});
