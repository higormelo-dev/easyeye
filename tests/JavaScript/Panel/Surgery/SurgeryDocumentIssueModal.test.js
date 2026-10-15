import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import SurgeryDocumentIssueModal from '@/Components/Surgery/SurgeryDocumentIssueModal.vue';

/**
 * Emissão de TCLE / orientações: prévia a partir do modelo, edição, emissão
 * por axios (erro de regra volta para dentro do modal sem perder o texto) e
 * travamento dos controles enquanto o POST está no ar.
 */
const t = {
    variables: { data_cirurgia: 'Data e hora da cirurgia' },
    ui: {
        modal_title: ':type',
        reissue_title: 'Reemitir: :type',
        reissue_warning: 'O documento vigente será substituído.',
        template_label: 'Modelo',
        template_free: 'Sem modelo (texto livre)',
        template_suggested: 'sugerido',
        no_templates: 'Não há modelo ativo deste tipo.',
        title_label: 'Título do documento',
        content_label: 'Texto do documento',
        edit_hint: 'Revise o texto.',
        loading_preview: 'Montando o texto...',
        pending_variables: 'Em branco: :list.',
        unknown_variables: 'Desconhecidas: :list.',
        submit: 'Emitir documento',
        submitting: 'Emitindo...',
        cancel: 'Cancelar',
        replace_text: 'Trocar o modelo descarta o texto. Continuar?',
        replace_confirm: 'Trocar',
        submit_failed: 'Não foi possível emitir.',
        eye_label: 'Olho da cirurgia',
        eye_missing: 'O caso não tem o olho definido.',
    },
};

const issuable = {
    type: 'consent',
    label: 'Termo de consentimento (TCLE)',
    templates: [
        { id: 'tpl-geral', name: 'Geral', version: 1, suggested: false },
        { id: 'tpl-faco', name: 'Catarata', version: 3, suggested: true },
    ],
};

const urls = { preview: '/preview', store: '/store' };

let post;

function mountModal(props = {}) {
    return mount(SurgeryDocumentIssueModal, {
        attachTo: document.body,
        props: { open: false, issuable, reissue: null, urls, t, ...props },
        global: {
            stubs: {
                TinyMceEditor: {
                    props: ['modelValue', 'disabled'],
                    template:
                        '<textarea data-testid="editor" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)"></textarea>',
                },
            },
        },
    });
}

beforeEach(() => {
    document.body.innerHTML = '';
    post = vi.fn((url) =>
        Promise.resolve({
            data:
                url === '/preview'
                    ? {
                          title: 'Catarata',
                          html: '<p>Eu, MARIA</p>',
                          pending: ['data_cirurgia'],
                          unknown: [],
                          template: { id: 'tpl-faco', version: 3 },
                          basis_hash: 'a'.repeat(64),
                      }
                    : { id: 'new', message: 'Documento emitido.' },
        }),
    );
    window.axios = { post };
});

async function open(wrapper) {
    await wrapper.setProps({ open: true });
    await flushPromises();
}

