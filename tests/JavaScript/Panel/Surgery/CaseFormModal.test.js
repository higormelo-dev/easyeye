import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () => (await import('./inertiaMock.js')).inertiaMock());

const { __submitted } = await import('@inertiajs/vue3');
const CaseFormModal = (await import('@/Components/Surgery/CaseFormModal.vue')).default;

const modal = {
    template: '<div v-if="open"><slot name="header" /><slot name="tabs" /><slot /><slot name="footer" /></div>',
    props: ['open', 'size'],
};
const cid = {
    template: '<div data-test="cid" />',
    props: ['modelValue', 'searchUrl', 'maxItems', 'label', 'disabled', 'invalid'],
};
const patientField = {
    template:
        "<button data-test=\"pick\" type=\"button\" @click=\"$emit('update:modelValue', { id: 'pat-1', full_name: 'MARIA', code: 'PAC-1', covenant_id: 'cov-1' })\" />",
    props: ['modelValue', 'disabled'],
};

const ui = new Proxy({}, { get: (_, key) => String(key) });
const t = { ui: { form: ui } };

const options = {
    surgeons: [{ id: 'd1', name: 'DR. A', registry: '123' }],
    covenants: [{ id: 'cov-1', name: 'UNIMED' }],
    surgery_types: [{ id: 'st-1', name: 'Facoemulsificação' }],
    facilities: [{ id: 'f1', name: 'Clínica', type: 'own_clinic', rooms: [] }],
    eyes: [
        { value: 'od', label: 'OD' },
        { value: 'oe', label: 'OE' },
        { value: 'ao', label: 'AO' },
    ],
    regimes: [
        { value: 'ambulatory', label: 'Ambulatorial' },
        { value: 'day_hospital', label: 'Hospital-dia' },
    ],
    urgencies: [{ value: 'elective', label: 'Eletiva' }],
    anesthesias: [{ value: 'topical', label: 'Tópica' }],
    team_roles: [{ value: 'assistant', label: 'Auxiliar' }],
};

const urls = {
    store: '/surgery',
    update: '/surgery/c1',
    patient_search: '/patients/search',
    procedure_search: '/procedures/search',
    cid_search: '/cid',
    patient_exams_base: '/surgery/patients/__ID__/exams',
};

const mountModal = (props = {}) =>
    mount(CaseFormModal, {
        props: { open: true, options, can: { manage: true, perform: false }, urls, currentDoctorId: 'd1', t, ...props },
        global: { stubs: { CenteredModal: modal, Cid10Picker: cid, PatientSearchField: patientField } },
    });

const editable = { request: true, planning: true, any: true };
const caseData = (over = {}) => ({
    patient: { id: 'pat-1', name: 'MARIA', code: 'PAC-1' },
    surgeon: { id: 'd1' },
    title: 'Facoemulsificação OD',
    eye_value: 'od',
    surgery_type_id: null,
    covenant_id: 'cov-1',
    covenant_card_number: '99',
    technique: null,
    anesthesia: null,
    regime_value: 'ambulatory',
    urgency_value: 'elective',
    estimated_duration: 45,
    facility_id: null,
    cid_codes: [{ code: 'H25.0', description: 'Catarata senil incipiente' }],
    clinical_indication: null,
    days_requested: 1,
    needs_opme: false,
    notes: null,
    revision: 5,
    editable,
    ...over,
});

beforeEach(() => {
    __submitted.length = 0;
    globalThis.fetch = vi.fn(() =>
        Promise.resolve({
            ok: true,
            json: () =>
                Promise.resolve([{ id: 'ex1', name: 'Biometria', performed_at: '2026-10-01', laterality: 'OD' }]),
        }),
    );
});

