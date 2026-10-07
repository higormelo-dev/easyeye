import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * "Editar" agendamento: o formulário era hidratado pelo JSON do show() sem
 * covenant_id/full_name e com a data formatada (d/m/Y) — abria com convênio
 * vazio e o PUT apagava o convênio. Agora usa os valores crus; o convênio já
 * inativado (fora da lista de ativos) aparece marcado "(inativo)" e é reenviado
 * igual (o servidor aceita o mesmo id). Erro em covenant_id aparece no campo.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, usePage: () => ({ props: { t_ui: { patient_form: {} } } }) };
});

const SearchSelectStub = {
    props: ['modelValue', 'options', 'placeholder', 'invalid', 'clearable'],
    template: `<div class="ss" :data-value="modelValue" :data-invalid="String(!!invalid)" :data-options="JSON.stringify(options ?? [])" />`,
};
const CenteredModalStub = { props: ['open'], template: '<div><slot /><slot name="footer" /></div>' };
const SlotPickerStub = {
    props: ['modelValue'],
    template: '<div class="slot-picker" />',
    methods: { setDate() {}, reset() {} },
};

const ScheduleFormModal = (await import('@/Pages/Panel/Schedules/ScheduleFormModal.vue')).default;

const t = { form_save_link: 'Salvar', covenant_inactive_suffix: '(inativo)' };
const editSchedule = {
    id: 's1',
    doctor_id: 'd1',
    patient_id: null,
    full_name: 'PACIENTE ANTIGO',
    patient_name: 'PACIENTE ANTIGO',
    date_time: '06/10/2026 14:00',
    date_time_iso: '2026-10-06T14:00',
    covenant_id: 'cov-old',
    covenant_name: 'UNIMED VELHA',
    covenant_active: false,
    visit_id: 'v1',
    specialty_area: null,
    notes: 'obs',
    resources: [],
};

async function mountEdit() {
    const wrapper = mount(ScheduleFormModal, {
        props: {
            open: false,
            editSchedule,
            doctors: [{ id: 'd1', name: 'DRA' }],
            covenants: [{ id: 'cov-ok', name: 'AMIL' }],
            storeUrl: '/store',
            t,
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
    return wrapper;
}

const covenantSelect = (wrapper) => wrapper.findAll('.ss').find((s) => s.attributes('data-options').includes('cov-ok'));
const saveButton = (wrapper) => wrapper.findAll('button').find((b) => b.text() === 'Salvar');

beforeEach(() => vi.clearAllMocks());

describe('Agenda — editar agendamento com convênio', () => {
    it('abre com o convênio atual marcado "(inativo)" e reenvia o mesmo id, nome e data ISO', async () => {
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ message: 'ok' }) }),
        );
        const wrapper = await mountEdit();

        const select = covenantSelect(wrapper);
        expect(select.attributes('data-value')).toBe('cov-old');
        expect(JSON.parse(select.attributes('data-options'))).toContainEqual({
            id: 'cov-old',
            name: 'UNIMED VELHA (inativo)',
        });

        await saveButton(wrapper).trigger('click');
        await flushPromises();

        const [url, init] = globalThis.fetch.mock.calls.find(([, opts]) => opts?.method === 'PUT');
        const body = JSON.parse(init.body);
        expect(url).toBe('/panel/schedules/s1');
        expect(init.method).toBe('PUT');
        expect(body.covenant_id).toBe('cov-old');
        expect(body.full_name).toBe('PACIENTE ANTIGO');
        expect(body.date_time).toBe('2026-10-06T14:00');
        expect(body.visit_id).toBe('v1');
    });

    it('erro de convênio inativo aparece no campo', async () => {
        const msg = 'O convênio selecionado está inativo. Escolha um convênio ativo.';
        globalThis.fetch = vi.fn((url, opts) =>
            opts?.method === 'PUT'
                ? Promise.resolve({
                      ok: false,
                      status: 422,
                      json: () => Promise.resolve({ errors: { covenant_id: [msg] } }),
                  })
                : Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: [] }) }),
        );
        const wrapper = await mountEdit();

        await saveButton(wrapper).trigger('click');
        await flushPromises();

        expect(covenantSelect(wrapper).attributes('data-invalid')).toBe('true');
        expect(wrapper.text()).toContain(msg);
    });
});