describe('SurgeryDocumentIssueModal', () => {
    it('ao abrir escolhe o modelo sugerido e monta a prévia, avisando o que ficou em branco', async () => {
        const wrapper = mountModal();
        await open(wrapper);

        expect(post).toHaveBeenCalledWith('/preview', { type: 'consent', template_id: 'tpl-faco' });
        expect(document.querySelector('[data-testid="issue-title"]').value).toBe('Catarata');
        expect(document.querySelector('[data-testid="editor"]').value).toBe('<p>Eu, MARIA</p>');
        expect(document.querySelector('[data-testid="issue-pending"]').textContent).toContain(
            'Data e hora da cirurgia',
        );
    });

    it('emite o texto editado, avisa o pai e fecha', async () => {
        const wrapper = mountModal();
        await open(wrapper);

        const editor = document.querySelector('[data-testid="editor"]');
        editor.value = '<p>Texto ajustado pelo médico</p>';
        editor.dispatchEvent(new Event('input'));
        await flushPromises();

        document.querySelector('[data-testid="issue-submit"]').click();
        await flushPromises();

        // Leva o retrato do pedido da prévia: se o pedido mudou desde então o servidor recusa e a prévia se refaz.
        expect(post).toHaveBeenLastCalledWith('/store', {
            type: 'consent',
            template_id: 'tpl-faco',
            title: 'Catarata',
            content: '<p>Texto ajustado pelo médico</p>',
            basis_hash: 'a'.repeat(64),
        });
        expect(wrapper.emitted('issued')[0][0].id).toBe('new');
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('erro de regra (422) fica no modal e o texto editado não se perde', async () => {
        const wrapper = mountModal();
        await open(wrapper);

        post.mockImplementationOnce(() =>
            Promise.reject({
                response: { status: 422, data: { message: 'Variáveis desconhecidas: {{cpf}}.', errors: {} } },
            }),
        );

        document.querySelector('[data-testid="issue-submit"]').click();
        await flushPromises();

        expect(document.querySelector('[data-testid="issue-error"]').textContent).toContain('{{cpf}}');
        expect(document.querySelector('[data-testid="editor"]').value).toBe('<p>Eu, MARIA</p>');
        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('trava todo controle do payload enquanto o POST está no ar (fieldset + editor)', async () => {
        const wrapper = mountModal();
        await open(wrapper);

        let release;
        post.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));

        document.querySelector('[data-testid="issue-submit"]').click();
        await flushPromises();

        expect(document.querySelector('[data-testid="issue-fieldset"]').disabled).toBe(true);
        expect(document.querySelector('[data-testid="editor"]').disabled).toBe(true);
        expect(document.querySelector('[data-testid="issue-submit"]').disabled).toBe(true);

        release({ data: { id: 'x' } });
        await flushPromises();
        expect(wrapper.emitted('issued')).toHaveLength(1);
    });

    it('variável desconhecida da prévia aparece como alerta', async () => {
        post.mockImplementation(() =>
            Promise.resolve({
                data: { title: 'T', html: '<p>{{foo}}</p>', pending: [], unknown: ['foo'], template: null },
            }),
        );
        const wrapper = mountModal();
        await open(wrapper);

        expect(document.querySelector('[data-testid="issue-unknown"]').textContent).toContain('{{foo}}');
    });

    it('sem modelo ativo avisa e deixa escrever texto livre', async () => {
        const wrapper = mountModal({ issuable: { ...issuable, templates: [] } });
        await open(wrapper);

        expect(document.querySelector('[data-testid="issue-no-templates"]')).not.toBeNull();
        expect(post).toHaveBeenCalledWith('/preview', { type: 'consent', template_id: null });
    });

    it('reemissão parte do texto já emitido, sem consultar modelo, e avisa da substituição', async () => {
        const wrapper = mountModal({
            reissue: { type_label: 'TCLE', title: 'TCLE emitido', content: '<p>Texto emitido</p>' },
        });
        await open(wrapper);

        expect(post).not.toHaveBeenCalled();
        expect(document.querySelector('[data-testid="issue-title"]').value).toBe('TCLE emitido');
        expect(document.querySelector('[data-testid="editor"]').value).toBe('<p>Texto emitido</p>');
        expect(document.body.textContent).toContain('O documento vigente será substituído.');
        expect(document.querySelector('[data-testid="issue-template"]')).toBeNull();

        // Sem prévia não há retrato do pedido para conferir.
        document.querySelector('[data-testid="issue-submit"]').click();
        await flushPromises();

        expect(post.mock.calls.at(-1)[1].basis_hash).toBeUndefined();
    });

    it('mostra o olho do caso com a sigla e avisa quando a prévia vem sem olho', async () => {
        post.mockImplementation(() =>
            Promise.resolve({
                data: {
                    title: 'T',
                    html: '<p>x</p>',
                    pending: [],
                    unknown: [],
                    eye: { value: 'oe', short: 'OS', label: 'Left eye (OS)' },
                    template: null,
                },
            }),
        );
        const wrapper = mountModal();
        await open(wrapper);

        expect(document.querySelector('[data-testid="issue-eye"]').textContent).toContain('OS');
        expect(document.querySelector('[data-testid="issue-eye-missing"]')).toBeNull();

        post.mockImplementation(() =>
            Promise.resolve({
                data: { title: 'T', html: '<p>x</p>', pending: ['olho'], unknown: [], eye: null, template: null },
            }),
        );
        await wrapper.setProps({ open: false });
        await open(wrapper);

        expect(document.querySelector('[data-testid="issue-eye-missing"]').textContent).toContain('olho definido');
    });
});
