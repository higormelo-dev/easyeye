import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import MedicalRecordDetailDrawer from '@/Pages/Panel/MedicalRecords/MedicalRecordDetailDrawer.vue';

/**
 * Gaveta do prontuário (OffcanvasPanel) com a confirmação de assinatura
 * (SweetAlert2) e a pré-visualização do PDF (PdfPreviewModal) por cima —
 * revisão adversarial Codex: o trap de foco da gaveta puxava o foco de volta
 * pra dentro dela no instante em que a confirmação abria, e quem usa teclado
 * não alcançava "Assinar"/"Cancelar". Componentes REAIS e SweetAlert2 real,
 * sem stub do OffcanvasPanel: o bug é a interação entre eles.
 *
 * Mecanismo geral (ActionDropdown, TinyMCE, diálogos empilhados) em
 * tests/JavaScript/Components/ModalNestedOverlays.test.js.
 */
const detail = {
    code: 'PR-1',
    created_at_formatted: '01/09/2026 10:00',
    doctor_name: 'Dr. Smith',
    diagnosis_cids: [],
    is_signed: false,
    is_locked: false,
    sign_url: '/panel/patients/p1/medicalrecords/r1/sign',
    clinical_revision: 3,
    documentations: [
        {
            id: 'd1',
            type_label: 'Laudo',
            title: 'Laudo 1',
            doctor_name: 'Dr. Smith',
            created_at: '01/09/2026 10:30',
            is_ai: false,
            ai_workflow_label: null,
            pdf_url: 'about:blank',
            shared_with_patient: false,
            document_share_id: null,
        },
    ],
};

const settle = () => new Promise((resolve) => setTimeout(resolve));

function pressKey(key, target = window) {
    target.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
}

let wrapper;

async function mountDrawer() {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({ ok: true, json: async () => detail })),
    );
    globalThis.window.Swal = Swal;

    wrapper = mount(MedicalRecordDetailDrawer, {
        attachTo: document.body,
        props: {
            open: false,
            record: { show_url: '/show', pdf_url: '/pdf', edit_url: '/edit' },
            patient: { id: 'p1' },
            canSign: true,
            t: {},
        },
        global: {
            stubs: {
                ShareWithPatientToggle: true,
                ActionIconButton: {
                    emits: ['click'],
                    template: '<button type="button" class="doc-view" @click="$emit(\'click\')">Ver</button>',
                },
            },
        },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
    await settle();

    return wrapper;
}

beforeEach(() => {
    document.body.innerHTML = '';
    router.post.mockClear();
});

afterEach(async () => {
    Swal.close();
    // O SweetAlert2 devolve o foco 100 ms depois de fechar (estado global dele).
    await new Promise((resolve) => setTimeout(resolve, 150));
    wrapper?.unmount();
    vi.unstubAllGlobals();
    delete globalThis.window.Swal;
    document.body.innerHTML = '';
    document.body.className = '';
});

describe('Gaveta do prontuário — confirmação de assinatura (SweetAlert2 real)', () => {
    it('"Assinar prontuário" abre a confirmação COM o foco nela; confirmar assina com a revisão que a tela mostrou', async () => {
        await mountDrawer();

        const signButton = document.querySelector('button.btn-outline-success');
        signButton.focus();
        signButton.click();
        await settle();

        // Antes: o trap devolvia o foco pro botão fechar da gaveta no mesmo instante.
        expect(document.activeElement?.closest('.swal2-container')).not.toBeNull();
        expect(router.post).not.toHaveBeenCalled();

        Swal.clickConfirm();
        await flushPromises();
        await settle();

        expect(router.post).toHaveBeenCalledWith(
            '/panel/patients/p1/medicalrecords/r1/sign',
            { clinical_revision: 3 },
            expect.any(Object),
        );
    });

    it('Escape na confirmação cancela só a confirmação: a gaveta segue aberta e nada é assinado', async () => {
        await mountDrawer();

        const signButton = document.querySelector('button.btn-outline-success');
        signButton.focus();
        signButton.click();
        await settle();

        pressKey('Escape', document.activeElement);
        await settle();

        expect(Swal.isVisible()).toBe(false);
        expect(wrapper.emitted('close')).toBeFalsy();
        expect(router.post).not.toHaveBeenCalled();
    });
});

describe('Gaveta do prontuário — pré-visualização do PDF por cima', () => {
    async function openPreview() {
        await mountDrawer();

        // Aba "Documentos" → olho do documento.
        document.querySelectorAll('[role="tab"]')[1].click();
        await settle();

        const view = document.querySelector('button.doc-view');
        view.focus();
        view.click();
        await settle();

        return view;
    }

    it('o foco entra na pré-visualização; Escape fecha só ela e devolve o foco ao olho que a abriu', async () => {
        const view = await openPreview();

        expect(document.querySelector('.modal-content')?.contains(document.activeElement)).toBe(true);

        pressKey('Escape');
        await settle();

        expect(document.querySelector('.modal-content')).toBeNull();
        expect(wrapper.emitted('close')).toBeFalsy();
        expect(document.activeElement).toBe(view);
    });

    it('com a pré-visualização fechada, o Escape volta a fechar a gaveta', async () => {
        await openPreview();

        pressKey('Escape');
        await settle();
        pressKey('Escape');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
