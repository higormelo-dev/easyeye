import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, enableAutoUnmount } from '@vue/test-utils';
import Create from '@/Pages/Panel/MedicalRecords/Create.vue';
import Edit from '@/Pages/Panel/MedicalRecords/Edit.vue';
import MedicalRecordForm from '@/Pages/Panel/MedicalRecords/Components/MedicalRecordForm.vue';

/**
 * As páginas Novo/Editar prontuário repassam a sugestão do campo "Retorno" (Fase 6) ao formulário: o servidor manda
 * `followUp` (só ao médico, só com protocolo em uso) e o formulário é quem desenha. Sem o repasse, a sugestão
 * existiria no servidor e nunca apareceria na tela.
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

const FOLLOW_UP = { suggestions: [{ protocol_key: 'glaucoma', days: 120 }], max_days: 181, t: {} };

const Layout = { template: '<div><slot /></div>' };
const FormStub = {
    name: 'MedicalRecordFormStub',
    props: { followUp: { type: Object, default: null }, isDoctor: Boolean },
    template: '<div data-testid="form-stub" />',
};

const base = {
    patient: { id: 'p1', full_name: 'Maria' },
    catalogs: {},
    urls: { list: '/list', create: '/create', pdf: '/pdf' },
    isDoctor: true,
};

const global = {
    stubs: {
        AppLayout: Layout,
        MedicalRecordForm: FormStub,
        PatientInfoSidebar: true,
        PreviousRecordsCard: true,
        MedicalRecordViewModal: true,
        ScheduleFlowGuard: true,
        AttendanceTimer: true,
        ClinicalAlertBanner: true,
    },
};

describe('páginas do prontuário — repasse da sugestão de retorno', () => {
    it('Novo prontuário: o formulário recebe followUp do servidor (e o componente real declara a prop)', () => {
        const wrapper = mount(Create, { props: { ...base, followUp: FOLLOW_UP }, global });

        expect(wrapper.findComponent(FormStub).props('followUp')).toEqual(FOLLOW_UP);
        expect(Object.keys(MedicalRecordForm.props)).toContain('followUp');
    });

    it('Editar prontuário: idem', () => {
        const wrapper = mount(Edit, {
            props: { ...base, medicalrecord: { id: 'r1', is_locked: false }, followUp: FOLLOW_UP },
            global,
        });

        expect(wrapper.findComponent(FormStub).props('followUp')).toEqual(FOLLOW_UP);
    });

    it('sem sugestão (admin, sem condição ativa, plano sem a feature): o formulário recebe null', () => {
        const create = mount(Create, { props: base, global });
        const edit = mount(Edit, { props: { ...base, medicalrecord: { id: 'r1', is_locked: false } }, global });

        expect(create.findComponent(FormStub).props('followUp')).toBeNull();
        expect(edit.findComponent(FormStub).props('followUp')).toBeNull();
    });
});
