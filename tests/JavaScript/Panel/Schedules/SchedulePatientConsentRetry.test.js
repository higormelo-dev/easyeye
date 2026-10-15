import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * Agendamento que salva o paciente ANTES do horário (LGPD, autorização de lembretes): o horário recusado deixa a tela
 * aberta com a autorização já gravada. Tentar de novo depois de voltar atrás na caixa tem de REVOGAR — antes a base
 * continuava "desmarcada" e a revogação sumia (nada ia, ou ia nulo), deixando a autorização ativa com a tela mostrando
 * o contrário.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, usePage: () => ({ props: { t_ui: { patient_form: {} }, locale: 'pt_BR' } }) };
});
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const SearchSelectStub = {
    props: ['modelValue', 'options'],
    emits: ['update:modelValue'],
    template: '<select class="ss" />',
};
const SlotPickerStub = {
    props: ['modelValue', 'allowOccupied'],
    template: '<div class="slot-picker" />',
    methods: { setDate() {}, reset() {} },
};
const CenteredModalStub = { props: ['open'], template: '<div><slot /><slot name="footer" /></div>' };

const ScheduleFormModal = (await import('@/Pages/Panel/Schedules/ScheduleFormModal.vue')).default;

const prefill = {
    patient_id: 'p-1',
    full_name: 'MARIA DOS RETORNOS',
    cellphone: '11977776666',
    telephone: null,
    cellphone_whatsapp: true,
    covenant_id: null,
};

const json = (status, body) => Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });

let calls;

function fakeServer({ storeStatus }) {
    calls = [];
    globalThis.route = vi.fn((name, id) => `/_routes/${name}/${id ?? ''}`);
    globalThis.fetch = vi.fn((url, init = {}) => {
        const target = String(url);
        calls.push({ url: target, method: init.method ?? 'GET', body: init.body ? JSON.parse(init.body) : null });

        if (target.includes('editData')) {
            return json(200, { data: { name: 'MARIA DOS RETORNOS', care_reminders_consent: false, whatsapp: true } });
        }

        if (target === '/store') {
            return storeStatus === 422
                ? json(422, { errors: { date_time: ['Horário indisponível.'] } })
                : json(201, { message: 'ok' });
        }

        return json(200, { data: { id: 'p-1' } });
    });
}

async function mountForm() {
    const wrapper = mount(ScheduleFormModal, {
        props: {
            open: false,
            doctors: [{ id: 'd1', name: 'DRA' }],
            storeUrl: '/store',
            prefillData: prefill,
            t: { form_save_link: 'Salvar' },
        },
        global: {
            stubs: {
                SearchSelect: SearchSelectStub,
                CenteredModal: CenteredModalStub,
                SlotPicker: SlotPickerStub,
                PatientFormSections: true,
            },
            directives: { mask: {} },
        },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
    wrapper.vm.$.setupState.form.date_time = '2030-01-07T09:00';

    return wrapper;
}

const save = async (wrapper) => {
    await wrapper
        .findAll('button')
        .find((b) => b.text() === 'Salvar')
        .trigger('click');
    await flushPromises();
};
const patientSaves = () => calls.filter((c) => c.method === 'PUT' && c.url.includes('panel.patients.update'));
const storeAttempts = () => calls.filter((c) => c.url === '/store');

beforeEach(() => vi.clearAllMocks());

describe('Agenda — autorização de lembretes ao tentar de novo depois de um horário recusado', () => {
    it('marcar → horário recusado → desmarcar → tentar de novo: a revogação VAI (true e depois false)', async () => {
        fakeServer({ storeStatus: 422 });
        const wrapper = await mountForm();
        const patient = wrapper.vm.$.setupState.patientForm;

        patient.care_reminders_consent = true;
        await flushPromises();
        await save(wrapper);

        expect(patientSaves()).toHaveLength(1);
        expect(patientSaves()[0].body.care_reminders_consent).toBe(true);
        expect(storeAttempts()).toHaveLength(1);

        // A pessoa volta atrás na mesma tela (a caixa volta ao valor com que o cadastro abriu).
        patient.care_reminders_consent = false;
        await flushPromises();
        await save(wrapper);

        expect(patientSaves()).toHaveLength(2);
        expect(patientSaves()[1].body.care_reminders_consent).toBe(false);
        expect(storeAttempts()).toHaveLength(2);
    });

    it('tentar de novo SEM mexer em nada não grava o paciente outra vez nem reenvia a autorização', async () => {
        fakeServer({ storeStatus: 422 });
        const wrapper = await mountForm();

        wrapper.vm.$.setupState.patientForm.care_reminders_consent = true;
        await flushPromises();
        await save(wrapper);
        await save(wrapper);

        expect(patientSaves()).toHaveLength(1);
        expect(storeAttempts()).toHaveLength(2);
    });

    it('com outro campo do paciente mexido no meio, a nova gravação leva a autorização que mudou (false), nunca nulo', async () => {
        fakeServer({ storeStatus: 422 });
        const wrapper = await mountForm();
        const patient = wrapper.vm.$.setupState.patientForm;

        patient.care_reminders_consent = true;
        await flushPromises();
        await save(wrapper);

        patient.care_reminders_consent = false;
        patient.occupation = 'Aposentada';
        await flushPromises();
        await save(wrapper);

        expect(patientSaves().map((c) => c.body.care_reminders_consent)).toEqual([true, false]);
    });
});
