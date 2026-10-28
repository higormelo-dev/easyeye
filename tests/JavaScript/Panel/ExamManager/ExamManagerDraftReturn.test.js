import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ExamManagerReportModal from '@/Pages/Panel/ExamManager/ExamManagerReportModal.vue';
import ExamManagerReportDraftsModal from '@/Pages/Panel/ExamManager/ExamManagerReportDraftsModal.vue';
import TinyMceEditor from '@/Components/Panel/TinyMceEditor.vue';

/**
 * Fluxo técnico → médico (Fase 5): o médico devolve o rascunho enviado com um
 * motivo; o técnico vê o motivo, corrige e reenvia. Cobre a tela do laudo
 * (botão do médico, aviso e rótulo do técnico) e a fila de rascunhos.
 */
const urls = {
    templates: '/t',
    preview: '/p',
    store: '/s',
    draftStore: '/_routes/exam-manager.report-drafts.store',
    draftUpdate: '/_routes/exam-manager.report-drafts.update/__ID__',
    draftSubmit: '/_routes/exam-manager.report-drafts.submit/__ID__',
    draftReturn: '/_routes/exam-manager.report-drafts.return/__ID__',
    draftDestroy: '/_routes/exam-manager.report-drafts.destroy/__ID__',
};
const patient = { id: 'pat-1', code: '123', name: 'Paciente Teste' };
const submittedDraft = { id: 'draft-1', status: 'submitted', revision: 4, content: '<p>Achados.</p>' };
const returnedDraft = {
    id: 'draft-1',
    status: 'returned',
    revision: 5,
    content: '<p>Achados.</p>',
    return_reason: 'Falta a imagem do OE.',
};

function mountModal(propsOverride = {}) {
    return mount(ExamManagerReportModal, {
        attachTo: document.body,
        props: { open: false, patient, examIds: ['exam-1'], urls, t: {}, ...propsOverride },
    });
}

async function openWith(propsOverride) {
    const wrapper = mountModal(propsOverride);
    await wrapper.setProps({ open: true });
    await flushPromises();
    return wrapper;
}

const byTestId = (id) => document.body.querySelector(`[data-testid="${id}"]`);
const findButton = (text) =>
    Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent.includes(text));

beforeEach(() => {
    document.body.innerHTML = '';
    delete window.Swal;
    globalThis.window.axios = {
        get: vi.fn(() => Promise.resolve({ data: { data: [] } })),
        post: vi.fn(() => Promise.resolve({ data: {} })),
        put: vi.fn(() => Promise.resolve({ data: {} })),
        delete: vi.fn(() => Promise.resolve({})),
    };
});

