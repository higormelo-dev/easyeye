import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import EyeImageReportModal from '@/Pages/Panel/EyeImages/EyeImageReportModal.vue';
import TinyMceEditor from '@/Components/Panel/TinyMceEditor.vue';

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

    it('[GAP] onTemplateChange() envia a data alternativa atual no payload do preview', async () => {
        const wrapper = mountModal({
            dateConfig: { today: '2026-10-09', min: '2026-09-09', max: '2026-11-08' },
        });
        await flushPromises();

        wrapper.vm.form.report_setting_content_id = 'content-1';
        wrapper.vm.form.document_date = '2026-10-05';
        await wrapper.vm.onTemplateChange();

        expect(window.axios.post).toHaveBeenCalledWith(
            urls.preview,
            expect.objectContaining({ document_date: '2026-10-05' }),
        );
        wrapper.unmount();
    });

    it('[GAP] onTemplateChange() sem data alternativa (vazia ou igual a hoje) não envia document_date', async () => {
        const wrapper = mountModal({
            dateConfig: { today: '2026-10-09', min: '2026-09-09', max: '2026-11-08' },
        });
        await flushPromises();

        wrapper.vm.form.report_setting_content_id = 'content-1';
        await wrapper.vm.onTemplateChange();

        const call = window.axios.post.mock.calls.find(([url]) => url === urls.preview);
        expect(call[1].document_date).toBeUndefined();
        wrapper.unmount();
    });

    // [GAP] "Modelos" vem ANTES do campo de data na tela (ordem natural de
    // leitura/preenchimento). Escolher o modelo primeiro e só DEPOIS trocar a
    // data nunca reemitia o preview — {{DATA_ATUAL}}/{{LOCAL_DATA}} ficavam
    // presos na data de quando o modelo foi carregado, mesmo com
    // onTemplateChange() já mandando document_date corretamente (acima).
    it('[GAP] trocar a data DEPOIS de já ter escolhido o modelo reemite o preview com a nova data', async () => {
        const wrapper = mountModal({
            dateConfig: { today: '2026-10-09', min: '2026-09-09', max: '2026-11-08' },
        });
        await flushPromises();

        wrapper.vm.form.report_setting_content_id = 'content-1';
        await wrapper.vm.onTemplateChange(); // 1ª prévia, sem data ainda escolhida
        window.axios.post.mockClear();

        wrapper.vm.onDocumentDateInput('2026-10-05'); // médico só agora escolhe a data
        await flushPromises();

        expect(wrapper.vm.form.document_date).toBe('2026-10-05');
        expect(window.axios.post).toHaveBeenCalledWith(
            urls.preview,
            expect.objectContaining({ document_date: '2026-10-05' }),
        );
        wrapper.unmount();
    });

    it('[GAP] trocar a data depois de EDITAR o conteúdo manualmente não reemite o preview (preserva a edição)', async () => {
        const wrapper = mountModal({
            dateConfig: { today: '2026-10-09', min: '2026-09-09', max: '2026-11-08' },
        });
        await flushPromises();

        wrapper.vm.form.report_setting_content_id = 'content-1';
        await wrapper.vm.onTemplateChange(); // 1ª prévia
        wrapper.vm.form.content = '<p>Achado digitado pelo médico.</p>'; // edição manual
        window.axios.post.mockClear();

        wrapper.vm.onDocumentDateInput('2026-10-06');
        await flushPromises();

        expect(wrapper.vm.form.document_date).toBe('2026-10-06');
        expect(wrapper.vm.form.content).toBe('<p>Achado digitado pelo médico.</p>');
        expect(window.axios.post).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('[GAP] resposta de preview desatualizada (fora de ordem) nunca sobrescreve a mais nova', async () => {
        const wrapper = mountModal({
            dateConfig: { today: '2026-10-09', min: '2026-09-09', max: '2026-11-08' },
        });
        await flushPromises();
        wrapper.vm.form.report_setting_content_id = 'content-1';

        const resolvers = [];
        window.axios.post = vi.fn(() => new Promise((resolve) => resolvers.push(resolve)));

        const first = wrapper.vm.onTemplateChange(); // D1 (mais velha)
        wrapper.vm.form.document_date = '2026-10-06';
        const second = wrapper.vm.onTemplateChange(); // D2 (mais nova)

        // Fora de ordem: a MAIS NOVA responde primeiro.
        resolvers[1]({ data: { content: '<p>Conteúdo da chamada mais nova.</p>' } });
        await second;
        resolvers[0]({ data: { content: '<p>Conteúdo da chamada desatualizada.</p>' } });
        await first;
        await flushPromises();

        expect(wrapper.vm.form.content).toBe('<p>Conteúdo da chamada mais nova.</p>');
        wrapper.unmount();
    });

    it('[GAP] erro de uma chamada de preview já superada não aparece depois de outra ter tido sucesso', async () => {
        const wrapper = mountModal({
            dateConfig: { today: '2026-10-09', min: '2026-09-09', max: '2026-11-08' },
        });
        await flushPromises();
        wrapper.vm.form.report_setting_content_id = 'content-1';

        const resolvers = [];
        const rejecters = [];
        window.axios.post = vi.fn(
            () =>
                new Promise((resolve, reject) => {
                    resolvers.push(resolve);
                    rejecters.push(reject);
                }),
        );

        const first = wrapper.vm.onTemplateChange(); // vai falhar, mas desatualizada
        wrapper.vm.form.document_date = '2026-10-06';
        const second = wrapper.vm.onTemplateChange(); // mais nova, tem sucesso

        resolvers[1]({ data: { content: '<p>Sucesso da chamada mais nova.</p>' } });
        await second;
        rejecters[0](new Error('falha da chamada desatualizada'));
        await first; // onTemplateChange() trata o próprio catch — nunca rejeita pra fora
        await flushPromises();

        expect(wrapper.vm.form.content).toBe('<p>Sucesso da chamada mais nova.</p>');
        expect(wrapper.vm.error).toBe('');
        wrapper.unmount();
    });

    it('[GAP] previewing só desliga quando a chamada MAIS NOVA resolve (resposta desatualizada resolvendo antes não libera "Salvar")', async () => {
        const wrapper = mountModal({
            dateConfig: { today: '2026-10-09', min: '2026-09-09', max: '2026-11-08' },
        });
        await flushPromises();
        wrapper.vm.form.report_setting_content_id = 'content-1';

        const resolvers = [];
        window.axios.post = vi.fn(() => new Promise((resolve) => resolvers.push(resolve)));

        const first = wrapper.vm.onTemplateChange(); // A (desatualizada)
        wrapper.vm.form.document_date = '2026-10-06';
        const second = wrapper.vm.onTemplateChange(); // B (mais nova, ainda pendente)

        // A resolve PRIMEIRO, enquanto B ainda está no ar — o finally de A
        // nunca pode liberar previewing aqui: "Salvar" ficaria disponível
        // com o conteúdo de A (ou o atual, ainda sem o preview de B) junto
        // da data já trocada pra B (achado via revisão Codex).
        resolvers[0]({ data: { content: '<p>Conteúdo de A.</p>' } });
        await first;
        await flushPromises();

        expect(wrapper.vm.previewing).toBe(true);

        resolvers[1]({ data: { content: '<p>Conteúdo de B.</p>' } });
        await second;
        await flushPromises();

        expect(wrapper.vm.previewing).toBe(false);
        expect(wrapper.vm.form.content).toBe('<p>Conteúdo de B.</p>');
        wrapper.unmount();
    });
});

