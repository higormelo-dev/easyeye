import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import EyeImageReportModal from '@/Pages/Panel/EyeImages/EyeImageReportModal.vue';

/**
 * form.content é setado direto via wrapper.vm (script setup expõe os
 * bindings do topo em dev/test) em vez de digitar no TinyMCE: o stub global
 * de window.tinymce (tests/JavaScript/setup.js) não dispara os callbacks
 * registrados via editor.on(...), então não há como simular digitação real
 * — mesmo padrão já usado em AiAssistantPanel.test.js (vm.runId = ...).
 */

/**
 * Cobre as 3 adaptações do benchmark 18/09/2026 (Wizard/"Auto Load Image"/
 * extração de texto de PDF do concorrente): inserir imagem no cursor,
 * biblioteca de frases rápidas do médico, extração de texto de PDF —
 * todas reaproveitam TinyMceEditor::insertContent() (mockado aqui: JSDOM
 * não roda o TinyMCE de verdade, só o stub — ver setup.js).
 */
describe('EyeImageReportModal — inserir imagem / frases rápidas / extrair PDF', () => {
    const urls = {
        templates: '/_routes/eye-images.report-templates.index',
        preview: '/_routes/eye-images.report-templates.preview',
        store: '/_routes/eye-images.reports.store',
        phrasesIndex: '/_routes/eye-images.report-phrases.index',
        phrasesStore: '/_routes/eye-images.report-phrases.store',
        phrasesDestroy: '/_routes/eye-images.report-phrases.destroy/__ID__',
        extractPdfText: '/_routes/eye-images.reports.extract-pdf-text',
    };
    const patient = { id: 'pat-1', code: '123', name: 'Amanda Alves de Moura' };
    const examImages = [{ id: 'exam-1', url: 'https://s3.example/exam-1.jpg', label: 'Pentacam — OD' }];

    function mountModal(propsOverride = {}) {
        return mount(EyeImageReportModal, {
            attachTo: document.body,
            props: {
                open: true,
                patient,
                examIds: ['exam-1'],
                examImages,
                urls,
                t: {},
                ...propsOverride,
            },
        });
    }

    function findButton(text) {
        return Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent.includes(text));
    }

    beforeEach(() => {
        document.body.innerHTML = '';
        globalThis.window.axios = {
            get: vi.fn(() => Promise.resolve({ data: { data: [] } })),
            post: vi.fn(() => Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } })),
            delete: vi.fn(() => Promise.resolve({})),
        };
    });

    it('mostra "Inserir imagem" quando examImages não é vazio, e insere no cursor ao clicar', async () => {
        const wrapper = mountModal();
        await flushPromises();

        findButton('Inserir imagem').click();
        await wrapper.vm.$nextTick();

        findButton('Pentacam — OD').click();
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.form.content).toContain('exam-1.jpg');
        wrapper.unmount();
    });

    it('sem examImages, não mostra "Inserir imagem"', async () => {
        const wrapper = mountModal({ examImages: [] });
        await flushPromises();

        expect(findButton('Inserir imagem')).toBeUndefined();
        wrapper.unmount();
    });

    it('busca frases rápidas ao (re)abrir o modal (watch open: false→true)', async () => {
        // Mesma técnica de ManualCreditModal.test.js ("reseta o form quando
        // reabre"): montar com open:true direto NUNCA dispara o
        // watch(() => props.open, ...) — ele só reage a uma MUDANÇA de
        // valor, e é assim que fetchTemplates()/fetchPhrases() disparam de
        // verdade em produção (reportModalOpen começa false, só vira true
        // quando o médico abre o laudo).
        window.axios.get = vi.fn(() =>
            Promise.resolve({
                data: { data: [{ id: 'phrase-1', label: 'Fundo de olho normal', content: '<p>Sem alterações.</p>' }] },
            }),
        );
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledWith(urls.phrasesIndex);
        expect(wrapper.vm.phrases).toHaveLength(1);
        wrapper.unmount();
    });

    it('insere o conteúdo da frase no cursor', async () => {
        const wrapper = mountModal();
        await flushPromises();

        wrapper.vm.insertPhrase({ id: 'phrase-1', label: 'Fundo de olho normal', content: '<p>Sem alterações.</p>' });
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.form.content).toContain('Sem alterações');
        expect(wrapper.vm.showPhrasesPicker).toBe(false);
        wrapper.unmount();
    });

    it('salvar seleção como frase sem nada selecionado mostra aviso, não chama a API', async () => {
        const wrapper = mountModal();
        await flushPromises();
        window.showErrorToast = vi.fn();

        await wrapper.vm.savePhraseFromSelection();

        expect(window.axios.post).not.toHaveBeenCalledWith(urls.phrasesStore, expect.anything());
        expect(window.showErrorToast).toHaveBeenCalled();
        wrapper.unmount();
    });

    it('mostra "Extrair texto do PDF" quando há exam_ids, e insere o texto extraído', async () => {
        window.axios.post = vi.fn((url) => {
            if (url === urls.extractPdfText) return Promise.resolve({ data: { text: 'Achado extraído do PDF.' } });
            return Promise.resolve({ data: {} });
        });
        const wrapper = mountModal();
        await flushPromises();

        const btn = findButton('Extrair texto do PDF');
        expect(btn).toBeTruthy();
        btn.click();
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(urls.extractPdfText, { exam_ids: ['exam-1'] });
        expect(wrapper.vm.form.content).toContain('Achado extraído do PDF');
        wrapper.unmount();
    });

    it('sem exam_ids, não mostra "Extrair texto do PDF"', async () => {
        const wrapper = mountModal({ examIds: [] });
        await flushPromises();

        expect(findButton('Extrair texto do PDF')).toBeUndefined();
        wrapper.unmount();
    });
});