describe('ExamManagerReportModal — devolver ao técnico / reenviar (Fase 5)', () => {
    it('médico vê "Devolver ao técnico" só num rascunho ENVIADO (não num rascunho novo nem sem rascunho)', async () => {
        let wrapper = await openWith({ isDoctor: true, draft: submittedDraft });
        expect(byTestId('draft-return-button')).not.toBeNull();
        wrapper.unmount();

        document.body.innerHTML = '';
        wrapper = await openWith({ isDoctor: true, draft: { ...submittedDraft, status: 'draft' } });
        expect(byTestId('draft-return-button')).toBeNull();
        wrapper.unmount();

        document.body.innerHTML = '';
        wrapper = await openWith({ isDoctor: true });
        expect(byTestId('draft-return-button')).toBeNull();
        wrapper.unmount();
    });

    it('quem prepara nunca vê "Devolver ao técnico"', async () => {
        const wrapper = await openWith({ isDoctor: false, draft: submittedDraft });

        expect(byTestId('draft-return-button')).toBeNull();
        wrapper.unmount();
    });

    it('devolver pede o motivo, manda motivo + revisão e avisa o pai antes de fechar', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true, value: '  Falta o OE.  ' })) };
        const wrapper = await openWith({ isDoctor: true, draft: submittedDraft });

        await wrapper.vm.returnDraft();
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/_routes/exam-manager.report-drafts.return/draft-1', {
            draft_revision: 4,
            reason: 'Falta o OE.',
        });
        expect(wrapper.emitted('draft-returned')).toEqual([[{ id: 'draft-1' }]]);
        expect(wrapper.emitted('close')).toBeTruthy();
        wrapper.unmount();
    });

    it('o diálogo exige motivo preenchido (validator recusa vazio)', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: false })) };
        const wrapper = await openWith({ isDoctor: true, draft: submittedDraft });

        await wrapper.vm.returnDraft();

        const options = window.Swal.fire.mock.calls[0][0];
        expect(options.input).toBe('textarea');
        expect(options.inputValidator('')).toBeTruthy();
        expect(options.inputValidator('   ')).toBeTruthy();
        expect(options.inputValidator('Falta o OE.')).toBeUndefined();
        wrapper.unmount();
    });

    it('cancelar o diálogo não devolve nada', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: false })) };
        const wrapper = await openWith({ isDoctor: true, draft: submittedDraft });

        await wrapper.vm.returnDraft();
        await flushPromises();

        expect(window.axios.post).not.toHaveBeenCalled();
        expect(wrapper.emitted('draft-returned')).toBeFalsy();
        wrapper.unmount();
    });

    it('com alterações não salvas, o diálogo avisa que elas não vão ao técnico', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: false })) };
        const wrapper = await openWith({ isDoctor: true, draft: submittedDraft });
        wrapper.vm.form.content = '<p>Editado só nesta tela.</p>';

        await wrapper.vm.returnDraft();

        expect(window.Swal.fire.mock.calls[0][0].text).toContain('não serão enviadas');
        wrapper.unmount();
    });

    it('409 (o técnico salvou uma correção no meio): mostra o erro, não fecha e não avisa o pai', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true, value: 'Falta o OE.' })) };
        window.axios.post = vi.fn(() =>
            Promise.reject({ response: { status: 409, data: { message: 'Rascunho alterado por outra pessoa.' } } }),
        );
        const wrapper = await openWith({ isDoctor: true, draft: submittedDraft });

        await wrapper.vm.returnDraft();
        await flushPromises();

        expect(wrapper.vm.error).toContain('alterado por outra pessoa');
        expect(wrapper.emitted('draft-returned')).toBeFalsy();
        expect(wrapper.emitted('close')).toBeFalsy();
        expect(wrapper.vm.returningDraft).toBe(false);
        wrapper.unmount();
    });

    it('[F3] durante o POST de devolução, congela o formulário e ignora texto ditado tardio', async () => {
        let resolveReturn;
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true, value: 'Falta o OE.' })) };
        window.axios.post = vi.fn(
            () =>
                new Promise((resolve) => {
                    resolveReturn = resolve;
                }),
        );
        const wrapper = await openWith({ isDoctor: true, draft: submittedDraft });
        const insert = vi.fn();
        wrapper.vm.editorRef = { insertContent: insert };

        const returning = wrapper.vm.returnDraft();
        await vi.waitFor(() => expect(wrapper.vm.returningDraft).toBe(true));
        await flushPromises();

        expect(document.body.querySelector('[data-testid="report-form-fieldset"]')?.hasAttribute('disabled')).toBe(
            true,
        );
        expect(wrapper.findComponent(TinyMceEditor).props('disabled')).toBe(true);
        expect(byTestId('draft-return-button').disabled).toBe(true);
        wrapper.vm.onDictated('texto tardio');
        expect(insert).not.toHaveBeenCalled();

        resolveReturn({ data: {} });
        await returning;
        wrapper.unmount();
    });

    it('[F3] bloqueia X, Escape e backdrop até o POST de devolução terminar', async () => {
        let resolveReturn;
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true, value: 'Falta o OE.' })) };
        window.axios.post = vi.fn(
            () =>
                new Promise((resolve) => {
                    resolveReturn = resolve;
                }),
        );
        const wrapper = await openWith({ isDoctor: true, draft: submittedDraft });

        const returning = wrapper.vm.returnDraft();
        await vi.waitFor(() => expect(wrapper.vm.returningDraft).toBe(true));

        expect(byTestId('report-close-button').disabled).toBe(true);
        byTestId('report-close-button').click();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        byTestId('eye-image-report-modal').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();

        expect(wrapper.emitted('close')).toBeFalsy();
        resolveReturn({ data: {} });
        await returning;
        expect(wrapper.emitted('close')).toHaveLength(1);
        wrapper.unmount();
    });

    it('[F3] não permite devolver enquanto o ditado ainda está em andamento', async () => {
        const wrapper = await openWith({ isDoctor: true, draft: submittedDraft, dictation: { available: true } });
        wrapper.vm.dictationBusy = true;
        await flushPromises();

        expect(byTestId('draft-return-button').disabled).toBe(true);
        await wrapper.vm.returnDraft();
        expect(window.axios.post).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('[F4] reabrir outro rascunho enquanto a devolução responde restaura o estado da nova sessão', async () => {
        let resolveReturn;
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true, value: 'Falta o OE.' })) };
        window.axios.post = vi.fn(
            () =>
                new Promise((resolve) => {
                    resolveReturn = resolve;
                }),
        );
        const wrapper = await openWith({ isDoctor: true, draft: submittedDraft });

        const returning = wrapper.vm.returnDraft();
        await vi.waitFor(() => expect(wrapper.vm.returningDraft).toBe(true));
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true, draft: { ...submittedDraft, id: 'draft-2', revision: 8 } });
        await flushPromises();

        expect(wrapper.vm.returningDraft).toBe(false);
        resolveReturn({ data: {} });
        await returning;
        expect(wrapper.vm.returningDraft).toBe(false);
        wrapper.unmount();
    });

    it('[F4] não envia a devolução depois de fechar e reabrir outro rascunho durante o diálogo', async () => {
        let resolveDialog;
        window.Swal = {
            fire: vi.fn(
                () =>
                    new Promise((resolve) => {
                        resolveDialog = resolve;
                    }),
            ),
        };
        const wrapper = await openWith({ isDoctor: true, draft: submittedDraft });

        const returning = wrapper.vm.returnDraft();
        await vi.waitFor(() => expect(window.Swal.fire).toHaveBeenCalled());
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true, draft: { ...submittedDraft, id: 'draft-2', revision: 8 } });
        await flushPromises();

        resolveDialog({ isConfirmed: true, value: 'Falta o OE.' });
        await returning;

        expect(window.axios.post).not.toHaveBeenCalled();
        expect(wrapper.vm.returningDraft).toBe(false);
        expect(wrapper.emitted('draft-returned')).toBeFalsy();
        wrapper.unmount();
    });

    it('técnico vê o motivo da devolução no topo do rascunho devolvido, e o botão vira "Reenviar"', async () => {
        const wrapper = await openWith({ isDoctor: false, draft: returnedDraft });

        expect(byTestId('draft-return-banner').textContent).toContain('Falta a imagem do OE.');
        expect(findButton('Reenviar para o médico')).toBeDefined();
        expect(findButton('Enviar para o médico')).toBeUndefined();
        expect(wrapper.vm.draftIsEditable).toBe(true);
        wrapper.unmount();
    });

    it('rascunho que não foi devolvido não mostra o aviso e mantém "Enviar para o médico"', async () => {
        const wrapper = await openWith({ isDoctor: false, draft: { ...submittedDraft, status: 'draft' } });

        expect(byTestId('draft-return-banner')).toBeNull();
        expect(findButton('Enviar para o médico')).toBeDefined();
        wrapper.unmount();
    });

    it('salvar o devolvido mantém o motivo; reenviar tira o aviso e conta como reenvio de devolvido', async () => {
        window.axios.put = vi.fn(() =>
            Promise.resolve({ data: { status: 'returned', revision: 6, return_reason: 'Falta a imagem do OE.' } }),
        );
        window.axios.post = vi.fn(() => Promise.resolve({ data: { status: 'submitted', revision: 7 } }));
        const wrapper = await openWith({ isDoctor: false, draft: returnedDraft });
        wrapper.vm.form.content = '<p>Achados com o OE.</p>';

        await wrapper.vm.saveDraft(false);
        await flushPromises();
        expect(wrapper.vm.draftStatus).toBe('returned');
        expect(byTestId('draft-return-banner')).not.toBeNull();

        await wrapper.vm.saveDraft(true);
        await flushPromises();

        expect(wrapper.vm.draftStatus).toBe('submitted');
        expect(byTestId('draft-return-banner')).toBeNull();
        const savedEvents = wrapper.emitted('draft-saved');
        expect(savedEvents.at(-1)[0]).toMatchObject({ submitted: true, wasReturned: true }); // o reenvio parte de um devolvido
        wrapper.unmount();
    });
});

