import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import MedicalRecordImagingModal from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordImagingModal.vue';

/**
 * Painel "Exames de imagem" do prontuário — consome o MESMO endpoint
 * patientExamsForRecord() do Gerenciador de exames (urls.eye_exams), que já
 * expõe is_video (Fase 5 / 6a, revisão Codex) pra vídeo capturado pela
 * câmera nunca renderizar como <img> (preview/miniatura quebrados, já que
 * display_url/thumb_url apontam pro próprio arquivo .webm/.mp4 sem
 * derivado JPEG — GenerateExamDerivatives não gera poster-frame ainda).
 *
 * <Teleport to="body"> escapa da raiz do wrapper — consultas via
 * document.body, nunca wrapper.find() (mesmo padrão de ExamManagerReportModal.test.js).
 */
const wrappers = [];

beforeEach(() => {
    document.body.innerHTML = '';
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    delete global.fetch;
});

async function mountOpen(exams) {
    global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: async () => ({ exams }) }));
    const wrapper = mount(MedicalRecordImagingModal, {
        attachTo: document.body,
        props: { show: false, fetchUrl: '/eye-exams', t: {} },
    });
    await wrapper.setProps({ show: true });
    await flushPromises();
    wrappers.push(wrapper);
    return wrapper;
}

const videoExam = {
    id: 'video-1',
    exam_type: 'Vídeo',
    laterality: 'OD',
    display_url: '/v1.webm',
    thumb_url: '/v1.webm',
    original_url: '/v1.webm',
    is_video: true,
};
const photoExam = {
    id: 'photo-1',
    exam_type: 'Foto',
    laterality: 'OD',
    display_url: '/p1.jpg',
    thumb_url: '/p1-thumb.jpg',
    original_url: '/p1.jpg',
    is_video: false,
};

describe('MedicalRecordImagingModal — vídeo capturado nunca renderiza como <img> (Fase 5 / 6a, revisão Codex)', () => {
    it('[GAP] visualização ampliada: vídeo usa <video controls>, nunca <img>', async () => {
        await mountOpen([videoExam]);

        const video = document.body.querySelector('.flex-grow-1 video');
        expect(video).not.toBeNull();
        expect(video.getAttribute('src')).toBe('/v1.webm');
        expect(video.hasAttribute('controls')).toBe(true);
        expect(document.body.querySelector('.flex-grow-1 img')).toBeNull();
    });

    it('[GAP] miniatura: vídeo mostra ícone, nunca tenta <img> (sem poster-frame gerado)', async () => {
        await mountOpen([videoExam]);

        expect(document.body.querySelector('.list-group-item img')).toBeNull();
        expect(document.body.querySelector('.list-group-item i.fa-video')).not.toBeNull();
    });

    it('foto continua renderizando <img> normalmente na ampliada e na miniatura (comportamento inalterado)', async () => {
        await mountOpen([photoExam]);

        expect(document.body.querySelector('.flex-grow-1 img')).not.toBeNull();
        expect(document.body.querySelector('.flex-grow-1 video')).toBeNull();
        expect(document.body.querySelector('.list-group-item img')).not.toBeNull();
    });
});
