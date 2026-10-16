import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import EyeImageReportModal from '@/Pages/Panel/EyeImages/EyeImageReportModal.vue';
import DictationButton from '@/Components/Panel/DictationButton.vue';

/**
 * Ditado por voz no laudo (Fase 5): botão só com o recurso disponível, texto
 * inserido no cursor do editor e travas da emissão (escritor assíncrono do
 * conteúdo: o texto que chega depois que a emissão começou é descartado).
 */
const urls = {
    templates: '/t',
    preview: '/p',
    store: '/s',
    dictate: '/_routes/eye-images.reports.dictate',
    draftStore: '/_routes/eye-images.report-drafts.store',
    draftUpdate: '/_routes/eye-images.report-drafts.update/__ID__',
    draftSubmit: '/_routes/eye-images.report-drafts.submit/__ID__',
};
const patient = { id: 'pat-1', code: '123', name: 'Paciente Teste' };
const available = { available: true, max_seconds: 120 };

function stubBrowserAudio() {
    vi.stubGlobal('MediaRecorder', class {});
    vi.stubGlobal('AudioContext', class {});
    vi.stubGlobal('OfflineAudioContext', class {});
    Object.defineProperty(navigator, 'mediaDevices', { value: { getUserMedia: vi.fn() }, configurable: true });
}

async function openModal(props = {}) {
    const wrapper = mount(EyeImageReportModal, {
        attachTo: document.body,
        props: { open: false, patient, examIds: ['exam-1'], urls, t: {}, isDoctor: true, ...props },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

const byTestId = (id) => document.body.querySelector(`[data-testid="${id}"]`);

beforeEach(() => {
    document.body.innerHTML = '';
    stubBrowserAudio();
    globalThis.window.axios = {
        get: vi.fn(() => Promise.resolve({ data: { data: [] } })),
        post: vi.fn(() => Promise.resolve({ data: {} })),
        put: vi.fn(() => Promise.resolve({ data: {} })),
        delete: vi.fn(() => Promise.resolve({})),
    };
});

describe('EyeImageReportModal — ditado por voz (Fase 5)', () => {
    it('sem o recurso disponível (padrão), não há botão nem aviso de privacidade', async () => {
        const wrapper = await openModal();

        expect(byTestId('dictation-button')).toBeNull();
        expect(byTestId('dictation-notice')).toBeNull();
        wrapper.unmount();
    });

    it('com o recurso disponível mostra o botão "Ditar" e o aviso de que o áudio vai a um provedor aprovado', async () => {
        const wrapper = await openModal({ dictation: available });

        expect(byTestId('dictation-button')).not.toBeNull();
        expect(byTestId('dictation-notice').textContent).toContain('não é guardado');
        expect(byTestId('dictation-notice').textContent).toContain('Não cite o nome do paciente');
        wrapper.unmount();
    });

    it('sem URL do ditado, o botão não aparece mesmo com o recurso disponível', async () => {
        const wrapper = await openModal({ dictation: available, urls: { ...urls, dictate: '' } });

        expect(byTestId('dictation-button')).toBeNull();
        wrapper.unmount();
    });

    it('o texto transcrito entra no cursor do editor, escapado', async () => {
        const wrapper = await openModal({ dictation: available });
        const insert = vi.fn();
        wrapper.vm.editorRef = { insertContent: insert };

        wrapper.vm.onDictated('Cristalino <b>transparente</b>.');

        expect(insert).toHaveBeenCalledWith('Cristalino &lt;b&gt;transparente&lt;/b&gt;. ');
        wrapper.unmount();
    });

    it('[GAP] texto que chega com a emissão em andamento (ou já concluída) é descartado — o editor não corresponde mais ao enviado', async () => {
        const wrapper = await openModal({ dictation: available });
        const insert = vi.fn();
        wrapper.vm.editorRef = { insertContent: insert };

        wrapper.vm.saving = true;
        wrapper.vm.onDictated('texto tardio');
        wrapper.vm.saving = false;

        wrapper.vm.savingDraft = true;
        wrapper.vm.onDictated('texto tardio de rascunho');
        wrapper.vm.savingDraft = false;

        wrapper.vm.savedResult = { id: 'doc-1' };
        wrapper.vm.onDictated('texto depois do sucesso');

        expect(insert).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('gravando ou transcrevendo, "Salvar"/"Emitir" ficam travados (o texto que vem não estaria no enviado)', async () => {
        const wrapper = await openModal({ dictation: available });
        const save = () =>
            Array.from(document.body.querySelectorAll('.modal-footer button')).find((b) =>
                b.textContent.includes('Salvar laudo'),
            );
        expect(save()).toBeDefined();
        expect(save().disabled).toBe(false);

        wrapper.findComponent(DictationButton).vm.$emit('busy', true);
        await flushPromises();
        expect(save().disabled).toBe(true);

        wrapper.findComponent(DictationButton).vm.$emit('busy', false);
        await flushPromises();
        expect(save().disabled).toBe(false);
        wrapper.unmount();
    });

    it('quem prepara o rascunho também fica travado enquanto dita', async () => {
        const wrapper = await openModal({ dictation: available, isDoctor: false });
        wrapper.findComponent(DictationButton).vm.$emit('busy', true);
        await flushPromises();

        const buttons = Array.from(document.body.querySelectorAll('.modal-footer button')).filter(
            (b) => b.textContent.includes('Salvar rascunho') || b.textContent.includes('Enviar para o médico'),
        );
        expect(buttons.length).toBeGreaterThanOrEqual(2);
        expect(buttons.every((b) => b.disabled)).toBe(true);
        wrapper.unmount();
    });

    it('erro do ditado vira aviso na tela (toast), não quebra o laudo', async () => {
        window.showErrorToast = vi.fn();
        const wrapper = await openModal({ dictation: available });

        wrapper.findComponent(DictationButton).vm.$emit('error', 'Nenhuma fala reconhecida.');

        expect(window.showErrorToast).toHaveBeenCalledWith('Nenhuma fala reconhecida.');
        wrapper.unmount();
    });
});

describe('DictationButton — navegador sem gravação', () => {
    it('sem MediaRecorder/getUserMedia o botão nem aparece (nada de ditado pelo reconhecimento do navegador)', async () => {
        vi.unstubAllGlobals();
        Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true });

        const wrapper = mount(DictationButton, { props: { url: '/d' } });
        await flushPromises();

        expect(wrapper.find('[data-testid="dictation-button"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('com gravação disponível, o botão aparece e fica desabilitado quando o pai trava', async () => {
        const wrapper = mount(DictationButton, { props: { url: '/d', disabled: true } });
        await flushPromises();

        expect(wrapper.find('[data-testid="dictation-button"]').attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });
});
