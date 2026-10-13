import { describe, it, expect, vi, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import Index from '@/Pages/Panel/EyeImages/Index.vue';

/**
 * Rascunho reaberto (Fase 5 — secretária-rascunha/médico-emite) nunca manda
 * examGroups pro EyeImageReportModal (sem checkbox "Exames neste laudo" —
 * ver template de Index.vue), então exam_ids do rascunho ia direto pro laudo
 * sem nenhuma UI pra tirar um exame. Exame excluído do banco ou desativado
 * (active=false) DEPOIS que o rascunho foi criado ficava morto ali pra
 * sempre: emitir sempre travava em 422/403 (assertExamsActive()/
 * ownedExamIds()), sem nenhuma forma de corrigir (achado via revisão Codex).
 * openDraftInReportModal() agora revalida contra o endpoint de exames do
 * paciente (o mesmo já usado pra montar os candidatos de "Inserir imagem")
 * e tira os indisponíveis antes de abrir o modal.
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /><slot name="top-actions" /></div>' } }));

const baseProps = {
    entity: { id: 'entity-1' },
    patients: [],
    urls: {
        patient_urls: '/patient/__ID__/urls',
        image_url: '/exam/__ID__/url',
        montage: '/montage',
        report_drafts_show: '/drafts/__ID__',
        patient_exams: '/patient-exams/__ID__',
    },
};
const wrappers = [];

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
    delete window.showErrorToast;
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

function mockAxios({ examIds, patientExams }) {
    window.axios = {
        get: vi.fn((url) => {
            if (url === '/drafts/draft-1') {
                return Promise.resolve({
                    data: {
                        id: 'draft-1',
                        patient: { id: 'pat-1', name: 'Paciente Teste' },
                        exam_ids: examIds,
                        content: '<p>Achados.</p>',
                    },
                });
            }
            if (url === '/patient-exams/pat-1') {
                return Promise.resolve({ data: { exams: patientExams } });
            }
            return Promise.resolve({ data: {} });
        }),
    };
}

describe('EyeImages — rascunho reaberto revalida exam_ids indisponíveis (Fase 5 / revisão Codex)', () => {
    it('[GAP] exame desativado (active=false) é removido do rascunho ao reabrir, com aviso', async () => {
        window.showErrorToast = vi.fn();
        mockAxios({
            examIds: ['exam-1', 'exam-2'],
            patientExams: [
                { id: 'exam-1', active: true, display_url: '/x1.jpg' },
                { id: 'exam-2', active: false, display_url: '/x2.jpg' },
            ],
        });

        const wrapper = await render();
        await wrapper.vm.openDraftInReportModal({ id: 'draft-1' });
        await flushPromises();

        expect(wrapper.vm.activeDraft.exam_ids).toEqual(['exam-1']);
        expect(wrapper.vm.reportModalOpen).toBe(true);
        expect(window.showErrorToast).toHaveBeenCalledTimes(1);
    });

    it('[GAP] exame removido do banco (ausente na lista do paciente) também é retirado, sem quebrar', async () => {
        window.showErrorToast = vi.fn();
        mockAxios({
            examIds: ['exam-1', 'exam-deleted'],
            patientExams: [{ id: 'exam-1', active: true, display_url: '/x1.jpg' }],
        });

        const wrapper = await render();
        await wrapper.vm.openDraftInReportModal({ id: 'draft-1' });
        await flushPromises();

        expect(wrapper.vm.activeDraft.exam_ids).toEqual(['exam-1']);
        expect(window.showErrorToast).toHaveBeenCalledTimes(1);
    });

    it('todos os exames disponíveis: abre sem aviso, exam_ids intactos (comportamento inalterado)', async () => {
        window.showErrorToast = vi.fn();
        mockAxios({
            examIds: ['exam-1', 'exam-2'],
            patientExams: [
                { id: 'exam-1', active: true, display_url: '/x1.jpg' },
                { id: 'exam-2', active: true, display_url: '/x2.jpg' },
            ],
        });

        const wrapper = await render();
        await wrapper.vm.openDraftInReportModal({ id: 'draft-1' });
        await flushPromises();

        expect(wrapper.vm.activeDraft.exam_ids).toEqual(['exam-1', 'exam-2']);
        expect(window.showErrorToast).not.toHaveBeenCalled();
    });

    it('[GAP] vídeo capturado continua no rascunho (exam_ids), mas nunca entra como candidato de "Inserir imagem"/"Imagens no laudo"', async () => {
        window.showErrorToast = vi.fn();
        mockAxios({
            examIds: ['exam-1', 'video-1'],
            patientExams: [
                { id: 'exam-1', active: true, display_url: '/x1.jpg', is_video: false },
                { id: 'video-1', active: true, display_url: '/v1.webm', is_video: true },
            ],
        });

        const wrapper = await render();
        await wrapper.vm.openDraftInReportModal({ id: 'draft-1' });
        await flushPromises();

        // is_video nunca remove o exame do rascunho (diferente de
        // active=false) — é só pra "Inserir imagem"/"Imagens no laudo", que
        // rendem <img> e quebrariam com uma URL de vídeo (achado via revisão
        // Codex).
        expect(wrapper.vm.activeDraft.exam_ids).toEqual(['exam-1', 'video-1']);
        expect(window.showErrorToast).not.toHaveBeenCalled();
        expect(wrapper.vm.draftExamImages.map((i) => i.id)).toEqual(['exam-1']);
    });
});

describe('EyeImages — abrir outro laudo invalida um rascunho ainda carregando (Fase 5 / revisão Codex)', () => {
    it('[GAP] resposta obsoleta do rascunho A nunca sobrescreve activeDraft depois que o usuário já abriu o laudo do paciente B', async () => {
        const resolvers = [];
        window.axios = {
            get: vi.fn((url) => {
                if (url === '/drafts/draft-1') {
                    return new Promise((resolve) => resolvers.push(resolve));
                }
                return Promise.resolve({ data: {} });
            }),
        };

        const wrapper = await render();

        // Usuário abre o rascunho A — GET em voo, nunca resolve ainda.
        const openA = wrapper.vm.openDraftInReportModal({ id: 'draft-1' });
        await flushPromises();

        // ANTES da resposta de A chegar, abre o laudo (NOVO) do paciente B,
        // já selecionado na tela — mesmo cenário real de trocar de
        // paciente/laudo rápido na galeria.
        wrapper.vm.selectedPatient = { id: 'pat-b', exams: [] };
        wrapper.vm.openReportModal();

        expect(wrapper.vm.reportModalOpen).toBe(true);
        expect(wrapper.vm.activeDraft).toBeNull();

        // SÓ AGORA a resposta de A (obsoleta) chega.
        resolvers[0]({
            data: {
                id: 'draft-1',
                patient: { id: 'pat-a', name: 'Paciente A' },
                exam_ids: ['exam-a'],
                content: '<p>Achados do paciente A.</p>',
            },
        });
        await openA;
        await flushPromises();

        // activeDraft nunca pode virar os dados do rascunho A enquanto o
        // laudo do paciente B já está aberto — salvar mandaria o conteúdo
        // digitado pro paciente B com o patient_id do paciente A.
        expect(wrapper.vm.activeDraft).toBeNull();
        expect(wrapper.vm.reportModalOpen).toBe(true);
    });
});