describe('CaseFormModal — criação', () => {
    it('secretária não vê a aba de planejamento e o POST não leva campos de ato médico', async () => {
        const wrapper = mountModal();

        expect(wrapper.find('[data-testid="surgery-form-tab-planning"]').exists()).toBe(false);

        await wrapper.find('[data-test="pick"]').trigger('click');
        await wrapper.find('[data-testid="surgery-form-title"]').setValue('Facoemulsificação OD');
        await wrapper.find('[data-testid="surgery-form-save"]').trigger('click');

        const sent = __submitted.at(-1);
        expect(sent.method).toBe('post');
        expect(sent.url).toBe('/surgery');
        expect(sent.payload.patient_id).toBe('pat-1');
        expect(sent.payload.surgeon_doctor_id).toBe('d1');
        expect(sent.payload).not.toHaveProperty('technique');
        expect(sent.payload).not.toHaveProperty('anesthesia');
        expect(sent.payload).not.toHaveProperty('iol_plans');
    });

    it('médico vê o planejamento e envia a LIO planejada', async () => {
        const wrapper = mountModal({ can: { manage: true, perform: true } });

        await wrapper.find('[data-test="pick"]').trigger('click');
        await wrapper.find('[data-testid="surgery-form-title"]').setValue('Faco');
        await wrapper.find('[data-testid="surgery-form-tab-planning"]').trigger('click');
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'iol_add')
            .trigger('click');

        const row = wrapper.find('[data-testid="surgery-iol-row"]');
        await row.find('input[id^="sf-i-model"]').setValue('LIO X');
        await row.find('input[id^="sf-i-power"]').setValue('21.5');
        await wrapper.find('[data-testid="surgery-form-save"]').trigger('click');

        const iol = __submitted.at(-1).payload.iol_plans;
        expect(iol).toHaveLength(1);
        expect(iol[0]).toMatchObject({ model_label: 'LIO X', power: 21.5 });
    });

    it('um plano de LIO por olho: o olho que outra linha já usa fica indisponível no seletor', async () => {
        const wrapper = mountModal({ can: { manage: true, perform: true } });

        await wrapper.find('[data-test="pick"]').trigger('click');
        await wrapper.find('[data-testid="surgery-form-tab-planning"]').trigger('click');

        const add = () => wrapper.findAll('button').find((b) => b.text() === 'iol_add');
        await add().trigger('click');
        await add().trigger('click');

        const rows = wrapper.findAll('[data-testid="surgery-iol-row"]');
        expect(rows).toHaveLength(2);

        const selects = rows.map((row) => row.find('select[id^="sf-i-eye"]'));
        const disabled = (select) => select.findAll('option').map((o) => o.attributes('disabled') !== undefined);

        // A 1ª linha ficou com OD e a 2ª com OE: cada uma só pode manter o próprio olho.
        expect(selects.map((s) => s.element.value)).toEqual(['od', 'oe']);
        expect(disabled(selects[0])).toEqual([false, true]);
        expect(disabled(selects[1])).toEqual([true, false]);

        // Com os dois olhos usados, o botão de adicionar também some do caminho.
        expect(add().attributes('disabled')).toBeDefined();
    });

    it('escolher o paciente carrega os exames dele e herda o convênio do cadastro', async () => {
        const wrapper = mountModal();

        await wrapper.find('[data-test="pick"]').trigger('click');
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledWith('/surgery/patients/pat-1/exams', expect.any(Object));
        await wrapper.find('[data-testid="surgery-form-tab-team"]').trigger('click');
        expect(wrapper.text()).toContain('Biometria');
        expect(wrapper.find('#sf-covenant').element.value).toBe('cov-1');
    });

    it('OPME desligado não manda materiais; ligado, manda', async () => {
        const wrapper = mountModal();

        await wrapper.find('[data-test="pick"]').trigger('click');
        await wrapper.find('[data-testid="surgery-form-tab-request"]').trigger('click');
        await wrapper.find('#sf-needs-opme').setValue(true);
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'material_add')
            .trigger('click');
        await wrapper.find('input[id^="sf-m-desc"]').setValue('Anel de tensão');

        await wrapper.find('[data-testid="surgery-form-save"]').trigger('click');
        expect(__submitted.at(-1).payload.materials).toHaveLength(1);

        await wrapper.find('#sf-needs-opme').setValue(false);
        await wrapper.find('[data-testid="surgery-form-save"]').trigger('click');
        expect(__submitted.at(-1).payload.materials).toEqual([]);
    });

    it('regime com internação pede as diárias', async () => {
        const wrapper = mountModal();

        expect(wrapper.find('#sf-days').exists()).toBe(false);
        await wrapper.find('#sf-regime').setValue('day_hospital');
        expect(wrapper.find('#sf-days').exists()).toBe(true);
    });

    it('título sugerido pelo tipo de cirurgia só quando está vazio', async () => {
        const wrapper = mountModal();

        await wrapper.find('#sf-type').setValue('st-1');
        expect(wrapper.find('[data-testid="surgery-form-title"]').element.value).toBe('Facoemulsificação');

        await wrapper.find('[data-testid="surgery-form-title"]').setValue('Meu título');
        await wrapper.find('#sf-type').setValue('');
        await wrapper.find('#sf-type').setValue('st-1');
        expect(wrapper.find('[data-testid="surgery-form-title"]').element.value).toBe('Meu título');
    });
});

describe('CaseFormModal — edição', () => {
    it('PUT com a revisão vista pela tela e sem trocar o paciente', async () => {
        const wrapper = mountModal({
            caseData: caseData(),
            request: { procedures: [], materials: [], iol_plans: [], exams: [], team: [] },
        });

        await wrapper.find('[data-testid="surgery-form-title"]').setValue('Faco OD v2');
        await wrapper.find('[data-testid="surgery-form-save"]').trigger('click');

        const sent = __submitted.at(-1);
        expect(sent.method).toBe('put');
        expect(sent.url).toBe('/surgery/c1');
        expect(sent.payload.expected_revision).toBe(5);
        expect(sent.payload.title).toBe('Faco OD v2');
        expect(sent.payload).not.toHaveProperty('patient_id');
    });

    it('pedido travado (já enviado): campos do pedido desabilitados e fora do envio', async () => {
        const wrapper = mountModal({
            caseData: caseData({ editable: { request: false, planning: true, any: true } }),
            request: {
                procedures: [{ procedure_id: null, tuss_code: '1', description: 'X', quantity: 1, access_route: null }],
                materials: [],
                iol_plans: [],
                exams: [],
                team: [],
            },
        });

        expect(wrapper.find('[data-testid="surgery-form-title"]').attributes('disabled')).toBeDefined();

        await wrapper.find('#sf-notes').setValue('só uma observação');
        await wrapper.find('[data-testid="surgery-form-save"]').trigger('click');

        const payload = __submitted.at(-1).payload;
        expect(payload.notes).toBe('só uma observação');
        for (const key of ['title', 'eye', 'procedures', 'materials', 'cid_codes', 'regime']) {
            expect(payload).not.toHaveProperty(key);
        }
    });

    it('caso encerrado desabilita tudo, inclusive o botão salvar', () => {
        const wrapper = mountModal({
            caseData: caseData({ editable: { request: false, planning: false, any: false } }),
            request: { procedures: [], materials: [], iol_plans: [], exams: [], team: [] },
        });

        expect(wrapper.find('[data-testid="surgery-form-save"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('locked_all');
    });
});
