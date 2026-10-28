import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Swal from 'sweetalert2';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import PdfPreviewModal from '@/Pages/Panel/MedicalRecords/Components/PdfPreviewModal.vue';
import ExamManagerReportDraftsModal from '@/Pages/Panel/ExamManager/ExamManagerReportDraftsModal.vue';

/**
 * Overlays abertos POR CIMA de um diálogo (CenteredModal/OffcanvasPanel):
 * revisão adversarial Codex — o trap de foco do useModalA11y puxava de volta
 * pro diálogo QUALQUER foco que caísse fora dele (só o editor TinyMCE era
 * exceção). O SweetAlert de "assinar prontuário"/"descartar rascunho"
 * (anexado direto no <body>, fora do diálogo, e nunca na pilha) perdia o foco
 * no mesmo instante em que abria: quem usa teclado não alcançava os botões da
 * confirmação. Mesma causa em qualquer overlay de fora — PdfPreviewModal
 * (teleportado) e o menu do ActionDropdown (teleportado) incluídos.
 *
 * SweetAlert2 REAL aqui (não mock): o bug nasce da ordem exata de eventos —
 * ele foca o botão de confirmar de forma síncrona dentro do fire().
 */
const settle = () => new Promise((resolve) => setTimeout(resolve));

function pressKey(key, target = window) {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
    target.dispatchEvent(event);

    return event;
}

const wrappers = [];

async function mountDialog(Component, slots = {}, props = {}) {
    const wrapper = mount(Component, {
        attachTo: document.body,
        props: { open: false, ...props },
        slots: { header: '<h5>Diálogo</h5>', ...slots },
    });
    wrappers.push(wrapper);
    await wrapper.setProps({ open: true });
    await settle();

    return wrapper;
}

const insideSweetAlert = () => document.activeElement?.closest('.swal2-container') !== null;

beforeEach(() => {
    document.body.innerHTML = '';
});

afterEach(() => {
    Swal.close();
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
    document.body.className = '';
});

