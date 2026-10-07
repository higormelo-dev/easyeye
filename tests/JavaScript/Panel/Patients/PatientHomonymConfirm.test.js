import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { effectScope, nextTick, reactive } from 'vue';

/**
 * Homônimo no cadastro de paciente (Pacientes, abas da Agenda e cadastro
 * rápido da lista de espera): o servidor devolve 422 em `confirm_homonym`
 * com o(s) código(s) do paciente de mesmo nome; a tela mostra o aviso com
 * "Confirmo que é outro paciente" e reenvia com confirm_homonym=true.
 * Também: convênio inativo que o paciente JÁ tem aparece marcado "(inativo)".
 */
vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: { data: [] } })) } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        usePage: () => ({
            props: {
                t_ui: {
                    patient_form: {
                        homonym_title: 'Paciente com o mesmo nome',
                        homonym_confirm: 'Confirmo que é outro paciente',
                        covenant_inactive_suffix: '(inativo)',
                    },
                },
            },
        }),
    };
});

const SearchSelectStub = {
    props: ['modelValue', 'options', 'placeholder', 'invalid', 'clearable', 'valueKey', 'labelKey', 'disabled'],
    template: `<div class="ss" :data-options="JSON.stringify(options ?? [])" />`,
};
const OffcanvasStub = {
    props: ['open'],
    template: '<div><slot name="header" /><slot name="tabs" /><slot /><slot name="footer" /></div>',
};

const PatientFormModal = (await import('@/Pages/Panel/Patients/PatientFormModal.vue')).default;
const PatientFormSections = (await import('@/Pages/Panel/Patients/PatientFormSections.vue')).default;
const WaitingListFormModal = (await import('@/Pages/Panel/Schedules/WaitingListFormModal.vue')).default;
const { usePatientForm } = await import('@/Support/usePatientForm.js');

const HOMONYM =
    'Já existe paciente com este nome nesta clínica (PAC-0000000007). Se for outra pessoa, confirme abaixo para salvar.';

function jsonResponse(status, body) {
    return Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });
}

const bodyOf = (call) => JSON.parse(call[1].body);

beforeEach(() => {
    vi.clearAllMocks();
});

