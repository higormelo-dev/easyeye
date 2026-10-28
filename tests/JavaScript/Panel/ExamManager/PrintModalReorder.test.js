import { describe, it, expect, vi, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import Index from '@/Pages/Panel/ExamManager/Index.vue';

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

describe('ExamManager — modal de impressão: reordenar / agrupar OD-OE / legenda (Fase 5)', () => {
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

describe('ExamManager — modal de impressão: N imagens por folha (Fase 5)', () => {
    const odA = { id: 'a-od', laterality: 1, exam_session_id: 'sess-a', created_at: '2026-02-12T12:00:00Z' };
    const oeA = { id: 'a-oe', laterality: 2, exam_session_id: 'sess-a', created_at: '2026-02-12T12:00:00Z' };
    const odB = { id: 'b-od', laterality: 1, exam_session_id: 'sess-b', created_at: '2026-02-12T12:00:00Z' };
    const oeB = { id: 'b-oe', laterality: 2, exam_session_id: 'sess-b', created_at: '2026-02-12T12:00:00Z' };
    const sheetIds = (wrapper) => wrapper.vm.printPages.map((page) => page.cells.map((cell) => cell?.id ?? null));

    it('padrão: modo contínuo, uma página só, colunas = printCols', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([odA, oeA, odB], false);

        expect(wrapper.vm.printPerSheet).toBeNull();
        expect(wrapper.vm.printPages).toHaveLength(1);
        expect(wrapper.vm.printPages[0]).toMatchObject({ cols: 2, rows: null });
        expect(sheetIds(wrapper)).toEqual([['a-od', 'a-oe', 'b-od']]);
        expect(wrapper.find('.ei-sheet').exists()).toBe(false);
    });

    it('2 por folha parte em folhas de 2 com a última completada por célula vazia', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([odA, oeA, odB], false);
        wrapper.vm.printPerSheet = 2;
        await flushPromises();

        expect(sheetIds(wrapper)).toEqual([
            ['a-od', 'a-oe'],
            ['b-od', null],
        ]);
        expect(wrapper.findAll('.ei-sheet')).toHaveLength(2);
        expect(wrapper.vm.printPages.map((p) => p.total)).toEqual([2, 2]);
    });

    it('"Agrupar OD/OE" no modo por folha põe OD | OE lado a lado, par por linha', async () => {
        const wrapper = await render();
        // Ordem crua embaralhada de propósito: OE antes de OD, grupos intercalados.
        wrapper.vm.openPrintModal([oeA, oeB, odA, odB], false);
        wrapper.vm.printPerSheet = 4;
        wrapper.vm.printGroupOdOe = true;
        await flushPromises();

        expect(wrapper.vm.printPages[0]).toMatchObject({ cols: 2, rows: 2 });
        expect(sheetIds(wrapper)).toEqual([['a-od', 'a-oe', 'b-od', 'b-oe']]);
    });

    it('vídeo nunca ocupa posição na folha', async () => {
        const wrapper = await render();
        const video = { id: 'v', laterality: 1, archive: 'exames/clip.mp4', exam_session_id: 'sess-a' };
        wrapper.vm.openPrintModal([odA, video, oeA], false);
        wrapper.vm.printPerSheet = 2;
        await flushPromises();

        expect(sheetIds(wrapper)).toEqual([['a-od', 'a-oe']]);
    });

    it('reabrir o modal volta ao modo contínuo', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([odA], false);
        wrapper.vm.printPerSheet = 4;

        wrapper.vm.openPrintModal([odA], false);

        expect(wrapper.vm.printPerSheet).toBeNull();
    });

    it('clicar de novo no valor ativo desliga o modo por folha', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([odA, oeA], false);
        await flushPromises();

        const buttons = wrapper.findAll('[data-testid="print-per-sheet"] button');
        expect(buttons.map((b) => b.text())).toEqual(['1', '2', '4', '6', '8', '9', '12', '15', '16']);

        await buttons[1].trigger('click');
        expect(wrapper.vm.printPerSheet).toBe(2);
        await buttons[1].trigger('click');
        expect(wrapper.vm.printPerSheet).toBeNull();
    });

    it('folha ímpar com agrupamento OD/OE mostra o aviso e preserva a ordem', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([oeA, odA, oeB], false);
        wrapper.vm.printPerSheet = 9;
        wrapper.vm.printGroupOdOe = true;
        await flushPromises();

        expect(wrapper.find('[data-testid="print-pair-odd-hint"]').exists()).toBe(true);
        expect(sheetIds(wrapper)[0].slice(0, 3)).toEqual(['a-oe', 'a-od', 'b-oe']);
    });

    it('printReport injeta o @page do papel escolhido e remove depois da impressão', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([odA], false);
        wrapper.vm.printOrientation = 'landscape';
        let during = '';
        vi.stubGlobal('print', () => {
            during = document.head.querySelector('style[data-ei-print-page]')?.textContent ?? '';
        });

        wrapper.vm.printReport();
        window.dispatchEvent(new Event('afterprint'));

        expect(during).toContain('size: A4 landscape');
        expect(document.head.querySelector('style[data-ei-print-page]')).toBeNull();
    });

    it('[F1] fechar desmonta a impressão paginada; ao abrir para outro paciente não sobra folha anterior', async () => {
        const wrapper = await render();
        const patientA = { code: 'A-001', person: { full_name: 'Paciente anterior' }, exams: [] };
        const patientB = { code: 'B-002', person: { full_name: 'Paciente atual' }, exams: [] };

        wrapper.vm.selectedPatient = patientA;
        wrapper.vm.openPrintModal([odA], false);
        wrapper.vm.printPerSheet = 1;
        await flushPromises();
        expect(wrapper.find('.ei-print-paged').exists()).toBe(true);
        expect(wrapper.text()).toContain('Paciente anterior');

        wrapper.vm.showPrintModal = false;
        await flushPromises();
        expect(wrapper.find('.ei-print-root').exists()).toBe(false);

        wrapper.vm.selectedPatient = patientB;
        wrapper.vm.openPrintModal([oeB], false);
        wrapper.vm.printPerSheet = 1;
        await flushPromises();

        expect(wrapper.find('.ei-print-paged').exists()).toBe(true);
        expect(wrapper.text()).toContain('Paciente atual');
        expect(wrapper.text()).not.toContain('Paciente anterior');
    });

    it.each([320, 375])('[F2] em %ipx a barra quebra linhas e mantém Fechar acessível', async (viewport) => {
        Object.defineProperty(window, 'innerWidth', { configurable: true, value: viewport });
        const wrapper = await render();
        wrapper.vm.openPrintModal([odA], false);
        await flushPromises();

        const toolbar = wrapper.find('[data-testid="print-toolbar"]');
        expect(toolbar.classes()).toContain('flex-wrap');
        expect(toolbar.find('[data-testid="print-close"]').exists()).toBe(true);
    });
});
