import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import EyeImageReportModal from '@/Pages/Panel/EyeImages/EyeImageReportModal.vue';

/**
 * Cobre o laudo em lote (Index.vue::openReportModal quando a seleção cobre
 * 2+ grupos de exame — ver docblock lá): as props/emit novas
 * (queueProgress/queueSummary/nextLabel/@next) são só orquestração do
 * PARENT; aqui testamos só a renderização condicional deste componente,
 * garantindo que fora do laudo em lote (props ausentes) nada muda.
 *
 * form.content é setado direto via wrapper.vm (script setup expõe os
 * bindings do topo em dev/test) em vez de digitar no TinyMCE: o stub global
 * de window.tinymce (tests/JavaScript/setup.js) não dispara os callbacks
 * registrados via editor.on(...), então não há como simular digitação real
 * — mesmo padrão já usado em AiAssistantPanel.test.js (vm.runId = ...).
 */
describe('EyeImageReportModal — laudo em lote', () => {
    const urls = {
        templates: '/_routes/eye-images.report-templates.index',
        preview:   '/_routes/eye-images.report-templates.preview',
        store:     '/_routes/eye-images.reports.store',
    };
    const patient = { id: 'pat-1', code: '123', name: 'Amanda Alves de Moura' };

    function mountModal(propsOverride = {}) {
        return mount(EyeImageReportModal, {
            attachTo: document.body,
            props: {
                open: true,
                patient,
                examIds: ['exam-1'],
                urls,
                t: {},
                ...propsOverride,
            },
        });
    }

    beforeEach(() => {
        document.body.innerHTML = '';
        globalThis.window.axios = {
            get:  vi.fn(() => Promise.resolve({ data: { data: [] } })),
            post: vi.fn(() => Promise.resolve({ data: { title: 'Laudo Pentacan', pdf_url: '/pdf/1' } })),
        };
    });

    async function save(wrapper, content = 'Conteúdo do laudo de teste.') {
        wrapper.vm.form.content = content;
        await wrapper.vm.save(false);
        await flushPromises();
    }

    it('sem queueProgress, não mostra a barra de progresso (fluxo normal preservado)', async () => {
        const wrapper = mountModal();
        await flushPromises();

        expect(document.body.querySelector('.ti-list-numbers')).toBeNull();
        wrapper.unmount();
    });

    it('com queueProgress, mostra "Laudo 1 de 2 — Pentacan"', async () => {
        const wrapper = mountModal({ queueProgress: { current: 1, total: 2, label: 'Pentacan' } });
        await flushPromises();

        const bar = document.body.querySelector('.ti-list-numbers')?.parentElement;
        expect(bar).not.toBeNull();
        expect(bar.textContent).toContain('1');
        expect(bar.textContent).toContain('2');
        expect(bar.textContent).toContain('Pentacan');
        wrapper.unmount();
    });

    it('após salvar com nextLabel definido, mostra botão de avançar e emite "next" ao clicar', async () => {
        const wrapper = mountModal({ nextLabel: 'Próximo laudo: Retinografia' });
        await flushPromises();
        await save(wrapper);

        expect(wrapper.emitted('saved')).toBeTruthy();

        const nextBtn = Array.from(document.body.querySelectorAll('button'))
            .find((b) => b.textContent.includes('Próximo laudo: Retinografia'));
        expect(nextBtn).toBeTruthy();

        nextBtn.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('next')).toBeTruthy();
        wrapper.unmount();
    });

    it('após salvar SEM nextLabel (último passo ou fluxo normal), não mostra botão de avançar', async () => {
        const wrapper = mountModal();
        await flushPromises();
        await save(wrapper);

        const nextBtn = Array.from(document.body.querySelectorAll('button'))
            .find((b) => b.textContent.includes('Próximo laudo'));
        expect(nextBtn).toBeUndefined();
        wrapper.unmount();
    });

    it('mostra a lista de laudos já gerados (queueSummary) com link de download', async () => {
        const wrapper = mountModal({
            queueSummary: [{ label: 'Pentacan', title: 'Laudo Pentacan', pdf_url: '/pdf/pentacan' }],
        });
        await flushPromises();
        await save(wrapper, 'Conteúdo do 2º laudo.');

        expect(document.body.textContent).toContain('Laudos já gerados nesta sessão');
        expect(document.body.textContent).toContain('Pentacan');
        wrapper.unmount();
    });

    it('sem queueSummary, não mostra a lista de laudos anteriores', async () => {
        const wrapper = mountModal();
        await flushPromises();
        await save(wrapper);

        expect(document.body.textContent).not.toContain('Laudos já gerados nesta sessão');
        wrapper.unmount();
    });
});

/**
 * Cobre as 3 adaptações do benchmark 18/09/2026 (Wizard/"Auto Load Image"/
 * extração de texto de PDF do concorrente): inserir imagem no cursor,
 * biblioteca de frases rápidas do médico, extração de texto de PDF —
 * todas reaproveitam TinyMceEditor::insertContent() (mockado aqui: JSDOM
 * não roda o TinyMCE de verdade, só o stub — ver setup.js).
 */
describe('EyeImageReportModal — inserir imagem / frases rápidas / extrair PDF', () => {
    const urls = {
        templates:      '/_routes/eye-images.report-templates.index',
        preview:        '/_routes/eye-images.report-templates.preview',
        store:          '/_routes/eye-images.reports.store',
        phrasesIndex:   '/_routes/eye-images.report-phrases.index',
        phrasesStore:   '/_routes/eye-images.report-phrases.store',
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
            get:  vi.fn(() => Promise.resolve({ data: { data: [] } })),
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
        window.axios.get = vi.fn(() => Promise.resolve({
            data: { data: [{ id: 'phrase-1', label: 'Fundo de olho normal', content: '<p>Sem alterações.</p>' }] },
        }));
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
