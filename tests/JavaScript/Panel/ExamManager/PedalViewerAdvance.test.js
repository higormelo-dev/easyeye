import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import Index from '@/Pages/Panel/ExamManager/Index.vue';

/**
 * Pedal USB (Fase 5 / 7b) no visualizador: a MESMA tecla "avançar"
 * configurada na captura (ImportExternalExamModal) troca pra próxima
 * imagem aqui, mesmo efeito do ArrowRight já existente. Mesmo padrão de
 * shallowMount de PrintModalReorder.test.js/VideoExamExclusion.test.js.
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /><slot name="top-actions" /></div>' } }));

// usePage() (mock global de tests/JavaScript/setup.js) devolve um objeto NOVO
// a cada chamada — não dá pra mutar `preferences` nele entre o setup do
// teste e o que o componente lê. Mesmo padrão de mock direto do composable
// já usado em ImportExternalExamModal.test.js.
const pedalPrefs = vi.hoisted(() => ({ values: {} }));
vi.mock('@/composables/useUserPreferences.js', () => ({
    useUserPreferences: () => ({
        getPreference: (key, fallback) => pedalPrefs.values[key] ?? fallback,
        savePreference: vi.fn(),
    }),
}));

const baseProps = {
    entity: { id: 'entity-1' },
    patients: [],
    urls: { patient_urls: '/patient/__ID__/urls', image_url: '/exam/__ID__/url' },
    // Tecla "avançar" fixa em PageDown nos testes — evita qualquer ambiguidade
    // com o Espaço (poderia coincidir com um scroll/clique nativo do jsdom).
    pedal_shortcuts_config: { allowed_keys: ['Enter', ' ', 'PageDown', 'PageUp'], default_advance_key: 'PageDown' },
};
const wrappers = [];

beforeEach(() => {
    pedalPrefs.values = {};
});

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
        attachTo: document.body, // keydown precisa bubble real até window (teste de foco em input)
        props: { ...baseProps, ...propsOverride },
        global: { stubs: { Teleport: true }, renderStubDefaultSlot: true },
    });
    wrappers.push(wrapper);
    await flushPromises();
    return wrapper;
}

const examA = { id: 'exam-a', laterality: 1, exam_id: 'type-1', created_at: '2026-02-12T12:00:00Z' };
const examB = { id: 'exam-b', laterality: 2, exam_id: 'type-1', created_at: '2026-02-12T12:00:00Z' };

describe('ExamManager — pedal USB: tecla "avançar" no visualizador (Fase 5 / 7b)', () => {
    it('troca pra próxima imagem, mesmo efeito do ArrowRight', async () => {
        const wrapper = await render();
        wrapper.vm.openViewerModal([examA, examB], 0, 1);
        await flushPromises();
        expect(wrapper.vm.viewerPanelExams[0]?.id).toBe('exam-a');

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'PageDown', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.viewerPanelExams[0]?.id).toBe('exam-b');
    });

    it('[GAP] ignora quando o visualizador está fechado', async () => {
        const wrapper = await render();
        expect(wrapper.vm.showViewerModal).toBe(false);

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'PageDown', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.viewerPanelExams[0]).toBeNull();
    });

    it('[GAP] ignora com foco num campo de texto (ex.: busca de paciente)', async () => {
        const wrapper = await render();
        wrapper.vm.openViewerModal([examA, examB], 0, 1);
        await flushPromises();

        const input = wrapper.find('input[type="text"]');
        expect(input.exists()).toBe(true);
        input.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'PageDown', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.viewerPanelExams[0]?.id).toBe('exam-a');
    });

    it('[GAP] ignora quando o laudo encaixado está aberto (reportModalOpen)', async () => {
        const wrapper = await render();
        wrapper.vm.openViewerModal([examA, examB], 0, 1);
        await flushPromises();
        wrapper.vm.reportModalOpen = true;

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'PageDown', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.viewerPanelExams[0]?.id).toBe('exam-a');
    });

    it('[GAP] ignora com foco no <video controls> do exame em exibição (dar play/pause não pode avançar o exame)', async () => {
        const examVideo = {
            id: 'exam-v',
            laterality: 1,
            exam_id: 'type-1',
            archive: 'captura.webm',
            created_at: '2026-02-12T12:00:00Z',
        };
        const wrapper = await render();
        wrapper.vm.openViewerModal([examVideo, examB], 0, 1);
        await flushPromises();
        expect(wrapper.vm.viewerPanelExams[0]?.id).toBe('exam-v');

        const video = wrapper.find('video');
        expect(video.exists()).toBe(true); // isVideoExam() reconheceu o .webm

        // Mesma tecla configurada acima (PageDown nos testes; na prática o
        // default é Espaço, a mesma que o <video controls> nativo usa pra
        // alternar play/pause quando focado). Sem o guard, dar play
        // avançava pro próximo exame em vez de tocar o vídeo (achado via
        // revisão Codex).
        video.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'PageDown', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.viewerPanelExams[0]?.id).toBe('exam-v');
    });

    it('[GAP] advance_key salvo como null (desligado de propósito) nunca cai no default — PageDown não avança mais (achado via revisão Codex)', async () => {
        pedalPrefs.values['eye_images_pedal_shortcuts'] = { capture_key: 'Enter', advance_key: null };
        const wrapper = await render();
        wrapper.vm.openViewerModal([examA, examB], 0, 1);
        await flushPromises();
        expect(wrapper.vm.viewerPanelExams[0]?.id).toBe('exam-a');
        expect(wrapper.vm.pedalAdvanceKey).toBeNull();

        // Sem a correção, ?? caía no default_advance_key (PageDown nos
        // testes) mesmo com advance_key salvo como null — a tecla
        // continuava avançando apesar do usuário ter desligado
        // explicitamente (achado via revisão Codex).
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'PageDown', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.viewerPanelExams[0]?.id).toBe('exam-a');
    });

    it('[GAP] tecla Espaço (default) com foco num <button> do visualizador (ex.: "Fechar") nunca é interceptada — deixa o click nativo do botão acontecer (achado via revisão Codex)', async () => {
        // Espaço de propósito aqui (não PageDown): é a tecla DEFAULT de
        // verdade (default_advance_key) e a mesma que o navegador usa pra
        // ativar um <button> focado — exatamente o cenário real do achado.
        const wrapper = await render({
            pedal_shortcuts_config: { allowed_keys: ['Enter', ' ', 'PageDown', 'PageUp'], default_advance_key: ' ' },
        });
        wrapper.vm.openViewerModal([examA, examB], 0, 1);
        await flushPromises();
        expect(wrapper.vm.viewerPanelExams[0]?.id).toBe('exam-a');

        const closeBtn = Array.from(document.body.querySelectorAll('button')).find(
            (b) => b.getAttribute('aria-label') === 'Fechar',
        );
        expect(closeBtn).toBeTruthy();
        closeBtn.focus();

        // event.defaultPrevented é a causa raiz de verdade: sem a correção,
        // onKeyDown() chamava preventDefault() aqui, cancelando o click
        // NATIVO que o navegador dispara pra um <button> focado em Espaço
        // (ativação por teclado, acessibilidade) — "Fechar"/"Ajustar"/
        // "OD|OE" ficavam mortos pro teclado, e viewerNext() avançava de
        // exame no lugar (achado via revisão Codex).
        const event = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true });
        closeBtn.dispatchEvent(event);
        await flushPromises();

        expect(event.defaultPrevented).toBe(false);
        expect(wrapper.vm.viewerPanelExams[0]?.id).toBe('exam-a'); // nunca avançou de exame no lugar
    });
});