describe('ExamManagerReportDraftsModal — fila com devolvidos e "só os meus" (Fase 5)', () => {
    const draftsUrls = {
        index: '/_routes/exam-manager.report-drafts.index',
        destroy: '/_routes/exam-manager.report-drafts.destroy/__ID__',
    };

    async function openList(propsOverride = {}, rows = []) {
        globalThis.window.axios = {
            get: vi.fn(() => Promise.resolve({ data: { data: rows, meta: { current_page: 1, has_more: false } } })),
            delete: vi.fn(),
        };
        const wrapper = mount(ExamManagerReportDraftsModal, {
            attachTo: document.body,
            props: { open: false, urls: draftsUrls, t: {}, ...propsOverride },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        return wrapper;
    }

    it('oferece o filtro "Devolvido ao técnico" e trata devolvido como editável', async () => {
        const wrapper = await openList({}, [
            {
                id: 'd-1',
                status: 'returned',
                status_label: 'Devolvido ao técnico',
                patient: { name: 'Ana' },
                return_reason: 'Falta a imagem do OE.',
                resolved_doctor_name: 'Dra. Bia',
            },
        ]);

        expect(wrapper.vm.statusOptions.map((o) => o.value)).toContain('returned');
        // devolvido ainda abre e descarta (editável)
        expect(document.body.querySelectorAll('[data-testid="draft-row"] button')).toHaveLength(2);
        expect(document.body.querySelector('[data-testid="draft-row-return-reason"]').textContent).toContain(
            'Falta a imagem do OE.',
        );
        expect(document.body.textContent).toContain('Para Dra. Bia');
        wrapper.unmount();
    });

    it('o motivo só aparece em rascunho devolvido', async () => {
        const wrapper = await openList({}, [
            { id: 'd-1', status: 'submitted', status_label: 'Enviado', patient: { name: 'Ana' }, return_reason: 'x' },
        ]);

        expect(document.body.querySelector('[data-testid="draft-row-return-reason"]')).toBeNull();
        wrapper.unmount();
    });

    it('"só os meus" manda mine=1 e refaz a busca; desligado não manda o parâmetro', async () => {
        const wrapper = await openList({ isDoctor: true });
        const firstParams = window.axios.get.mock.calls.at(-1)[1].params;
        expect(firstParams).toEqual({ status: 'submitted', page: 1 });

        wrapper.vm.onlyMine = true;
        await flushPromises();
        expect(window.axios.get.mock.calls.at(-1)[1].params).toEqual({ status: 'submitted', mine: 1, page: 1 });

        wrapper.vm.onlyMine = false;
        await flushPromises();
        expect(window.axios.get.mock.calls.at(-1)[1].params).not.toHaveProperty('mine');
        wrapper.unmount();
    });

    it('reabrir o modal volta o "só os meus" para desligado', async () => {
        const wrapper = await openList({ isDoctor: true });
        wrapper.vm.onlyMine = true;
        await flushPromises();

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(wrapper.vm.onlyMine).toBe(false);
        wrapper.unmount();
    });

    it('o rótulo do "só os meus" muda com o perfil', async () => {
        const doctor = await openList({ isDoctor: true });
        expect(document.body.textContent).toContain('Só os meus');
        doctor.unmount();

        document.body.innerHTML = '';
        const preparer = await openList({ isDoctor: false });
        expect(document.body.textContent).toContain('Só os que preparei');
        preparer.unmount();
    });
});