describe('EyeImageReportModal — rascunho (Fase 5, secretária-rascunha/médico-emite)', () => {
    const urls = {
        templates: '/t',
        preview: '/p',
        store: '/s',
        draftStore: '/_routes/eye-images.report-drafts.store',
        draftUpdate: '/_routes/eye-images.report-drafts.update/__ID__',
        draftSubmit: '/_routes/eye-images.report-drafts.submit/__ID__',
        draftDestroy: '/_routes/eye-images.report-drafts.destroy/__ID__',
    };
    const patient = { id: 'pat-1', code: '123', name: 'Paciente Teste' };

    function mountModal(propsOverride = {}) {
        return mount(EyeImageReportModal, {
            attachTo: document.body,
            props: { open: true, patient, examIds: ['exam-1'], urls, t: {}, ...propsOverride },
        });
    }

    function findButton(text) {
        return Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent.includes(text));
    }

    beforeEach(() => {
        document.body.innerHTML = '';
        globalThis.window.axios = {
            get: vi.fn(() => Promise.resolve({ data: { data: [] } })),
            post: vi.fn((url) => {
                if (url === urls.draftStore)
                    return Promise.resolve({ data: { id: 'draft-1', status: 'draft', revision: 1 } });
                if (url === urls.draftSubmit.replace('__ID__', 'draft-1'))
                    return Promise.resolve({ data: { status: 'submitted', revision: 1 } });
                return Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } });
            }),
            put: vi.fn(() => Promise.resolve({ data: { status: 'draft', revision: 1 } })),
            delete: vi.fn(() => Promise.resolve({})),
        };
    });

    it('secretária (não-médico) vê "Salvar rascunho" e "Enviar para o médico", nunca "Salvar laudo"', async () => {
        const wrapper = mountModal({ isDoctor: false });
        await flushPromises();

        expect(findButton('Salvar rascunho')).toBeTruthy();
        expect(findButton('Enviar para o médico')).toBeTruthy();
        expect(findButton('Salvar laudo')).toBeFalsy();
        wrapper.unmount();
    });

    it('médico sem rascunho continua vendo "Salvar laudo" (fluxo direto inalterado)', async () => {
        const wrapper = mountModal({ isDoctor: true });
        await flushPromises();

        expect(findButton('Salvar laudo')).toBeTruthy();
        expect(findButton('Salvar rascunho')).toBeFalsy();
        wrapper.unmount();
    });

    it('secretária: 1º "Salvar rascunho" cria (POST); o 2º atualiza o mesmo rascunho (PUT)', async () => {
        const wrapper = mountModal({ isDoctor: false });
        await flushPromises();
        wrapper.vm.form.content = '<p>Rascunho inicial.</p>';

        await wrapper.vm.saveDraft(false);
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            urls.draftStore,
            expect.objectContaining({ patient_id: 'pat-1' }),
        );
        expect(wrapper.vm.draftId).toBe('draft-1');

        await wrapper.vm.saveDraft(false);
        await flushPromises();

        expect(window.axios.put).toHaveBeenCalledWith(urls.draftUpdate.replace('__ID__', 'draft-1'), expect.anything());
        wrapper.unmount();
    });

    it('[GAP] saveDraft() guarda a revisão devolvida na criação e manda de volta na atualização seguinte (achado via revisão adversarial Codex)', async () => {
        window.axios.post = vi.fn((url) => {
            if (url === urls.draftStore)
                return Promise.resolve({ data: { id: 'draft-1', status: 'draft', revision: 'REV-1' } });
            return Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } });
        });
        window.axios.put = vi.fn(() => Promise.resolve({ data: { status: 'draft', revision: 'REV-2' } }));

        const wrapper = mountModal({ isDoctor: false });
        await flushPromises();
        wrapper.vm.form.content = '<p>Rascunho inicial.</p>';

        await wrapper.vm.saveDraft(false);
        await flushPromises();
        expect(wrapper.vm.draftRevision).toBe('REV-1');

        await wrapper.vm.saveDraft(false);
        await flushPromises();

        expect(window.axios.put).toHaveBeenCalledWith(
            urls.draftUpdate.replace('__ID__', 'draft-1'),
            expect.objectContaining({ draft_revision: 'REV-1' }),
        );
        expect(wrapper.vm.draftRevision).toBe('REV-2'); // avança pro novo token devolvido
        wrapper.unmount();
    });

    it('[GAP] PUT 409 (rascunho corrigido por outra pessoa enquanto esta tela estava aberta): mostra erro claro, nunca reenvia sozinho (achado via revisão adversarial Codex)', async () => {
        const draft = { id: 'draft-1', status: 'draft', revision: 'REV-OLD', content: '<p>Antigo.</p>' };
        window.axios.put = vi.fn(() =>
            Promise.reject({
                response: {
                    status: 409,
                    data: { message: 'Este rascunho foi alterado por outra pessoa enquanto você revisava.' },
                },
            }),
        );

        const wrapper = mountModal({ isDoctor: false, open: false, draft });
        await wrapper.setProps({ open: true });
        await flushPromises();
        expect(wrapper.vm.draftId).toBe('draft-1'); // confirma que reset() populou do prop antes do saveDraft()

        wrapper.vm.form.content = '<p>Tentativa com revisão antiga.</p>';
        await wrapper.vm.saveDraft(false);
        await flushPromises();

        expect(window.axios.put).toHaveBeenCalledTimes(1); // nunca reenvia sozinho
        expect(wrapper.vm.error).toContain('alterado por outra pessoa');
        expect(wrapper.vm.isDirty()).toBe(true); // falhou — a edição tentada continua contando como não salva
        wrapper.unmount();
    });

    it('[GAP] editar DEPOIS de um "Salvar rascunho" bem-sucedido: close() ainda pergunta antes de descartar (achado via revisão adversarial Codex)', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: false })) };
        const wrapper = mountModal({ isDoctor: false });
        await flushPromises();
        wrapper.vm.form.content = '<p>Rascunho salvo.</p>';

        await wrapper.vm.saveDraft(false);
        await flushPromises();
        expect(wrapper.vm.draftId).toBe('draft-1'); // confirma que salvou

        // Edição feita DEPOIS do save bem-sucedido — nunca deve ser tratada
        // como "protegida" só porque ALGUMA versão anterior foi salva nesta
        // sessão (era exatamente o bug: draftSavedOnce ficava true pra
        // sempre e isDirty() nunca mais reavaliava).
        wrapper.vm.form.content = '<p>Edição depois do save, nunca enviada ao servidor.</p>';

        await wrapper.vm.close();

        expect(window.Swal.fire).toHaveBeenCalled(); // perguntou antes de descartar
        expect(wrapper.emitted('close')).toBeUndefined(); // usuário cancelou — nunca fechou
        delete window.Swal;
        wrapper.unmount();
    });

    it('[GAP] editar ENQUANTO o saveDraft() anterior ainda está em voo: a edição nova nunca é marcada como salva quando a resposta chegar (achado via revisão adversarial Codex)', async () => {
        const resolvers = [];
        window.axios.post = vi.fn((url) => {
            if (url === urls.draftStore) return new Promise((resolve) => resolvers.push(resolve));
            return Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } });
        });

        const wrapper = mountModal({ isDoctor: false });
        await flushPromises();
        wrapper.vm.form.content = '<p>V1 (isto que vai ser enviado).</p>';

        const savePromise = wrapper.vm.saveDraft(false); // POST em voo, ainda sem resposta

        // Usuário continua digitando ENQUANTO a requisição do V1 está em
        // voo — o servidor NUNCA viu V2.
        wrapper.vm.form.content = '<p>V2 (digitado depois do envio, nunca chegou ao servidor).</p>';

        resolvers[0]({ data: { id: 'draft-1', status: 'draft', revision: 'REV-1' } });
        await savePromise;
        await flushPromises();

        // Snapshot "salvo" tem que refletir o que foi DE FATO enviado (V1),
        // nunca um draftPayload() relido DEPOIS da resposta (que já seria
        // V2) — senão V2 passaria a contar como salvo sem nunca ter ido ao
        // servidor.
        expect(wrapper.vm.isDirty()).toBe(true);
        wrapper.unmount();
    });

    it('fechar SEM editar nada depois de salvar o rascunho: fecha direto, sem perguntar (nunca um falso positivo)', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: false })) };
        const wrapper = mountModal({ isDoctor: false });
        await flushPromises();
        wrapper.vm.form.content = '<p>Rascunho salvo.</p>';

        await wrapper.vm.saveDraft(false);
        await flushPromises();

        await wrapper.vm.close();

        expect(window.Swal.fire).not.toHaveBeenCalled();
        expect(wrapper.emitted('close')).toHaveLength(1);
        delete window.Swal;
        wrapper.unmount();
    });

    it('[GAP] fechar e reabrir pra OUTRO paciente com um "Salvar rascunho" ainda em voo nunca atribui o draftId do paciente anterior (achado via revisão Codex)', async () => {
        const resolvers = [];
        window.axios.post = vi.fn((url) => {
            if (url === urls.draftStore) return new Promise((resolve) => resolvers.push(resolve));
            return Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } });
        });

        const wrapper = mountModal({ isDoctor: false, open: false });
        await wrapper.setProps({ open: true }); // abre pra Paciente A (sessão 1)
        await flushPromises();
        wrapper.vm.form.content = '<p>Conteúdo do paciente A.</p>';

        const saveA = wrapper.vm.saveDraft(false); // POST draftStore em voo, nunca resolve ainda

        // Fecha e reabre pra OUTRO paciente ANTES da resposta de A chegar —
        // mesmo cenário real de trocar de paciente rápido na galeria.
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true, patient: { id: 'pat-2', code: '456', name: 'Paciente B' } }); // sessão 2
        await flushPromises();
        wrapper.vm.form.content = '<p>Conteúdo do paciente B.</p>';

        // reset() (reabertura) zera savingDraft de imediato — o finally de A
        // (sessão 1) nunca mais vai zerar nada aqui, já que o guard de
        // sessão acima o impede DE PROPÓSITO; sem reset() também zerar,
        // savingDraft ficava true pra sempre e travava "Salvar"/"Enviar"
        // pro paciente B até recarregar a página (achado via revisão Codex).
        expect(wrapper.vm.savingDraft).toBe(false);

        // SÓ AGORA a resposta de A (obsoleta) chega.
        resolvers[0]({ data: { id: 'draft-A', status: 'draft' } });
        await saveA;
        await flushPromises();

        // draftId nunca pode virar o do paciente A enquanto o form já mostra
        // o paciente B — um "Salvar" seguinte viraria PUT no rascunho ERRADO,
        // sobrescrevendo o conteúdo de um paciente com o de outro.
        expect(wrapper.vm.draftId).toBeNull();
        // A resposta obsoleta chegando depois não pode reativar/destravar
        // nada de forma inesperada — continua false.
        expect(wrapper.vm.savingDraft).toBe(false);
        wrapper.unmount();
    });

    it('[GAP] fechar e reabrir pra OUTRO paciente com um preview de modelo ainda em voo nunca sobrescreve o conteúdo do rascunho já salvo (achado via revisão Codex)', async () => {
        const resolvers = [];
        window.axios.post = vi.fn((url) => {
            if (url === urls.preview) return new Promise((resolve) => resolvers.push(resolve));
            return Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } });
        });

        const wrapper = mountModal({ isDoctor: false, open: false });
        await wrapper.setProps({ open: true }); // abre pra Paciente A (sessão 1), sem rascunho
        await flushPromises();
        wrapper.vm.form.report_setting_content_id = 'content-1';

        const previewA = wrapper.vm.onTemplateChange(); // POST preview em voo, nunca resolve ainda

        // Fecha e reabre pra OUTRO paciente, já com um rascunho salvo —
        // mesmo cenário real de trocar de paciente rápido na galeria.
        await wrapper.setProps({ open: false });
        await wrapper.setProps({
            open: true,
            patient: { id: 'pat-2', code: '456', name: 'Paciente B' },
            draft: { id: 'draft-2', status: 'draft', content: '<p>Conteúdo já salvo do paciente B.</p>' },
        }); // sessão 2
        await flushPromises();

        // reset() (reabertura) já carrega o conteúdo salvo do rascunho de B
        // — e precisa invalidar o preview de A em voo, senão a resposta
        // atrasada ainda sobrescreveria isto ao chegar.
        expect(wrapper.vm.form.content).toBe('<p>Conteúdo já salvo do paciente B.</p>');
        expect(wrapper.vm.previewing).toBe(false);

        // SÓ AGORA a resposta de A (obsoleta) chega.
        resolvers[0]({ data: { content: '<p>Prévia do modelo do paciente A.</p>' } });
        await previewA;
        await flushPromises();

        // O conteúdo do rascunho de B nunca pode ser trocado pelo preview
        // obsoleto de A — isto sobrescrevia um rascunho de OUTRO paciente
        // com texto clínico de uma sessão anterior (achado via revisão Codex).
        expect(wrapper.vm.form.content).toBe('<p>Conteúdo já salvo do paciente B.</p>');
        expect(wrapper.vm.previewing).toBe(false);
        wrapper.unmount();
    });

    it('[GAP] fechar e reabrir pra OUTRO paciente com uma emissão ("Salvar laudo") ainda em voo nunca aplica a resposta obsoleta na tela nova, nem esconde a edição não salva — achado na 4ª rodada de revisão adversarial Codex', async () => {
        const resolvers = [];
        window.axios.post = vi.fn((url) => {
            if (url === urls.store) return new Promise((resolve) => resolvers.push(resolve));
            return Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } });
        });

        const draftA = {
            id: 'draft-A',
            status: 'submitted',
            revision: 1,
            content: '<p>Laudo de A, sem alteração.</p>',
        };
        const wrapper = mountModal({ isDoctor: true, open: false, draft: draftA });
        await wrapper.setProps({ open: true }); // abre o rascunho de A (sessão 1)
        await flushPromises();

        const saveA = wrapper.vm.save(); // POST store em voo, nunca resolve ainda

        // Fecha e reabre pra OUTRO paciente, com OUTRO rascunho — mesmo
        // cenário real de trocar de paciente rápido na galeria enquanto uma
        // emissão anterior ainda está voando.
        const draftB = { id: 'draft-B', status: 'submitted', revision: 1, content: '<p>Laudo de B, original.</p>' };
        await wrapper.setProps({ open: false });
        await wrapper.setProps({
            open: true,
            patient: { id: 'pat-2', code: '456', name: 'Paciente B' },
            draft: draftB,
        }); // sessão 2
        await flushPromises();
        wrapper.vm.form.content = '<p>Laudo de B, CORRIGIDO pelo médico.</p>';

        // SÓ AGORA a resposta de A (obsoleta) chega.
        resolvers[0]({ data: { id: 'doc-A', pdf_url: '/pdf/A', title: 'Laudo A' } });
        await saveA;
        await flushPromises();

        // A resposta de A nunca pode aparecer na tela de B — isto escondia
        // o editor de B (savedResult trocava pra tela de "laudo emitido")
        // e fazia isDirty() achar "nada pra perder", descartando a correção
        // de B em silêncio ao fechar (achado via revisão adversarial Codex).
        expect(wrapper.vm.savedResult).toBeNull();
        expect(wrapper.vm.saving).toBe(false); // nunca trava o botão de B
        expect(wrapper.vm.isDirty()).toBe(true); // a correção de B continua contando como não salva
        wrapper.unmount();
    });

    it('secretária: "Enviar para o médico" salva e submete, depois fecha o modal', async () => {
        const wrapper = mountModal({ isDoctor: false });
        await flushPromises();
        wrapper.vm.form.content = '<p>Pronto para revisão.</p>';

        await wrapper.vm.saveDraft(true);
        await flushPromises();

        // draft_revision: 1 — o que o draftStore (criação) acabou de devolver
        // (achado na 5ª rodada de revisão adversarial Codex: submit() nunca
        // escrevia conteúdo, mas precisa saber se alguém mudou o rascunho
        // ENQUANTO esta tela ainda não tinha enviado nada).
        expect(window.axios.post).toHaveBeenCalledWith(urls.draftSubmit.replace('__ID__', 'draft-1'), {
            draft_revision: 1,
        });
        expect(wrapper.emitted('close')).toHaveLength(1);
        wrapper.unmount();
    });

    it('[GAP] submit() nunca "lava" o token de revisão: uma correção concorrente que avança a revisão no servidor não pode ser adotada sem o conteúdo ter sido visto — achado na 5ª rodada de revisão adversarial Codex', async () => {
        window.axios.post = vi.fn((url) => {
            if (url === urls.draftStore)
                return Promise.resolve({ data: { id: 'draft-1', status: 'draft', revision: 1 } });
            if (url === urls.draftSubmit.replace('__ID__', 'draft-1'))
                // Simula: ENQUANTO este submit() estava em voo, outra pessoa
                // salvou uma correção de verdade (revision avançou pra 3 no
                // servidor) — submit() nunca escreve conteúdo, só devolve o
                // revision ATUAL (que já não é mais o desta tela).
                return Promise.resolve({ data: { status: 'submitted', revision: 3 } });
            return Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } });
        });

        const wrapper = mountModal({ isDoctor: false });
        await flushPromises();
        wrapper.vm.form.content = '<p>Pronto para revisão.</p>';

        await wrapper.vm.saveDraft(true);
        await flushPromises();

        // NUNCA 3: adotar o revision da resposta de submit() "lavaria" o
        // token — o próximo save desta tela passaria a checagem de
        // obsolescência (3 === 3 no servidor) mesmo com texto que nunca viu
        // a correção, sobrescrevendo-a sem 409 (achado via revisão
        // adversarial Codex: "these lines adopt that token while retaining
        // A's old text").
        expect(wrapper.vm.draftRevision).toBe(1);
        wrapper.unmount();
    });

    it('[GAP] editar DURANTE o envio ("Enviar para o médico"): nunca fecha por cima da edição mais nova, avisa pra salvar de novo (achado na 2ª rodada de revisão adversarial Codex)', async () => {
        window.showErrorToast = vi.fn();
        const resolvers = {};
        window.axios.post = vi.fn((url) => {
            if (url === urls.draftStore) return new Promise((resolve) => (resolvers.store = resolve));
            if (url === urls.draftSubmit.replace('__ID__', 'draft-1'))
                return new Promise((resolve) => (resolvers.submit = resolve));
            return Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } });
        });

        const wrapper = mountModal({ isDoctor: false });
        await flushPromises();
        wrapper.vm.form.content = '<p>V1 (o que vai ser enviado).</p>';

        const submitPromise = wrapper.vm.saveDraft(true); // dispara o POST de criação, ainda em voo
        await Promise.resolve(); // deixa a chamada síncrona do axios.post acontecer

        // Usuário edita ENQUANTO a criação ainda está em voo — o editor
        // continua habilitado durante savingDraft, e o servidor nunca viu
        // esta edição.
        wrapper.vm.form.content = '<p>V2 (editado depois do envio, nunca chegou ao servidor).</p>';

        resolvers.store({ data: { id: 'draft-1', status: 'draft', revision: 1 } });
        await flushPromises();
        resolvers.submit({ data: { status: 'submitted', revision: 1 } });
        await submitPromise;
        await flushPromises();

        // O envio de V1 teve sucesso (status virou submitted no servidor),
        // mas NUNCA fecha o modal por cima da edição V2 (achado: "this
        // unconditional close discards the correction without checking
        // isDirty()").
        expect(wrapper.vm.draftStatus).toBe('submitted');
        expect(wrapper.emitted('close')).toBeUndefined();
        expect(window.showErrorToast).toHaveBeenCalled();
        delete window.showErrorToast;
        wrapper.unmount();
    });

    it('secretária: enviar sem conteúdo mostra aviso e não chama a API de submit', async () => {
        const wrapper = mountModal({ isDoctor: false });
        await flushPromises();

        await wrapper.vm.saveDraft(true);
        await flushPromises();

        expect(window.axios.post).not.toHaveBeenCalledWith(urls.draftStore, expect.anything());
        expect(document.body.textContent).toContain('Escreva o conteúdo do laudo antes de salvar.');
        wrapper.unmount();
    });

    it('abre com draft: pré-popula o form a partir do rascunho', async () => {
        const draft = {
            id: 'draft-1',
            status: 'submitted',
            patient: { id: 'pat-1', name: 'Paciente Teste' },
            exam_ids: ['exam-1'],
            content: '<p>Conteúdo do rascunho.</p>',
            clinical_indication: 'Rastreio.',
            observations: 'Obs do rascunho.',
            document_date: '2026-10-01',
            report_setting_content_id: '',
            title: '',
        };
        const wrapper = mountModal({ open: false, draft });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(wrapper.vm.form.content).toContain('Conteúdo do rascunho');
        expect(wrapper.vm.form.clinical_indication).toBe('Rastreio.');
        expect(wrapper.vm.draftId).toBe('draft-1');
        expect(wrapper.vm.draftStatus).toBe('submitted');
        wrapper.unmount();
    });

    it('médico revisando um rascunho submetido vê "Revisar e emitir" e manda draft_id ao emitir', async () => {
        const draft = {
            id: 'draft-1',
            status: 'submitted',
            patient: { id: 'pat-1', name: 'Paciente Teste' },
            exam_ids: ['exam-1'],
            content: '<p>Pronto para revisão.</p>',
        };
        const wrapper = mountModal({ open: false, isDoctor: true, draft });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(findButton('Revisar e emitir')).toBeTruthy();
        expect(findButton('Salvar laudo')).toBeFalsy();

        await wrapper.vm.save(false);
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(urls.store, expect.objectContaining({ draft_id: 'draft-1' }));
        wrapper.unmount();
    });

    it('médico sem rascunho: save() não manda draft_id (undefined, nunca vaza no payload)', async () => {
        const wrapper = mountModal({ isDoctor: true });
        await flushPromises();
        wrapper.vm.form.content = '<p>Laudo direto.</p>';

        await wrapper.vm.save(false);
        await flushPromises();

        const call = window.axios.post.mock.calls.find(([url]) => url === urls.store);
        expect(call[1].draft_id).toBeUndefined();
        wrapper.unmount();
    });

    it('[GAP] médico revisando um rascunho submetido: save() manda draft_revision junto com draft_id (achado via revisão adversarial Codex)', async () => {
        const draft = {
            id: 'draft-1',
            status: 'submitted',
            revision: 'REV-1',
            patient: { id: 'pat-1', name: 'Paciente Teste' },
            exam_ids: ['exam-1'],
            content: '<p>Pronto para revisão.</p>',
        };
        const wrapper = mountModal({ open: false, isDoctor: true, draft });
        await wrapper.setProps({ open: true });
        await flushPromises();

        await wrapper.vm.save(false);
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            urls.store,
            expect.objectContaining({ draft_id: 'draft-1', draft_revision: 'REV-1' }),
        );
        wrapper.unmount();
    });

    it('[GAP] emitir com draft_revision obsoleta (409 — outra pessoa corrigiu o rascunho): mostra erro claro, nunca reenvia sozinho nem fecha o modal como se tivesse emitido (achado via revisão adversarial Codex)', async () => {
        const draft = {
            id: 'draft-1',
            status: 'submitted',
            revision: 'REV-OLD',
            patient: { id: 'pat-1', name: 'Paciente Teste' },
            exam_ids: ['exam-1'],
            content: '<p>Texto que o médico abriu.</p>',
        };
        window.axios.post = vi.fn((url) => {
            if (url === urls.store) {
                return Promise.reject({
                    response: {
                        status: 409,
                        data: { message: 'Este rascunho foi alterado por outra pessoa enquanto você revisava.' },
                    },
                });
            }
            return Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } });
        });

        const wrapper = mountModal({ open: false, isDoctor: true, draft });
        await wrapper.setProps({ open: true });
        await flushPromises();

        await wrapper.vm.save(false);
        await flushPromises();

        const storeCalls = window.axios.post.mock.calls.filter(([url]) => url === urls.store);
        expect(storeCalls).toHaveLength(1); // nunca reenvia sozinho — diferente dos 422 (mismatch/record_confirmation)
        expect(wrapper.vm.error).toContain('alterado por outra pessoa');
        expect(wrapper.vm.savedResult).toBeNull(); // nunca marca como emitido
        wrapper.unmount();
    });

    it('descartar rascunho chama DELETE, emite draft-discarded e close', async () => {
        const draft = {
            id: 'draft-1',
            status: 'draft',
            revision: 3,
            patient: { id: 'pat-1', name: 'Paciente Teste' },
            exam_ids: ['exam-1'],
            content: '<p>Rascunho.</p>',
        };
        const wrapper = mountModal({ open: false, isDoctor: false, draft });
        await wrapper.setProps({ open: true });
        await flushPromises();
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true })) };

        await wrapper.vm.discardDraft();
        await flushPromises();

        // draft_revision: backend agora rejeita descarte obsoleto (409) —
        // achado via revisão adversarial Codex.
        expect(window.axios.delete).toHaveBeenCalledWith(urls.draftDestroy.replace('__ID__', 'draft-1'), {
            data: { draft_revision: 3 },
        });
        expect(wrapper.emitted('draft-discarded')).toEqual([[{ id: 'draft-1', status: 'draft' }]]);
        expect(wrapper.emitted('close')).toHaveLength(1);
        wrapper.unmount();
        delete window.Swal;
    });

    it('[GAP] fechar e reabrir pra OUTRO rascunho com um DELETE de descarte ainda em voo nunca fecha a sessão nova (achado via revisão Codex)', async () => {
        const draftA = {
            id: 'draft-a',
            status: 'draft',
            patient: { id: 'pat-1', name: 'Paciente A' },
            exam_ids: ['exam-1'],
            content: '<p>Rascunho A.</p>',
        };
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true })) };

        const resolvers = [];
        window.axios.delete = vi.fn(() => new Promise((resolve) => resolvers.push(resolve)));

        const wrapper = mountModal({ open: false, isDoctor: false, draft: draftA });
        await wrapper.setProps({ open: true }); // abre A (sessão 1)
        await flushPromises();

        const discardA = wrapper.vm.discardDraft(); // confirma + DELETE em voo, nunca resolve ainda
        await flushPromises();

        // Fecha e reabre pra OUTRO rascunho ANTES da resposta do DELETE de A
        // chegar — mesmo cenário real de trocar de rascunho rápido na lista.
        const draftB = {
            id: 'draft-b',
            status: 'submitted',
            patient: { id: 'pat-2', name: 'Paciente B' },
            exam_ids: ['exam-2'],
            content: '<p>Rascunho B, ainda sendo revisado.</p>',
        };
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true, patient: { id: 'pat-2', name: 'Paciente B' }, draft: draftB }); // sessão 2
        await flushPromises();

        // SÓ AGORA a resposta do DELETE de A (obsoleta) chega.
        resolvers[0]({});
        await discardA;
        await flushPromises();

        // draft-discarded continua correto (id/status de A, capturados
        // ANTES do confirm) — mas fechar agora destruiria a revisão de B,
        // ainda em andamento, sem nenhuma confirmação de conteúdo não salvo.
        expect(wrapper.emitted('draft-discarded')).toEqual([[{ id: 'draft-a', status: 'draft' }]]);
        expect(wrapper.emitted('close')).toBeFalsy();
        expect(wrapper.vm.draftId).toBe('draft-b');
        expect(wrapper.vm.form.content).toBe('<p>Rascunho B, ainda sendo revisado.</p>');
        wrapper.unmount();
        delete window.Swal;
    });

    it('sem draftId, botão "Descartar rascunho" não aparece', async () => {
        const wrapper = mountModal({ isDoctor: false });
        await flushPromises();

        expect(findButton('Descartar rascunho')).toBeFalsy();
        wrapper.unmount();
    });
});

