import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * Agendar a partir da tela de Retornos (Fase 6): o formulário abre com o paciente e o retorno escolhidos, avisa
 * qual retorno o agendamento atende e manda `recall_id` no cadastro; trocar o paciente desfaz o vínculo.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, usePage: () => ({ props: { t_ui: { patient_form: {} }, locale: 'pt_BR' } }) };
});
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const SearchSelectStub = {
    props: ['modelValue', 'options'],
    emits: ['update:modelValue'],
    template:
        '<select class="ss" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="o in options" :key="o.value ?? o.id" :value="o.value ?? o.id">{{ o.label ?? o.name }}</option></select>',
};
const SlotPickerStub = {
    props: ['modelValue', 'allowOccupied'],
    template: '<div class="slot-picker" />',
    methods: { setDate() {}, reset() {} },
};
const CenteredModalStub = { props: ['open'], template: '<div><slot /><slot name="footer" /></div>' };

const ScheduleFormModal = (await import('@/Pages/Panel/Schedules/ScheduleFormModal.vue')).default;

const t = {
    form_save_link: 'Salvar',
    form_recall_banner: 'Agendando o retorno: :task',
};

const prefill = {
    recall_id: 'rc-1',
    recall_label: 'Exame: Campo visual (campimetria)',
    patient_id: 'p-1',
    full_name: 'MARIA DOS RETORNOS',
    cellphone: '11977776666',
    telephone: null,
    cellphone_whatsapp: true,
    covenant_id: null,
};

async function mountForm(prefillData = null) {
    const wrapper = mount(ScheduleFormModal, {
        props: { open: false, doctors: [{ id: 'd1', name: 'DRA' }], storeUrl: '/store', prefillData, t },
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

const saveButton = (wrapper) => wrapper.findAll('button').find((b) => b.text() === 'Salvar');
const bodyOf = () => JSON.parse(globalThis.fetch.mock.calls.find((c) => c[0] === '/store')[1].body);

describe('ScheduleFormModal — retorno a agendar', () => {
    beforeEach(() => {
        // O cadastro do paciente (abas) carrega por GET; o envio do agendamento vai para /store.
        globalThis.fetch = vi.fn((url) =>
            Promise.resolve({
                ok: true,
                status: url === '/store' ? 201 : 200,
                json: () => Promise.resolve(url === '/store' ? { message: 'ok' } : { data: {} }),
            }),
        );
    });

    it('abre com o paciente, avisa qual retorno atende e manda recall_id no cadastro', async () => {
        const wrapper = await mountForm(prefill);
        const banner = wrapper.find('[data-test="recall-banner"]');

        expect(banner.exists()).toBe(true);
        expect(banner.text()).toBe('Agendando o retorno: Exame: Campo visual (campimetria)');
        expect(wrapper.vm.$.setupState.form.patient_id).toBe('p-1');
        expect(wrapper.vm.$.setupState.form.recall_id).toBe('rc-1');
        // O id do retorno nunca vira o da lista de espera.
        expect(wrapper.vm.$.setupState.form.waiting_list_id).toBe('');

        wrapper.vm.$.setupState.form.date_time = '2030-01-07T09:00';
        await saveButton(wrapper).trigger('click');
        await flushPromises();

        expect(bodyOf()).toMatchObject({ patient_id: 'p-1', recall_id: 'rc-1', waiting_list_id: '' });
    });

    it('trocar o paciente desfaz o vínculo: some o aviso e recall_id não vai no cadastro', async () => {
        const wrapper = await mountForm(prefill);

        wrapper.vm.$.setupState.selectPatient({ id: 'p-2', full_name: 'OUTRA PESSOA', cellphone: '', telephone: '' });
        await flushPromises();

        expect(wrapper.find('[data-test="recall-banner"]').exists()).toBe(false);
        expect(wrapper.vm.$.setupState.form.recall_id).toBe('');

        wrapper.vm.$.setupState.form.date_time = '2030-01-07T09:00';
        await saveButton(wrapper).trigger('click');
        await flushPromises();

        expect(bodyOf().recall_id).toBeNull();
    });

    it('escolher o MESMO paciente de novo mantém o vínculo; limpar o paciente desfaz', async () => {
        const wrapper = await mountForm(prefill);

        wrapper.vm.$.setupState.selectPatient({
            id: 'p-1',
            full_name: 'MARIA DOS RETORNOS',
            cellphone: '',
            telephone: '',
        });
        await flushPromises();
        expect(wrapper.vm.$.setupState.form.recall_id).toBe('rc-1');

        wrapper.vm.$.setupState.clearPatient();
        await flushPromises();
        expect(wrapper.vm.$.setupState.form.recall_id).toBe('');
        expect(wrapper.find('[data-test="recall-banner"]').exists()).toBe(false);
    });

    it('agendamento comum (sem retorno): nada de aviso e recall_id nulo', async () => {
        const wrapper = await mountForm(null);

        expect(wrapper.find('[data-test="recall-banner"]').exists()).toBe(false);

        wrapper.vm.$.setupState.form.date_time = '2030-01-07T09:00';
        wrapper.vm.$.setupState.form.full_name = 'AVULSO';
        await saveButton(wrapper).trigger('click');
        await flushPromises();

        expect(bodyOf().recall_id).toBeNull();
    });
});