describe('EyeImageReportModal — laudo conjunto (vários exames)', () => {
    const urls = { templates: '/t', preview: '/p', store: '/s' };
    const patient = { id: 'pat-1', code: '123', name: 'Paciente Teste' };
    const examGroups = [
        {
            key: 'g1',
            label: 'RETINOGRAFIA — 30/09/2026',
            eyes: [
                { key: '1', label: 'OD', examIds: ['e1'], images: [{ id: 'e1', url: '/img/e1.jpg', label: 'OD' }] },
                { key: '2', label: 'OE', examIds: ['e2'], images: [{ id: 'e2', url: '/img/e2.jpg', label: 'OE' }] },
            ],
        },
        {
            key: 'g2',
            label: 'OCT — 30/09/2026',
            eyes: [{ key: '1', label: 'OD', examIds: ['e3'], images: [{ id: 'e3', url: '/img/e3.jpg', label: 'OD' }] }],
        },
    ];

    // Abre como o botão Laudo de um painel: exame g1 (OD + OE) marcado.
    function mountModal(propsOverride = {}) {
        return mount(EyeImageReportModal, {
            attachTo: document.body,
            props: { open: true, patient, examIds: ['e1', 'e2'], urls, t: {}, examGroups, ...propsOverride },
        });
    }

    const eyeBox = (value) => document.body.querySelector(`input[type="checkbox"][value="${value}"]`);
    const examBox = (label) =>
        [...document.body.querySelectorAll('li label')]
            .find((l) => l.textContent.includes(label))
            .querySelector('input');

    async function save(wrapper) {
        wrapper.vm.form.content = '<p>Achados.</p>';
        await wrapper.vm.save(false);
        await flushPromises();
    }

    beforeEach(() => {
        document.body.innerHTML = '';
        globalThis.window.axios = {
            get: vi.fn(() => Promise.resolve({ data: { data: [] } })),
            post: vi.fn(() => Promise.resolve({ data: { title: 'Laudo', pdf_url: '/pdf/1' } })),
        };
    });

    const savedExamIds = () => window.axios.post.mock.calls.find(([url]) => url === '/s')?.[1].exam_ids;

    it('lista os exames com um item por olho', async () => {
        const wrapper = mountModal();
        await flushPromises();

        const text = document.body.textContent;
        expect(text).toContain('Exames neste laudo');
        expect(text).toContain('RETINOGRAFIA — 30/09/2026');
        expect(text).toContain('OCT — 30/09/2026');
        expect(eyeBox('g1|1')).not.toBeNull();
        expect(eyeBox('g1|2')).not.toBeNull();
        wrapper.unmount();
    });

    it('abre com os olhos do exame do painel marcados e o outro exame desmarcado', async () => {
        const wrapper = mountModal();
        await flushPromises();

        expect(eyeBox('g1|1').checked).toBe(true);
        expect(eyeBox('g1|2').checked).toBe(true);
        expect(eyeBox('g2|1').checked).toBe(false);
        wrapper.unmount();
    });

    it('desmarcar um olho lauda só o outro olho do mesmo exame', async () => {
        const wrapper = mountModal();
        await flushPromises();

        eyeBox('g1|2').click();
        await flushPromises();
        await save(wrapper);

        expect(savedExamIds()).toEqual(['e1']);
        wrapper.unmount();
    });

    it('marcar olho de outro exame vira laudo conjunto', async () => {
        const wrapper = mountModal();
        await flushPromises();

        eyeBox('g2|1').click();
        await flushPromises();
        await save(wrapper);

        expect(savedExamIds()).toEqual(['e1', 'e2', 'e3']);
        wrapper.unmount();
    });

    it('caixa do exame marca/desmarca todos os olhos e fica indeterminada com marcação parcial', async () => {
        const wrapper = mountModal();
        await flushPromises();

        eyeBox('g1|2').click();
        await flushPromises();
        expect(examBox('RETINOGRAFIA').indeterminate).toBe(true);

        examBox('RETINOGRAFIA').click();
        await flushPromises();
        expect(eyeBox('g1|1').checked).toBe(true);
        expect(eyeBox('g1|2').checked).toBe(true);

        examBox('RETINOGRAFIA').click();
        await flushPromises();
        expect(eyeBox('g1|1').checked).toBe(false);
        expect(eyeBox('g1|2').checked).toBe(false);
        wrapper.unmount();
    });

    it('nenhum olho marcado: não salva e avisa', async () => {
        const wrapper = mountModal();
        await flushPromises();

        examBox('RETINOGRAFIA').click();
        await flushPromises();
        await save(wrapper);

        expect(savedExamIds()).toBeUndefined();
        expect(document.body.textContent).toContain('Marque ao menos um exame para laudar.');
        wrapper.unmount();
    });

    it('"Inserir imagem" oferece só imagens dos olhos marcados', async () => {
        const wrapper = mountModal();
        await flushPromises();

        expect(wrapper.vm.availableImages.map((i) => i.id)).toEqual(['e1', 'e2']);
        eyeBox('g1|1').click();
        await flushPromises();
        eyeBox('g2|1').click();
        await flushPromises();
        expect(wrapper.vm.availableImages.map((i) => i.id)).toEqual(['e2', 'e3']);
        wrapper.unmount();
    });

    it('sem examGroups, não mostra a faixa de exames', async () => {
        const wrapper = mountModal({ examGroups: [] });
        await flushPromises();

        expect(document.body.textContent).not.toContain('Exames neste laudo');
        wrapper.unmount();
    });

    it('encaixado (docked): painel à direita sem fundo escuro, e sobe SweetAlert/TinyMCE acima dele', async () => {
        const wrapper = mountModal({ docked: true });
        await flushPromises();

        expect(document.body.querySelector('.ei-report-dock')).not.toBeNull();
        expect(document.body.querySelector('.modal.show')).toBeNull();
        expect(document.body.classList.contains('ei-report-docked')).toBe(true);

        await wrapper.setProps({ open: false });
        expect(document.body.classList.contains('ei-report-docked')).toBe(false);
        wrapper.unmount();
    });

    it('sem docked continua modal centralizado (comportamento antigo)', async () => {
        const wrapper = mountModal();
        await flushPromises();

        expect(document.body.querySelector('.modal.show')).not.toBeNull();
        expect(document.body.querySelector('.ei-report-dock')).toBeNull();
        expect(document.body.classList.contains('ei-report-docked')).toBe(false);
        wrapper.unmount();
    });

    it('expõe close() (visualizador fecha o laudo pelo mesmo caminho do X)', async () => {
        const wrapper = mountModal({ docked: true });
        await flushPromises();

        await wrapper.vm.close();
        expect(wrapper.emitted('close')).toHaveLength(1);
        wrapper.unmount();
    });
});