describe('EyeImageReportModal — aviso de divergência de médico bloqueia ANTES de emitir (Fase 5 / revisão Codex)', () => {
    const urls = { templates: '/t', preview: '/p', store: '/s' };
    const patient = { id: 'pat-1', code: '123', name: 'Paciente Teste' };

    function mountModal(propsOverride = {}) {
        return mount(EyeImageReportModal, {
            attachTo: document.body,
            props: { open: true, patient, examIds: ['exam-1'], urls, t: {}, isDoctor: true, ...propsOverride },
        });
    }

    beforeEach(() => {
        document.body.innerHTML = '';
        globalThis.window.axios = { get: vi.fn(() => Promise.resolve({ data: { data: [] } })) };
    });

    afterEach(() => {
        delete window.Swal;
    });

    it('[GAP] 1ª tentativa bloqueada (422) mostra o aviso ANTES de emitir; confirmar reenvia com confirm_physician_mismatch e salva', async () => {
        const warning =
            'O texto deste laudo foi gerado com base nos dados de DRA. BEATRIZ NUNES. Revise antes de assinar.';
        window.axios.post = vi
            .fn()
            .mockRejectedValueOnce({ response: { status: 422, data: { physician_mismatch_warning: warning } } })
            .mockResolvedValueOnce({ data: { id: 'doc-1', title: 'x', pdf_url: '/pdf/1' } });
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true })) };

        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        await wrapper.vm.save();
        await flushPromises();

        // Precisa ter mostrado o diálogo ANTES do laudo ser salvo de verdade
        // — nunca só avisar DEPOIS (achado via revisão Codex: antes o aviso
        // só vinha na resposta de SUCESSO, com o laudo já emitido e um
        // eventual e-mail ao médico solicitante já enviado).
        expect(window.Swal.fire).toHaveBeenCalledWith(expect.objectContaining({ icon: 'warning', title: warning }));
        expect(window.axios.post).toHaveBeenCalledTimes(2);
        expect(window.axios.post.mock.calls[1][1]).toMatchObject({ confirm_physician_mismatch: true });
        expect(wrapper.vm.savedResult?.id).toBe('doc-1'); // só emitiu DEPOIS de confirmar
        wrapper.unmount();
    });

    it('[GAP] cancelar o aviso NÃO reenvia nem emite', async () => {
        const warning = 'Aviso de divergência.';
        window.axios.post = vi
            .fn()
            .mockRejectedValueOnce({ response: { status: 422, data: { physician_mismatch_warning: warning } } });
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: false })) };

        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        await wrapper.vm.save();
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledTimes(1); // nunca reenviou
        expect(wrapper.vm.savedResult).toBeNull();
        wrapper.unmount();
    });

    it('[GAP] confirmar o aviso mantém saving=true durante toda a retentativa; um segundo clique enquanto ela está em voo não reenvia nem duplica a emissão — achado na 8ª rodada de revisão adversarial Codex', async () => {
        const warning = 'Aviso de divergência.';
        const resolvers = [];
        let callCount = 0;
        window.axios.post = vi.fn(() => {
            callCount += 1;
            if (callCount === 1) {
                return Promise.reject({ response: { status: 422, data: { physician_mismatch_warning: warning } } });
            }
            return new Promise((resolve) => resolvers.push(resolve));
        });
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true })) };

        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        const savePromise = wrapper.vm.save();
        await flushPromises(); // 1ª tentativa rejeitou (422), Swal confirmou, retentativa já reenviada e em voo

        expect(window.axios.post).toHaveBeenCalledTimes(2);
        // A retentativa confirmada ainda não respondeu — saving precisa
        // continuar true. Sem o await na retentativa (bug original), o
        // finally da chamada de FORA rodava aqui mesmo, liberando o botão
        // enquanto a emissão confirmada ainda estava em voo.
        expect(wrapper.vm.saving).toBe(true);

        // Um "segundo clique" nesta janela nunca pode reenviar nem emitir
        // de novo — duplicaria o documento (e um eventual e-mail ao médico
        // solicitante).
        await wrapper.vm.save();
        expect(window.axios.post).toHaveBeenCalledTimes(2); // nenhuma chamada nova

        resolvers[0]({ data: { id: 'doc-1', title: 'x', pdf_url: '/pdf/1' } });
        await savePromise;
        await flushPromises();

        expect(wrapper.vm.saving).toBe(false); // só libera depois da retentativa terminar de verdade
        expect(wrapper.vm.savedResult?.id).toBe('doc-1');
        wrapper.unmount();
    });

    it('[GAP] resposta com image_report_incomplete_warning (2ª rodada de revisão adversarial Codex): mostra o aviso ANTES de emitir; confirmar reenvia com confirm_image_report_incomplete e salva', async () => {
        const warning =
            '1 das imagens selecionadas não pôde ser incluída no laudo (exame desabilitado, arquivo indisponível ou excluído) e será omitida. Revise antes de emitir.';
        window.axios.post = vi
            .fn()
            .mockRejectedValueOnce({ response: { status: 422, data: { image_report_incomplete_warning: warning } } })
            .mockResolvedValueOnce({ data: { id: 'doc-1', title: 'x', pdf_url: '/pdf/1' } });
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true })) };

        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        await wrapper.vm.save();
        await flushPromises();

        // Mesmo motivo do physician_mismatch_warning acima: nunca só avisar
        // DEPOIS do laudo já emitido — antes, uma imagem que não pôde ser
        // congelada simplesmente desaparecia do laudo assinado sem aviso.
        expect(window.Swal.fire).toHaveBeenCalledWith(expect.objectContaining({ icon: 'warning', title: warning }));
        expect(window.axios.post).toHaveBeenCalledTimes(2);
        expect(window.axios.post.mock.calls[1][1]).toMatchObject({ confirm_image_report_incomplete: true });
        expect(wrapper.vm.savedResult?.id).toBe('doc-1'); // só emitiu DEPOIS de confirmar
        wrapper.unmount();
    });

    it('[GAP] cancelar o aviso de imagem incompleta NÃO reenvia nem emite', async () => {
        const warning = 'Aviso de imagem incompleta.';
        window.axios.post = vi
            .fn()
            .mockRejectedValueOnce({ response: { status: 422, data: { image_report_incomplete_warning: warning } } });
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: false })) };

        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        await wrapper.vm.save();
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledTimes(1); // nunca reenviou
        expect(wrapper.vm.savedResult).toBeNull();
        wrapper.unmount();
    });
});

