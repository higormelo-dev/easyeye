import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import Index from '@/Pages/Panel/ExamManager/Index.vue';

/**
 * Vídeo capturado pela câmera do navegador (Fase 5 / 6c): nunca entra na
 * grade de impressão nem na colagem (window.print() e Imagick não lidam com
 * vídeo) — exclusão sempre avisada (nota/toast), nunca silenciosa. Mesmo
 * padrão de shallowMount de PrintModalReorder.test.js: o modal de impressão
 * é template inline do próprio Index.vue, não componente filho.
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /><slot name="top-actions" /></div>' } }));

const baseProps = {
    entity: { id: 'entity-1' },
    patients: [],
    urls: { patient_urls: '/patient/__ID__/urls', image_url: '/exam/__ID__/url', montage: '/montage' },
};
const wrappers = [];

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
    delete URL.createObjectURL;
    delete URL.revokeObjectURL;
    delete window.showErrorToast;
    delete window.showSuccessToast;
    delete window.axios;
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

const examPhoto = {
    id: 'photo-1',
    laterality: 1,
    exam_id: 'type-1',
    archive: 'entities/1/exams/a.jpg',
    created_at: '2026-02-12T12:00:00Z',
};
const examPhoto2 = { ...examPhoto, id: 'photo-2' };
const examVideoWebm = {
    id: 'video-1',
    laterality: 2,
    exam_id: 'type-1',
    archive: 'entities/1/exams/b.webm',
    created_at: '2026-02-12T12:00:00Z',
};
const examVideoMp4 = { ...examVideoWebm, id: 'video-2', archive: 'entities/1/exams/c.mp4' };
const examVideoUpperExt = { ...examVideoWebm, id: 'video-3', archive: 'entities/1/exams/d.WEBM' };

describe('ExamManager — isVideoExam (Fase 5 / 6c)', () => {
    it('detecta .webm e .mp4, inclusive extensão em caixa alta', async () => {
        const wrapper = await render();
        expect(wrapper.vm.isVideoExam(examVideoWebm)).toBe(true);
        expect(wrapper.vm.isVideoExam(examVideoMp4)).toBe(true);
        expect(wrapper.vm.isVideoExam(examVideoUpperExt)).toBe(true);
    });

    it('não marca imagem/PDF como vídeo', async () => {
        const wrapper = await render();
        expect(wrapper.vm.isVideoExam(examPhoto)).toBe(false);
        expect(wrapper.vm.isVideoExam({ id: 'pdf-1', archive: 'x/doc.pdf' })).toBe(false);
    });

    it('sem archive (null/undefined) não quebra e retorna false', async () => {
        const wrapper = await render();
        expect(wrapper.vm.isVideoExam({ id: 'no-archive' })).toBe(false);
        expect(wrapper.vm.isVideoExam(null)).toBe(false);
        expect(wrapper.vm.isVideoExam(undefined)).toBe(false);
    });
});

describe('ExamManager — modal de impressão exclui vídeo (Fase 5 / 6c)', () => {
    it('printPrintableExams/printExcludedVideoCount filtram vídeo da lista crua', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([examPhoto, examVideoWebm, examVideoMp4], false);

        expect(wrapper.vm.printPrintableExams.map((e) => e.id)).toEqual(['photo-1']);
        expect(wrapper.vm.printExcludedVideoCount).toBe(2);
        expect(wrapper.vm.printExamsDisplay.map((e) => e.id)).toEqual(['photo-1']);
    });

    it('sem vídeo selecionado: contagem fica zero, nada filtrado', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([examPhoto, examPhoto2], false);

        expect(wrapper.vm.printExcludedVideoCount).toBe(0);
        expect(wrapper.vm.printExamsDisplay.map((e) => e.id)).toEqual(['photo-1', 'photo-2']);
    });

    it('"Agrupar OD/OE" também exclui vídeo (filtro roda antes do agrupamento)', async () => {
        const wrapper = await render();
        wrapper.vm.openPrintModal([examVideoWebm, examPhoto], false);

        wrapper.vm.printGroupOdOe = true;
        await flushPromises();

        expect(wrapper.vm.printExamsDisplay.map((e) => e.id)).toEqual(['photo-1']);
    });
});

describe('ExamManager — colagem exclui vídeo (Fase 5 / 6c)', () => {
    beforeEach(() => {
        URL.createObjectURL = vi.fn(() => 'blob:fake-url');
        URL.revokeObjectURL = vi.fn();
    });

    it('filtra vídeo antes de mandar pro backend e avisa quantidade excluída por toast', async () => {
        window.axios = { post: vi.fn(() => Promise.resolve({ data: new Blob(['x']) })) };
        window.showSuccessToast = vi.fn();
        window.showErrorToast = vi.fn();

        const wrapper = await render();
        wrapper.vm.selectedPatient = { id: 'pat-1', exams: [examPhoto, examPhoto2, examVideoWebm] };
        wrapper.vm.selectedExamIds = ['photo-1', 'photo-2', 'video-1'];

        await wrapper.vm.downloadMontage();
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            '/montage',
            { exam_ids: ['photo-1', 'photo-2'], columns: 2 },
            { responseType: 'blob' },
        );
        expect(window.showErrorToast).not.toHaveBeenCalled();
        expect(window.showSuccessToast).toHaveBeenCalledTimes(1);
        expect(window.showSuccessToast.mock.calls[0][0]).toContain('1');
    });

    it('sem vídeo selecionado: manda todos, sem toast de exclusão', async () => {
        window.axios = { post: vi.fn(() => Promise.resolve({ data: new Blob(['x']) })) };
        window.showSuccessToast = vi.fn();

        const wrapper = await render();
        wrapper.vm.selectedPatient = { id: 'pat-1', exams: [examPhoto, examPhoto2] };
        wrapper.vm.selectedExamIds = ['photo-1', 'photo-2'];

        await wrapper.vm.downloadMontage();
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            '/montage',
            { exam_ids: ['photo-1', 'photo-2'], columns: 2 },
            { responseType: 'blob' },
        );
        expect(window.showSuccessToast).not.toHaveBeenCalled();
    });

    it('menos de 2 exames sobrando depois de filtrar vídeo: não chama backend, avisa erro', async () => {
        window.axios = { post: vi.fn() };
        window.showErrorToast = vi.fn();

        const wrapper = await render();
        wrapper.vm.selectedPatient = { id: 'pat-1', exams: [examPhoto, examVideoWebm] };
        wrapper.vm.selectedExamIds = ['photo-1', 'video-1'];

        await wrapper.vm.downloadMontage();
        await flushPromises();

        expect(window.axios.post).not.toHaveBeenCalled();
        expect(window.showErrorToast).toHaveBeenCalled();
    });
});

describe('ExamManager — laudo (Modelos) não oferece vídeo como candidato de imagem (Fase 5 / 6c, revisão Codex)', () => {
    it('reportExamGroups: olho com vídeo mantém o exam_id (seleção do laudo normal), mas nunca entra em images (candidato de "Inserir imagem"/"Imagens no laudo")', async () => {
        const wrapper = await render();
        wrapper.vm.selectedPatient = { id: 'pat-1', exams: [examPhoto, examVideoWebm] };
        wrapper.vm.examUrls = { 'photo-1': '/x1.jpg', 'video-1': '/v1.webm' };
        wrapper.vm.reportExamIds = ['photo-1', 'video-1'];

        const groups = wrapper.vm.reportExamGroups;
        expect(groups).toHaveLength(1);
        const [od, oe] = groups[0].eyes;

        expect(od.examIds).toEqual(['photo-1']);
        expect(od.images.map((i) => i.id)).toEqual(['photo-1']);

        // Vídeo tem URL preenchida como qualquer exame — sem o filtro,
        // passaria no .filter((img) => img.url) e viraria candidato de
        // imagem (preview quebrado / risco de entrar no corpo do laudo como
        // <img> — achado via revisão Codex).
        expect(oe.examIds).toEqual(['video-1']);
        expect(oe.images).toEqual([]);
    });
});

describe('ExamManager — Comparar exclui vídeo (Fase 5 / 6c, revisão Codex)', () => {
    it('vídeo + foto selecionados (2 itens): "Comparar" fica indisponível — comparar vídeo como <img> quebra', async () => {
        const wrapper = await render();
        wrapper.vm.selectedPatient = { id: 'pat-1', exams: [examPhoto, examVideoWebm] };
        wrapper.vm.selectedExamIds = ['photo-1', 'video-1'];

        // 2 selecionados, mas só 1 é elegível (não-vídeo) — abrir o modal
        // de comparação com um vídeo renderiza <img src="...webm">
        // (preview quebrado) e não é uma "comparação" válida com só 1 lado
        // de verdade (achado via revisão Codex).
        expect(wrapper.vm.compareEligibleExamIds).toEqual(['photo-1']);
        wrapper.vm.openCompareModal();
        expect(wrapper.vm.compareModalOpen).toBe(false);
    });

    it('duas fotos selecionadas: "Comparar" continua funcionando normalmente (comportamento inalterado)', async () => {
        const wrapper = await render();
        wrapper.vm.selectedPatient = { id: 'pat-1', exams: [examPhoto, examPhoto2] };
        wrapper.vm.selectedExamIds = ['photo-1', 'photo-2'];

        expect(wrapper.vm.compareEligibleExamIds).toEqual(['photo-1', 'photo-2']);
        wrapper.vm.openCompareModal();
        expect(wrapper.vm.compareModalOpen).toBe(true);
        expect(wrapper.vm.compareImages.map((i) => i.id)).toEqual(['photo-1', 'photo-2']);
    });
});