describe('SweetAlert2 por cima do diálogo (confirmar assinatura, descartar rascunho, aviso de sessão)', () => {
    // O SweetAlert2 devolve o foco 100 ms DEPOIS de fechar e guarda o "elemento
    // anterior" em estado global: um timer pendente de um teste caía no teste
    // seguinte e roubava o foco do botão novo. Deixa ele terminar antes do próximo.
    afterEach(async () => {
        Swal.close();
        await new Promise((resolve) => setTimeout(resolve, 150));
    });

    it.each([
        ['OffcanvasPanel', OffcanvasPanel],
        ['CenteredModal', CenteredModal],
    ])('%s: a confirmação abre com o foco nela — o trap não puxa de volta pro diálogo', async (_name, Component) => {
        await mountDialog(Component, { default: '<button id="sign">Assinar</button>' });
        document.getElementById('sign').focus();

        const answer = Swal.fire({ title: 'Assinar o prontuário?', showCancelButton: true });
        await settle();

        expect(insideSweetAlert()).toBe(true);

        Swal.clickCancel();
        await answer;
    });

    it('Escape dentro da confirmação fecha só a confirmação — o diálogo de baixo continua aberto', async () => {
        const wrapper = await mountDialog(OffcanvasPanel, { default: '<button id="sign">Assinar</button>' });
        document.getElementById('sign').focus();

        const answer = Swal.fire({ title: 'Assinar o prontuário?', showCancelButton: true });
        await settle();

        pressKey('Escape', document.activeElement);
        const result = await answer;

        expect(result.isDismissed).toBe(true);
        expect(wrapper.emitted('close')).toBeFalsy();
    });

    it('fechada a confirmação, o foco volta a quem a abriu (dentro do diálogo)', async () => {
        await mountDialog(OffcanvasPanel, { default: '<button id="sign">Assinar</button>' });
        const opener = document.getElementById('sign');
        opener.focus();

        const answer = Swal.fire({ title: 'Assinar?', showCancelButton: true });
        await settle();
        Swal.clickCancel();
        await answer;
        // O SweetAlert2 devolve o foco 100 ms depois de fechar (returnFocus).
        await new Promise((resolve) => setTimeout(resolve, 200));

        expect(document.activeElement).toBe(opener);
    });

    it('alerta que não pode ser dispensado (aviso de sessão expirando): Escape com o foco perdido não fecha o diálogo por trás', async () => {
        const wrapper = await mountDialog(OffcanvasPanel);

        const answer = Swal.fire({ title: 'Sessão expirando', allowEscapeKey: false, allowOutsideClick: false });
        await settle();

        // Clicou no fundo do alerta: o foco foi pro <body>, onde o teclado do
        // SweetAlert2 (preso ao popup) já não escuta.
        document.body.focus();
        document.activeElement?.blur?.();
        pressKey('Escape');

        expect(wrapper.emitted('close')).toBeFalsy();

        Swal.close();
        await answer;
    });

    it('toast não é modal: não suspende o Escape do diálogo', async () => {
        const wrapper = await mountDialog(OffcanvasPanel);

        Swal.fire({ toast: true, position: 'top-end', title: 'Salvo', showConfirmButton: false });
        await settle();
        pressKey('Escape');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('descartar rascunho (ExamManagerReportDraftsModal com o SweetAlert real): a confirmação segura o foco e o DELETE só sai depois de confirmar', async () => {
        globalThis.window.Swal = Swal;
        globalThis.window.axios = {
            get: vi.fn(() =>
                Promise.resolve({
                    data: {
                        data: [
                            {
                                id: 'd-1',
                                status: 'draft',
                                status_label: 'Rascunho',
                                revision: 4,
                                patient: { name: 'Ana' },
                            },
                        ],
                    },
                }),
            ),
            delete: vi.fn(() => Promise.resolve({ data: {} })),
        };

        const wrapper = mount(ExamManagerReportDraftsModal, {
            attachTo: document.body,
            props: { open: false, urls: { index: '/drafts', destroy: '/drafts/__ID__' }, t: {} },
        });
        wrappers.push(wrapper);
        await wrapper.setProps({ open: true });
        await flushPromises();

        const discard = document.querySelector('[data-testid="draft-row"] .btn-outline-danger');
        discard.focus();
        discard.click();
        await settle();

        expect(insideSweetAlert()).toBe(true);
        expect(window.axios.delete).not.toHaveBeenCalled();

        Swal.clickConfirm();
        await settle();
        await flushPromises();

        expect(window.axios.delete).toHaveBeenCalledWith('/drafts/d-1', { data: { draft_revision: 4 } });
        delete globalThis.window.Swal;
    });
});

describe('menu do ActionDropdown (teleportado pro <body>) dentro do diálogo', () => {
    const Host = {
        components: { ActionDropdown },
        template: `
            <div>
                <ActionDropdown title="Ações">
                    <li><button id="item-edit" type="button" class="dropdown-item">Editar</button></li>
                    <li><button id="item-del" type="button" class="dropdown-item">Excluir</button></li>
                </ActionDropdown>
            </div>`,
    };

    const trigger = () => document.querySelector('button[aria-haspopup="menu"]');

    it('aberto pelo teclado, o foco vai pro 1º item e fica lá (antes o trap devolvia pro diálogo)', async () => {
        await mountDialog(CenteredModal, { default: Host });

        trigger().focus();
        pressKey('ArrowDown', trigger());
        await settle();

        expect(document.activeElement?.id).toBe('item-edit');
    });

    it('ArrowDown no menu segue navegando entre os itens', async () => {
        await mountDialog(CenteredModal, { default: Host });

        trigger().focus();
        pressKey('ArrowDown', trigger());
        await settle();
        pressKey('ArrowDown', document.activeElement);
        await settle();

        expect(document.activeElement?.id).toBe('item-del');
    });

    it('Escape no menu aberto pelo teclado fecha só o menu — o diálogo não fecha junto', async () => {
        const wrapper = await mountDialog(CenteredModal, { default: Host });

        trigger().focus();
        pressKey('ArrowDown', trigger());
        await settle();
        pressKey('Escape', document.activeElement);
        await settle();

        expect(trigger().getAttribute('aria-expanded')).toBe('false');
        expect(wrapper.emitted('close')).toBeFalsy();
        expect(document.activeElement).toBe(trigger());
    });

    it('menu aberto com o mouse (foco segue no botão, dentro do diálogo): o 1º Escape fecha só o menu, o 2º fecha o diálogo', async () => {
        const wrapper = await mountDialog(CenteredModal, { default: Host });

        trigger().focus();
        trigger().dispatchEvent(new MouseEvent('click', { bubbles: true, detail: 1 }));
        await settle();
        expect(trigger().getAttribute('aria-expanded')).toBe('true');

        pressKey('Escape', trigger());
        await settle();

        expect(trigger().getAttribute('aria-expanded')).toBe('false');
        expect(wrapper.emitted('close')).toBeFalsy();

        pressKey('Escape', trigger());
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});

describe('PdfPreviewModal por cima de um diálogo (entra na pilha como qualquer diálogo)', () => {
    async function mountPreview() {
        const preview = mount(PdfPreviewModal, {
            attachTo: document.body,
            props: { url: 'about:blank', title: 'Receita' },
        });
        wrappers.push(preview);
        await settle();

        return preview;
    }

    it('o foco entra na pré-visualização e o trap do diálogo de baixo não o puxa de volta', async () => {
        await mountDialog(OffcanvasPanel, { default: '<button id="open-pdf">Ver PDF</button>' });
        document.getElementById('open-pdf').focus();

        await mountPreview();

        const previewDialog = document.querySelector('.modal-content');
        expect(previewDialog.contains(document.activeElement)).toBe(true);

        // O clique em "Baixar"/"Nova aba" também não é revertido.
        const download = previewDialog.querySelector('a[download]');
        download.focus();
        expect(document.activeElement).toBe(download);
    });

    it('Escape fecha só a pré-visualização; fechada, o Escape volta pro diálogo de baixo', async () => {
        const drawer = await mountDialog(OffcanvasPanel, { default: '<button id="open-pdf">Ver PDF</button>' });
        document.getElementById('open-pdf').focus();

        const preview = await mountPreview();

        pressKey('Escape');
        expect(preview.emitted('close')).toHaveLength(1);
        expect(drawer.emitted('close')).toBeFalsy();

        preview.unmount();
        wrappers.splice(wrappers.indexOf(preview), 1);
        await settle();

        pressKey('Escape');
        expect(drawer.emitted('close')).toHaveLength(1);
    });

    it('fechada a pré-visualização, o foco volta ao botão que a abriu', async () => {
        await mountDialog(OffcanvasPanel, { default: '<button id="open-pdf">Ver PDF</button>' });
        const opener = document.getElementById('open-pdf');
        opener.focus();

        const preview = await mountPreview();
        preview.unmount();
        wrappers.splice(wrappers.indexOf(preview), 1);
        await settle();

        expect(document.activeElement).toBe(opener);
    });

    it('é um diálogo de verdade: role, aria-modal e nome pelo título', async () => {
        await mountPreview();

        const dialog = document.querySelector('[role="dialog"]');
        const title = document.getElementById(dialog.getAttribute('aria-labelledby'));

        expect(dialog.getAttribute('aria-modal')).toBe('true');
        expect(title.textContent).toContain('Receita');
    });
});

describe('o trap continua protegendo o que importa', () => {
    it('foco que escapa pro conteúdo do fundo da página (#app) é puxado de volta pro diálogo', async () => {
        const app = document.createElement('div');
        app.id = 'app';
        app.innerHTML = '<input id="background-field" />';
        document.body.appendChild(app);

        await mountDialog(CenteredModal, { default: '<input id="inside-field" />' });

        // Navegador sem suporte a inert: o foco chega no fundo. O evento é
        // disparado na mão (focar um elemento inert nem dispara focusin onde
        // há suporte) com o foco solto no <body>, pra provar que quem trouxe
        // o foco de volta foi o trap.
        document.activeElement.blur();
        expect(document.activeElement).toBe(document.body);
        document.getElementById('background-field').dispatchEvent(new FocusEvent('focusin', { bubbles: true }));

        expect(document.querySelector('.ee-modal__dialog').contains(document.activeElement)).toBe(true);
    });

    it('foco que escapa pra um diálogo de BAIXO da pilha é puxado de volta pro de cima', async () => {
        await mountDialog(CenteredModal, { default: '<input id="bottom-field" />' });
        const top = await mountDialog(OffcanvasPanel, { default: '<input id="top-field" />' });

        document.getElementById('bottom-field').focus();

        expect(
            top.element.ownerDocument.querySelectorAll('.ee-modal__dialog')[1].contains(document.activeElement),
        ).toBe(true);
    });

    it('Escape disparado de dentro de um overlay de fora do diálogo não fecha o diálogo', async () => {
        const wrapper = await mountDialog(CenteredModal);

        const foreign = document.createElement('div');
        foreign.innerHTML = '<button id="foreign-btn">Outro overlay</button>';
        document.body.appendChild(foreign);

        pressKey('Escape', document.getElementById('foreign-btn'));

        expect(wrapper.emitted('close')).toBeFalsy();
    });
});