describe('EyeImageReportModal — médico solicitante + e-mail (Fase 5)', () => {
    const urls = {
        templates: '/t',
        preview: '/p',
        store: '/s',
        reportResend: '/_routes/eye-images.reports.resend/__ID__',
    };
    const patient = { id: 'pat-1', code: '123', name: 'Paciente Teste' };

    function mountModal(propsOverride = {}) {
        return mount(EyeImageReportModal, {
            attachTo: document.body,
            props: { open: true, patient, examIds: ['exam-1'], urls, t: {}, isDoctor: true, ...propsOverride },
        });
    }

    beforeEach(() => {
        document.body.innerHTML = '';
        globalThis.window.axios = {
            get: vi.fn(() => Promise.resolve({ data: { data: [] } })),
            post: vi.fn(() => Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } })),
        };
    });

    it('manda nome e e-mail do médico solicitante ao emitir', async () => {
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';
        wrapper.vm.form.requesting_doctor_name = 'Dr. Carlos Souza';
        wrapper.vm.form.requesting_doctor_email = 'carlos@outraclinica.com';

        await wrapper.vm.save(false);
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            urls.store,
            expect.objectContaining({
                requesting_doctor_name: 'Dr. Carlos Souza',
                requesting_doctor_email: 'carlos@outraclinica.com',
            }),
        );
        wrapper.unmount();
    });

    it('sem e-mail preenchido, manda null (nunca string vazia)', async () => {
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        await wrapper.vm.save(false);
        await flushPromises();

        const call = window.axios.post.mock.calls.find(([url]) => url === urls.store);
        expect(call[1].requesting_doctor_name).toBeNull();
        expect(call[1].requesting_doctor_email).toBeNull();
        wrapper.unmount();
    });

    it('resposta com e-mail mas sem sent_at mostra "Ainda não enviado" e botão Reenviar', async () => {
        window.axios.post = vi.fn(() =>
            Promise.resolve({
                data: {
                    id: 'doc-1',
                    title: 'x',
                    pdf_url: '/pdf/1',
                    requesting_doctor_email: 'carlos@outraclinica.com',
                    requesting_doctor_sent_at: null,
                },
            }),
        );
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        await wrapper.vm.save(false);
        await flushPromises();

        expect(document.body.textContent).toContain('Ainda não enviado');
        expect(
            Array.from(document.body.querySelectorAll('button')).some((b) => b.textContent.includes('Reenviar')),
        ).toBe(true);
        wrapper.unmount();
    });

    it('[SEGURANÇA] resposta "aguardando assinatura": mostra o aviso, NÃO mostra "Ainda não enviado" nem o botão Reenviar (o servidor recusaria o clique)', async () => {
        window.axios.post = vi.fn(() =>
            Promise.resolve({
                data: {
                    id: 'doc-1',
                    title: 'x',
                    pdf_url: '/pdf/1',
                    requesting_doctor_email: 'carlos@outraclinica.com',
                    requesting_doctor_sent_at: null,
                    requesting_doctor_awaiting_signature: true,
                },
            }),
        );
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        await wrapper.vm.save(false);
        await flushPromises();

        const notice = document.body.querySelector('[data-testid="requesting-doctor-awaiting-signature"]');
        expect(notice).not.toBeNull();
        expect(notice.textContent).toContain('o prontuário for assinado');
        expect(document.body.textContent).not.toContain('Ainda não enviado');
        expect(
            Array.from(document.body.querySelectorAll('button')).some((b) => b.textContent.includes('Reenviar')),
        ).toBe(false);
        wrapper.unmount();
    });

    it('o campo de e-mail do solicitante avisa que o envio só é liberado depois da assinatura do prontuário', async () => {
        const wrapper = mountModal();
        await flushPromises();

        const hint = document.body.querySelector('[data-testid="requesting-doctor-email-hint"]');
        expect(hint).not.toBeNull();
        expect(hint.textContent).toContain('só é liberado depois que o prontuário for assinado');
        wrapper.unmount();
    });

    it('reenviar chama o endpoint de resend com o id do documento e atualiza sent_at', async () => {
        window.axios.post = vi.fn((url) => {
            if (url === '/_routes/eye-images.reports.resend/doc-1') {
                return Promise.resolve({ data: { requesting_doctor_sent_at: '08/10/2026 21:00' } });
            }
            return Promise.resolve({
                data: {
                    id: 'doc-1',
                    title: 'x',
                    pdf_url: '/pdf/1',
                    requesting_doctor_email: 'carlos@outraclinica.com',
                    requesting_doctor_sent_at: '08/10/2026 20:00',
                },
            });
        });
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';
        await wrapper.vm.save(false);
        await flushPromises();

        await wrapper.vm.resendToRequestingDoctor();
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/_routes/eye-images.reports.resend/doc-1');
        expect(wrapper.vm.savedResult.requesting_doctor_sent_at).toBe('08/10/2026 21:00');
        wrapper.unmount();
    });

    it('[GAP] fechar e reabrir pro laudo de OUTRO paciente com um reenvio ainda em voo nunca lança nem atualiza o sent_at do laudo errado (achado via revisão Codex)', async () => {
        window.showErrorToast = vi.fn();
        const resolvers = [];
        window.axios.post = vi.fn((url) => {
            if (url === '/_routes/eye-images.reports.resend/doc-1') {
                return new Promise((resolve) => resolvers.push(resolve));
            }
            return Promise.resolve({
                data: {
                    id: 'doc-1',
                    title: 'x',
                    pdf_url: '/pdf/1',
                    requesting_doctor_email: 'carlos@outraclinica.com',
                    requesting_doctor_sent_at: '08/10/2026 20:00',
                },
            });
        });

        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true }); // abre pro paciente A (sessão 1)
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';
        await wrapper.vm.save(false);
        await flushPromises();

        const resend = wrapper.vm.resendToRequestingDoctor(); // POST em voo, nunca resolve ainda
        await flushPromises();
        expect(wrapper.vm.resending).toBe(true);

        // Fecha e reabre pra OUTRO paciente ANTES da resposta do reenvio
        // chegar — mesmo cenário real de trocar de paciente rápido na galeria.
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true, patient: { id: 'pat-2', code: '456', name: 'Paciente B' } }); // sessão 2
        await flushPromises();

        // SÓ AGORA a resposta do reenvio de doc-1 (obsoleta) chega.
        resolvers[0]({ data: { requesting_doctor_sent_at: '08/10/2026 21:00' } });
        await resend;
        await flushPromises();

        // savedResult já é null (sessão nova, ainda sem salvar nada) — a
        // resposta atrasada nunca pode lançar (antes:
        // savedResult.value.requesting_doctor_sent_at = ... quebrava com
        // savedResult null) nem reaparecer com dados do laudo de outro
        // paciente. resending volta pra false mesmo assim — senão o botão
        // "Reenviar" da sessão nova ficaria travado desabilitado pra sempre.
        expect(wrapper.vm.savedResult).toBeNull();
        expect(wrapper.vm.resending).toBe(false);
        // O e-mail FOI entregue de verdade (a resposta resolveu com
        // sucesso) — sem a correção, o acesso a savedResult.value (null)
        // lançava, caía no catch, e mostrava "Não foi possível reenviar."
        // pro paciente ERRADO mesmo o envio original tendo funcionado
        // (achado via revisão Codex).
        expect(window.showErrorToast).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('sem requesting_doctor_email na resposta, não mostra o painel de envio', async () => {
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        await wrapper.vm.save(false);
        await flushPromises();

        expect(document.body.textContent).not.toContain('Ainda não enviado');
        expect(
            Array.from(document.body.querySelectorAll('button')).some((b) => b.textContent.includes('Reenviar')),
        ).toBe(false);
        wrapper.unmount();
    });

    it('[GAP] resposta com physician_mismatch_warning mostra o aviso não-bloqueante (médico que previu ≠ quem emitiu)', async () => {
        window.axios.post = vi.fn(() =>
            Promise.resolve({
                data: {
                    id: 'doc-1',
                    title: 'x',
                    pdf_url: '/pdf/1',
                    physician_mismatch_warning:
                        'O texto deste laudo foi gerado com base nos dados de DRA. BEATRIZ NUNES. Revise antes de assinar.',
                },
            }),
        );
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        await wrapper.vm.save(false);
        await flushPromises();

        expect(document.body.querySelector('.alert-warning[role="alert"]')).not.toBeNull();
        expect(document.body.textContent).toContain('BEATRIZ NUNES');
        wrapper.unmount();
    });

    it('sem physician_mismatch_warning na resposta (fluxo direto, sem rascunho), não mostra aviso de divergência', async () => {
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        await wrapper.vm.save(false);
        await flushPromises();

        expect(document.body.querySelector('.alert-warning[role="alert"]')).toBeNull();
        wrapper.unmount();
    });

    it('rascunho pré-popula médico solicitante também', async () => {
        const draft = {
            id: 'draft-1',
            status: 'draft',
            patient: { id: 'pat-1', name: 'Paciente Teste' },
            exam_ids: ['exam-1'],
            content: '<p>Rascunho.</p>',
            requesting_doctor_name: 'Dr. Ana Lima',
            requesting_doctor_email: 'ana@outraclinica.com',
        };
        const wrapper = mountModal({ open: false, draft });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(wrapper.vm.form.requesting_doctor_name).toBe('Dr. Ana Lima');
        expect(wrapper.vm.form.requesting_doctor_email).toBe('ana@outraclinica.com');
        wrapper.unmount();
    });
});

