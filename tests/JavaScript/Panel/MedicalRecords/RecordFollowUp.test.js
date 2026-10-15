import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import MedicalRecordForm from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue';
import followUpT from '../Recall/support/recall_followup.json';

/**
 * Prontuário × sugestão do campo "Retorno" (Fase 6): o médico vê o intervalo que a clínica escolheu para cada
 * condição crônica ativa do paciente, usa com um clique (o campo continua dele) e é avisado do teto validado.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        usePage: () => ({ props: { auth: { user: { preferences: {} } }, flash: {}, errors: {}, locale: 'pt_BR' } }),
        router: { on: vi.fn(() => () => {}), visit: vi.fn(), reload: vi.fn() },
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
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

const FOLLOW_UP = {
    suggestions: [
        {
            protocol_key: 'glaucoma',
            title: 'Glaucoma',
            eye: 'both',
            eye_label: 'Ambos os olhos (AO)',
            interval: 4,
            unit: 'months',
            unit_label: 'meses',
            days: 120,
        },
    ],
    max_days: 181,
    t: followUpT,
};

function mountForm({ followUp = FOLLOW_UP, isDoctor = true, record = {} } = {}) {
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) }));

    return mount(MedicalRecordForm, {
        props: {
            patient: { id: 'p1', full_name: 'Maria' },
            medicalrecord: { id: 'r1', doctor_id: 'd1', is_locked: false, ...record },
            isEdit: true,
            isDoctor,
            currentDoctorId: 'd1',
            catalogs: {},
            followUp,
            urls: {
                store: '/store',
                update: '/update',
                list: '/list',
                schedules: '/agenda',
                quick_action_template: '/qa/__ACTION__',
            },
            t: {},
        },
        global: { stubs: STUBS },
    });
}

const field = (wrapper) => wrapper.find('input[name="follow_up_days"]');

describe('prontuário — sugestão do campo Retorno', () => {
    it('o médico vê a sugestão junto do campo e o campo continua vazio (nada é preenchido sozinho)', () => {
        const wrapper = mountForm();

        expect(wrapper.find('[data-testid="follow-up-suggestion"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="follow-up-glaucoma"]').text()).toContain('consulta a cada 4 meses');
        expect(field(wrapper).element.value).toBe('');
    });

    it('"Usar 120 dias" preenche o campo; com o valor igual, vira "Valor atual"', async () => {
        const wrapper = mountForm();

        await wrapper.find('[data-testid="follow-up-use"]').trigger('click');

        expect(field(wrapper).element.value).toBe('120');
        expect(wrapper.find('[data-testid="follow-up-use"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="follow-up-glaucoma"]').text()).toContain('Valor atual');
    });

    it('digitar acima do teto troca o aviso; voltar ao teto desfaz', async () => {
        const wrapper = mountForm();

        await field(wrapper).setValue('400');
        expect(wrapper.find('[data-testid="follow-up-cap"]').text()).toContain('O valor passa de 181 dias');

        await field(wrapper).setValue('90');
        expect(wrapper.find('[data-testid="follow-up-cap"]').text()).toContain(
            'Retorno acima de 181 dias vale 181 dias',
        );
    });

    it('a sugestão sobrescreve o que já estava no campo (o médico escolheu usar)', async () => {
        const wrapper = mountForm({ record: { follow_up_days: 30 } });

        expect(field(wrapper).element.value).toBe('30');

        await wrapper.find('[data-testid="follow-up-use"]').trigger('click');

        expect(field(wrapper).element.value).toBe('120');
    });

    it('prontuário assinado/travado: o botão não funciona', async () => {
        const wrapper = mountForm({ record: { is_locked: true } });

        expect(wrapper.find('[data-testid="follow-up-use"]').attributes('disabled')).toBeDefined();
        expect(field(wrapper).attributes('disabled')).toBeDefined();
    });

    it('sem a prop (admin, sem condição ativa, plano sem a feature) ou fora do perfil médico: nada aparece', async () => {
        expect(mountForm({ followUp: null }).find('[data-testid="follow-up-suggestion"]').exists()).toBe(false);
        expect(
            mountForm({ followUp: { suggestions: [], max_days: null, t: followUpT } })
                .find('[data-testid="follow-up-suggestion"]')
                .exists(),
        ).toBe(false);
        expect(mountForm({ isDoctor: false }).find('[data-testid="follow-up-suggestion"]').exists()).toBe(false);

        await flushPromises();
    });
});
