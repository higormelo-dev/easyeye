import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import MedicalRecordForm from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue';

/**
 * Declaração médica: o servidor emitia (quick action `medical-declaration`),
 * mas nenhum botão chamava. O item agora fica na barra do prontuário, junto
 * dos atestados — no edit emite direto; no create salva e reabre com a ação.
 */
const inertia = vi.hoisted(() => ({ submits: [] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        usePage: () => ({ props: { auth: { user: { preferences: {} } }, flash: {}, errors: {}, locale: 'pt_BR' } }),
        router: { on: vi.fn(() => () => {}), visit: vi.fn(), reload: vi.fn() },
        useForm: (data) => {
            const form = actual.useForm(data);
            for (const method of ['put', 'post']) {
                form[method] = vi.fn((url) => inertia.submits.push({ method, url, data: form.data() }));
            }
            return form;
        },
    };
});

enableAutoUnmount(afterEach);

const STUBS = {
    teleport: true,
    AiAssistantPanel: true,
    TinyMceEditor: true,
    SearchSelect: true,
    AcuitySelect: true,
    MedicalRecordFileUploadModal: true,
    MedicalRecordImagingModal: true,
    MedicalRecordProceduresModal: true,
    ContactLensCalculatorModal: true,
    PdfPreviewModal: true,
};

function mountForm({ edit = true, locked = false, isDoctor = true, ai = undefined } = {}) {
    return mount(MedicalRecordForm, {
        props: {
            patient: { id: 'p1', full_name: 'Paciente Teste' },
            medicalrecord: edit ? { id: 'r1', doctor_id: 'd1', is_locked: locked } : null,
            isEdit: edit,
            isDoctor,
            ai,
            currentDoctorId: 'd1',
            catalogs: {},
            urls: {
                store: '/store',
                update: '/update',
                list: '/list',
                schedules: '/agenda',
                quick_action_template: '/qa/__ACTION__',
            },
            t: { medical_declaration: 'Declaração médica' },
        },
        global: { stubs: STUBS },
    });
}

describe('MedicalRecordForm — botão Declaração médica', () => {
    beforeEach(() => {
        inertia.submits.length = 0;
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                status: 201,
                json: () => Promise.resolve({ id: 'doc1', title: 'Declaração Médica', pdf_url: '/pdf/doc1' }),
            }),
        );
    });

    it('fica na barra com rótulo traduzido e acessível', () => {
        const btn = mountForm().find('[data-testid="medical-declaration-btn"]');

        expect(btn.exists()).toBe(true);
        expect(btn.attributes('aria-label')).toBe('Declaração médica');
        expect(btn.attributes('title')).toBe('Declaração médica');
    });

    it('no prontuário salvo, emite a quick action medical-declaration', async () => {
        const wrapper = mountForm();

        await wrapper.find('[data-testid="medical-declaration-btn"]').trigger('click');
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        const [url, init] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/qa/medical-declaration');
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body)).toEqual({ content: '' });
    });

    it('antes do 1º save, salva o prontuário com a ação pendente', async () => {
        const wrapper = mountForm({ edit: false });

        await wrapper.find('[data-testid="medical-declaration-btn"]').trigger('click');
        await flushPromises();

        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(inertia.submits).toHaveLength(1);
        expect(inertia.submits[0].data.post_save_action).toBe('medical_declaration');
    });

    it('prontuário assinado: botão desabilitado', () => {
        const btn = mountForm({ locked: true }).find('[data-testid="medical-declaration-btn"]');

        expect(btn.attributes('disabled')).toBeDefined();
    });

    it('perfil médico sem cadastro ativo (isDoctor=false do servidor): sem botões de documento e sem Assistente de IA', () => {
        const wrapper = mountForm({ isDoctor: false, ai: { enabled: true } });

        expect(wrapper.find('[data-testid="medical-declaration-btn"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Assistente de IA');
    });
});