describe('EyeImageReportModal — imagens no laudo (Fase 5 / 5b)', () => {
    const urls = { templates: '/t', preview: '/p', store: '/s' };
    const patient = { id: 'pat-1', code: '123', name: 'Paciente Teste' };
    const examImages = [
        { id: 'exam-1', url: '/img/exam-1.jpg', label: 'Pentacam — OD' },
        { id: 'exam-2', url: '/img/exam-2.jpg', label: 'Pentacam — OE' },
    ];

    function mountModal(propsOverride = {}) {
        return mount(EyeImageReportModal, {
            attachTo: document.body,
            props: { open: true, patient, examIds: ['exam-1', 'exam-2'], examImages, urls, t: {}, ...propsOverride },
        });
    }

    beforeEach(() => {
        document.body.innerHTML = '';
        globalThis.window.axios = {
            get: vi.fn(() => Promise.resolve({ data: { data: [] } })),
            post: vi.fn(() => Promise.resolve({ data: { title: 'x', pdf_url: '/pdf/1' } })),
        };
    });

    it('ligar o toggle popula com todas as imagens disponíveis', async () => {
        const wrapper = mountModal();
        await flushPromises();

        wrapper.vm.imageReport.enabled = true;
        wrapper.vm.onImageReportToggle();

        expect(wrapper.vm.imageReport.images.map((i) => i.exam_id)).toEqual(['exam-1', 'exam-2']);
    });

    it('remover uma imagem tira da lista; reordenar troca a posição', async () => {
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.imageReport.enabled = true;
        wrapper.vm.onImageReportToggle();

        wrapper.vm.moveImageReportImage(0, 1);
        expect(wrapper.vm.imageReport.images.map((i) => i.exam_id)).toEqual(['exam-2', 'exam-1']);

        wrapper.vm.removeImageReportImage(0);
        expect(wrapper.vm.imageReport.images.map((i) => i.exam_id)).toEqual(['exam-1']);
    });

    it('"Adicionar imagem" só lista o que ainda não está na lista', async () => {
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.imageReport.enabled = true;
        wrapper.vm.imageReport.images = [
            { exam_id: 'exam-1', url: examImages[0].url, label: examImages[0].label, caption: '' },
        ];

        expect(wrapper.vm.imageReportAvailableToAdd.map((i) => i.id)).toEqual(['exam-2']);

        wrapper.vm.addImageReportImage(examImages[1]);
        expect(wrapper.vm.imageReport.images.map((i) => i.exam_id)).toEqual(['exam-1', 'exam-2']);
        expect(wrapper.vm.imageReportAvailableToAdd).toHaveLength(0);
    });

    it('save() manda image_report com exam_id/caption/order (nunca url/label)', async () => {
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';
        wrapper.vm.imageReport.enabled = true;
        wrapper.vm.imageReport.columns = 4;
        wrapper.vm.imageReport.pair_od_oe = true;
        wrapper.vm.imageReport.images = [
            { exam_id: 'exam-2', url: examImages[1].url, label: examImages[1].label, caption: 'OE' },
            { exam_id: 'exam-1', url: examImages[0].url, label: examImages[0].label, caption: 'OD' },
        ];

        await wrapper.vm.save(false);
        await flushPromises();

        const call = window.axios.post.mock.calls.find(([url]) => url === urls.store);
        expect(call[1].image_report).toEqual({
            enabled: true,
            columns: 4,
            pair_od_oe: true,
            images: [
                { exam_id: 'exam-2', caption: 'OE', order: 0 },
                { exam_id: 'exam-1', caption: 'OD', order: 1 },
            ],
        });
        wrapper.unmount();
    });

    it('toggle desligado ou sem imagens manda image_report: null', async () => {
        const wrapper = mountModal();
        await flushPromises();
        wrapper.vm.form.content = '<p>Achados.</p>';

        await wrapper.vm.save(false);
        await flushPromises();

        const call = window.axios.post.mock.calls.find(([url]) => url === urls.store);
        expect(call[1].image_report).toBeNull();
        wrapper.unmount();
    });

    it('rascunho com image_report_payload rehidrata com url/label, e descarta exam_id que não existe mais', async () => {
        const draft = {
            id: 'draft-1',
            status: 'draft',
            patient,
            exam_ids: ['exam-1', 'exam-2'],
            content: '<p>Rascunho.</p>',
            image_report_payload: {
                enabled: true,
                columns: 2,
                pair_od_oe: false,
                images: [
                    { exam_id: 'exam-2', caption: 'OE', order: 0 },
                    { exam_id: 'exam-removido', caption: 'some', order: 1 },
                    { exam_id: 'exam-1', caption: 'OD', order: 2 },
                ],
            },
        };
        const wrapper = mountModal({ open: false, draft });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(wrapper.vm.imageReport.enabled).toBe(true);
        expect(wrapper.vm.imageReport.images).toEqual([
            { exam_id: 'exam-2', url: examImages[1].url, label: examImages[1].label, caption: 'OE' },
            { exam_id: 'exam-1', url: examImages[0].url, label: examImages[0].label, caption: 'OD' },
        ]);
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

    it('[GAP] desmarcar um olho DEPOIS de ligar "Imagens no laudo" tira a imagem dele da galeria embutida — achado via revisão adversarial Codex', async () => {
        const wrapper = mountModal();
        await flushPromises();

        wrapper.vm.imageReport.enabled = true;
        wrapper.vm.onImageReportToggle(); // galeria populada com e1 (OD) e e2 (OE), os 2 marcados

        expect(wrapper.vm.imageReport.images.map((i) => i.exam_id)).toEqual(['e1', 'e2']);

        // Sem o watch de selectedExamIds, e2 continuava na galeria embutida
        // mesmo depois de OE sair da seleção principal — o laudo saía com
        // uma imagem de um exame não selecionado, sem nenhum aviso.
        eyeBox('g1|2').click();
        await flushPromises();

        expect(wrapper.vm.imageReport.images.map((i) => i.exam_id)).toEqual(['e1']);
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

/**
 * Achado via revisão adversarial Codex (21ª rodada): o editor continuava
 * habilitado enquanto save() esperava a emissão. Uma correção digitada nessa
 * janela não estava no payload já enviado, mas o sucesso escondia o formulário e
 * isDirty() passava a dar false — e o servidor já tinha marcado o rascunho como
 * emitido (não aceita mais edição). A correção do médico sumia em silêncio.
 *
 * Agora tudo que entra no payload fica travado enquanto saving é true: o
 * fieldset desabilita os controles nativos (campos, seletores, botões, ordem e
 * legenda das imagens), o TinyMCE recebe :disabled, e as caixas de exames do
 * laudo conjunto também. (O stub de window.tinymce de setup.js não digita de
 * verdade — a trava é conferida no DOM e nas props, como nos demais testes.)
 */
describe('EyeImageReportModal — formulário travado durante a emissão (21ª rodada de revisão adversarial Codex)', () => {
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
    ];
    const submittedDraft = {
        id: 'draft-1',
        status: 'submitted',
        revision: 1,
        patient: { id: 'pat-1', name: 'Paciente Teste' },
        exam_ids: ['e1', 'e2'],
        content: '<p>Achado original do rascunho.</p>',
    };

    function mountModal(propsOverride = {}) {
        return mount(EyeImageReportModal, {
            attachTo: document.body,
            props: { open: true, patient, examIds: ['e1', 'e2'], urls, t: {}, examGroups, ...propsOverride },
        });
    }

    const fieldset = () => document.body.querySelector('[data-testid="report-form-fieldset"]');
    // Caixa do GRUPO de exames (sem value) — as dos olhos têm value="grupo|olho".
    const groupBox = () =>
        [...document.body.querySelectorAll('li input[type="checkbox"]')].find((el) => !el.hasAttribute('value'));
    const findButton = (text) =>
        Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent.includes(text));

    beforeEach(() => {
        document.body.innerHTML = '';
        globalThis.window.axios = {
            get: vi.fn(() => Promise.resolve({ data: { data: [] } })),
            post: vi.fn(() => Promise.resolve({ data: { id: 'doc-1', title: 'Laudo', pdf_url: '/pdf/1' } })),
        };
    });

    async function openDraft(wrapper) {
        await wrapper.setProps({ open: true, draft: submittedDraft, isDoctor: true });
        await flushPromises();
    }

    it('enquanto "Revisar e emitir" está em voo o formulário inteiro, o editor e as caixas de exame ficam travados; quando a emissão responde o formulário sai de cena', async () => {
        const resolvers = [];
        window.axios.post = vi.fn((url) =>
            url === urls.store ? new Promise((resolve) => resolvers.push(resolve)) : Promise.resolve({ data: {} }),
        );

        const wrapper = mountModal({ open: false });
        await openDraft(wrapper);

        // Parado: tudo editável.
        expect(fieldset().disabled).toBe(false);
        expect(wrapper.findComponent(TinyMceEditor).props('disabled')).toBe(false);
        expect(document.body.querySelector('input[type="checkbox"][value="g1|1"]').disabled).toBe(false);
        expect(groupBox().disabled).toBe(false);

        const savePromise = wrapper.vm.save();
        await flushPromises();

        // Em voo: o que for digitado agora NÃO estaria no payload — então nada pode ser digitado.
        expect(wrapper.vm.saving).toBe(true);
        expect(fieldset().hasAttribute('disabled')).toBe(true);
        expect(fieldset().disabled).toBe(true);
        expect(wrapper.findComponent(TinyMceEditor).props('disabled')).toBe(true);
        expect(document.body.querySelector('input[type="checkbox"][value="g1|1"]').disabled).toBe(true);
        expect(document.body.querySelector('input[type="checkbox"][value="g1|2"]').disabled).toBe(true);
        expect(groupBox().disabled).toBe(true);
        expect(findButton('Revisar e emitir').disabled).toBe(true);

        resolvers[0]({ data: { id: 'doc-1', title: 'Laudo', pdf_url: '/pdf/1' } });
        await savePromise;
        await flushPromises();

        expect(wrapper.vm.savedResult?.id).toBe('doc-1');
        expect(fieldset()).toBeNull();
        wrapper.unmount();
    });

    it('os controles de dentro do fieldset (campos de texto, data, seletor de modelo, imagens no laudo) estão sob o fieldset travado — nenhum fica de fora', async () => {
        const wrapper = mountModal({ open: false });
        await openDraft(wrapper);

        const locked = fieldset();
        const controls = [
            ...document.body.querySelectorAll('.modal-body input, .modal-body select, .modal-body textarea'),
        ];

        // Todo controle de entrada do corpo do modal é descendente do fieldset (que trava com saving)...
        expect(controls.length).toBeGreaterThan(4);
        expect(controls.every((el) => locked.contains(el))).toBe(true);
        // ...incluindo os que mexem no payload: indicação, observações, médico solicitante e modelo.
        for (const selector of ['textarea', 'select', 'input[type="email"]', 'input[type="text"]']) {
            expect(locked.querySelector(selector), selector).not.toBeNull();
        }
        wrapper.unmount();
    });

    it('se a emissão falha, o formulário volta a ser editável e o que foi digitado continua lá', async () => {
        window.axios.post = vi.fn((url) =>
            url === urls.store
                ? Promise.reject({ response: { status: 500, data: { message: 'Falha geral ao emitir.' } } })
                : Promise.resolve({ data: {} }),
        );

        const wrapper = mountModal({ open: false });
        await openDraft(wrapper);
        wrapper.vm.form.content = '<p>Achado com a correção do médico.</p>';

        await wrapper.vm.save();
        await flushPromises();

        expect(wrapper.vm.saving).toBe(false);
        expect(wrapper.vm.savedResult).toBeNull();
        expect(fieldset().disabled).toBe(false);
        expect(wrapper.findComponent(TinyMceEditor).props('disabled')).toBe(false);
        expect(wrapper.vm.form.content).toBe('<p>Achado com a correção do médico.</p>');
        expect(document.body.textContent).toContain('Falha geral ao emitir.');
        wrapper.unmount();
    });

    it('durante o aviso de confirmação (divergência de médico) o formulário continua travado; cancelar o aviso o destrava', async () => {
        let resolveConfirm;
        window.axios.post = vi.fn(() =>
            Promise.reject({
                response: { status: 422, data: { physician_mismatch_warning: 'Aviso de divergência.' } },
            }),
        );
        window.Swal = { fire: vi.fn(() => new Promise((resolve) => (resolveConfirm = resolve))) };

        const wrapper = mountModal({ open: false });
        await openDraft(wrapper);

        const savePromise = wrapper.vm.save();
        await flushPromises(); // 422 recebido, diálogo de confirmação aberto e ainda sem resposta

        expect(window.Swal.fire).toHaveBeenCalledTimes(1);
        expect(fieldset().disabled).toBe(true);
        expect(wrapper.findComponent(TinyMceEditor).props('disabled')).toBe(true);

        resolveConfirm({ isConfirmed: false }); // o médico cancela para revisar o texto
        await savePromise;
        await flushPromises();

        expect(fieldset().disabled).toBe(false);
        expect(wrapper.findComponent(TinyMceEditor).props('disabled')).toBe(false);
        delete window.Swal;
        wrapper.unmount();
    });

    it('a extração de texto do PDF escreve no conteúdo de forma assíncrona: com ela em voo o laudo não pode ser emitido (a resposta cairia depois do payload)', async () => {
        let resolveExtract;
        window.axios.post = vi.fn((url) =>
            url === '/extract'
                ? new Promise((resolve) => (resolveExtract = resolve))
                : Promise.resolve({ data: { id: 'doc-1', title: 'Laudo', pdf_url: '/pdf/1' } }),
        );

        const wrapper = mountModal({ open: false, urls: { ...urls, extractPdfText: '/extract' } });
        await openDraft(wrapper);

        findButton('Extrair texto do PDF').click();
        await flushPromises();

        expect(wrapper.vm.extractingPdf).toBe(true);
        expect(findButton('Revisar e emitir').disabled).toBe(true);

        resolveExtract({ data: { text: 'Texto extraído.' } });
        await flushPromises();

        expect(wrapper.vm.extractingPdf).toBe(false);
        expect(findButton('Revisar e emitir').disabled).toBe(false);
        wrapper.unmount();
    });
});