describe('Cadastro de paciente — homônimo', () => {
    async function mountModal() {
        const wrapper = mount(PatientFormModal, {
            props: { open: false, embedded: true, covenants: [{ id: 'c1', name: 'PARTICULAR' }] },
            global: {
                stubs: { SearchSelect: SearchSelectStub, OffcanvasPanel: OffcanvasStub },
                directives: { mask: {} },
            },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        return wrapper;
    }

    const saveButton = (wrapper) => wrapper.findAll('button').find((b) => b.text().includes('Cadastrar paciente'));

    it('mostra o aviso com o código e só reenvia confirmado depois do "Confirmo que é outro paciente"', async () => {
        globalThis.fetch = vi
            .fn()
            .mockImplementationOnce(() => jsonResponse(422, { errors: { confirm_homonym: [HOMONYM] } }))
            .mockImplementationOnce(() => jsonResponse(200, { data: { id: 'new-id' } }));

        const wrapper = await mountModal();
        await wrapper.find('input[type="text"]').setValue('José da Silva');

        await saveButton(wrapper).trigger('click');
        await flushPromises();

        expect(bodyOf(globalThis.fetch.mock.calls[0]).confirm_homonym).toBe(false);
        const alert = wrapper.find('[data-testid="homonym-alert"]');
        expect(alert.exists()).toBe(true);
        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toContain('PAC-0000000007');
        expect(alert.text()).toContain('Confirmo que é outro paciente');
        expect(wrapper.emitted('saved')).toBeUndefined();

        await wrapper.find('[data-testid="homonym-confirm"]').setValue(true);
        await saveButton(wrapper).trigger('click');
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledTimes(2);
        expect(bodyOf(globalThis.fetch.mock.calls[1]).confirm_homonym).toBe(true);
        expect(wrapper.emitted('saved')[0][0].id).toBe('new-id');
    });

    it('duplicado real (mesmo nome e nascimento) aparece no campo nome, sem checkbox de confirmação', async () => {
        const dup = 'Já existe um paciente com este nome e esta data de nascimento nesta clínica (PAC-0000000007).';
        globalThis.fetch = vi.fn(() => jsonResponse(422, { errors: { name: [dup] } }));

        const wrapper = await mountModal();
        await saveButton(wrapper).trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain(dup);
        expect(wrapper.find('[data-testid="homonym-alert"]').exists()).toBe(false);
    });
});

describe('PatientFormSections', () => {
    function mountSections(form, extra = {}) {
        return mount(PatientFormSections, {
            props: { form, section: 'personal', covenants: [{ id: 'c1', name: 'AMIL' }], ...extra },
            global: { stubs: { SearchSelect: SearchSelectStub }, directives: { mask: {} } },
        });
    }

    it('trocar o nome depois de confirmar desfaz a confirmação (usePatientForm)', async () => {
        const scope = effectScope();
        const { form } = scope.run(() => usePatientForm({}));

        form.name = 'JOSE';
        await nextTick();
        form.confirm_homonym = true;

        form.name = 'JOSE CARLOS';
        await nextTick();
        expect(form.confirm_homonym).toBe(false);

        form.confirm_homonym = true;
        form.birth_date = '1990-01-01';
        await nextTick();
        expect(form.confirm_homonym).toBe(false);

        scope.stop();
    });

    it('convênio inativo que o paciente já tem aparece marcado "(inativo)" na lista', () => {
        const form = reactive({ name: '', birth_date: '', covenant_id: 'old', errors: {} });
        const wrapper = mountSections(form, {
            section: 'clinical',
            covenantOption: { id: 'old', name: 'UNIMED VELHA', active: false },
        });

        const covenantSelect = wrapper.findAll('.ss').find((s) => s.attributes('data-options').includes('"c1"'));
        const options = JSON.parse(covenantSelect.attributes('data-options'));
        expect(options).toEqual([
            { id: 'c1', name: 'AMIL' },
            { id: 'old', name: 'UNIMED VELHA (inativo)' },
        ]);
    });
});

describe('Lista de espera — cadastro rápido', () => {
    it('envia nome + celular; homônimo pede confirmação e reenvia confirmado', async () => {
        globalThis.fetch = vi
            .fn()
            .mockImplementationOnce(() => jsonResponse(422, { errors: { confirm_homonym: [HOMONYM] } }))
            .mockImplementationOnce(() =>
                jsonResponse(201, { patient: { id: 'p9', full_name: 'JOSE DA SILVA', cellphone: '11977776666' } }),
            );

        const wrapper = mount(WaitingListFormModal, {
            props: {
                open: false,
                t: { wl_full_name: 'Nome completo', wl_cellphone: 'Celular', wl_save_link: 'Salvar' },
            },
            global: {
                stubs: { SearchSelect: SearchSelectStub, OffcanvasPanel: OffcanvasStub },
                directives: { mask: {} },
            },
        });
        await wrapper.setProps({ open: true });

        // Abre o cadastro rápido (mesmo efeito do item "Cadastrar ..." da busca).
        wrapper.vm.$.setupState.showQuickReg = true;
        await nextTick();

        await wrapper.find('[data-testid="wl-quick-name"]').setValue('José da Silva');
        await wrapper.find('[data-testid="wl-quick-cellphone"]').setValue('(11) 97777-6666');
        await wrapper.find('[data-testid="wl-quick-save"]').trigger('click');
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][0]).toBe('/_routes/panel.patients.quick');
        expect(bodyOf(globalThis.fetch.mock.calls[0])).toEqual({
            name: 'José da Silva',
            cellphone: '(11) 97777-6666',
            confirm_homonym: false,
        });
        expect(wrapper.find('[data-testid="wl-quick-homonym"]').text()).toContain('PAC-0000000007');

        await wrapper.find('#wl-quick-homonym-confirm').setValue(true);
        await wrapper.find('[data-testid="wl-quick-save"]').trigger('click');
        await flushPromises();

        expect(bodyOf(globalThis.fetch.mock.calls[1]).confirm_homonym).toBe(true);
        expect(wrapper.find('[data-testid="wl-quick-name"]').exists()).toBe(false);
    });
});
