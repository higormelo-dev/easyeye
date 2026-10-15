import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

/**
 * Autorização de lembretes de retorno por WhatsApp na aba Contato do cadastro (LGPD, Fase 6) — componente
 * compartilhado por Pacientes e Agenda (PatientFormSections). `null` = o formulário não tocou (o servidor não mexe);
 * marcar manda true, desmarcar manda false. Só faz sentido com o celular marcado como WhatsApp.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            t_ui: {
                patient_form: {
                    care_reminders: 'Autoriza lembretes de retorno por WhatsApp',
                    care_reminders_hint: 'O lembrete não cita o motivo do acompanhamento.',
                    care_reminders_needs_whatsapp: 'Marque que o celular tem WhatsApp para registrar esta autorização.',
                },
            },
        },
    }),
}));

const PatientFormSections = (await import('@/Pages/Panel/Patients/PatientFormSections.vue')).default;

function mountContact(overrides = {}) {
    const form = reactive({
        cellphone: '11988887777',
        telephone: '',
        whatsapp: true,
        care_reminders_consent: null,
        name: '',
        occupation: '',
        mother_name: '',
        father_name: '',
        covenant_id: '',
        card_number: '',
        errors: {},
        ...overrides,
    });

    const wrapper = mount(PatientFormSections, {
        props: { form, section: 'contact' },
        global: { stubs: { SearchSelect: true }, directives: { mask: {} } },
    });

    return { wrapper, form };
}

const box = (wrapper) => wrapper.find('#chkCareReminders');

describe('Cadastro de paciente — lembretes de retorno por WhatsApp', () => {
    it('a caixa nasce desmarcada quando o formulário não tocou (nulo) e o rótulo é o traduzido', () => {
        const { wrapper } = mountContact();

        expect(box(wrapper).element.checked).toBe(false);
        expect(wrapper.find('label[for="chkCareReminders"]').text()).toBe('Autoriza lembretes de retorno por WhatsApp');
        expect(wrapper.find('#chkCareRemindersHint').text()).toContain('não cita o motivo');
    });

    it('marcar grava true; desmarcar grava false (revogar é um ato)', async () => {
        const { wrapper, form } = mountContact();

        await box(wrapper).setValue(true);
        expect(form.care_reminders_consent).toBe(true);

        await box(wrapper).setValue(false);
        expect(form.care_reminders_consent).toBe(false);
    });

    it('na edição parte do estado atual do paciente', () => {
        expect(box(mountContact({ care_reminders_consent: true }).wrapper).element.checked).toBe(true);
        expect(box(mountContact({ care_reminders_consent: false }).wrapper).element.checked).toBe(false);
    });

    it('sem a marca de WhatsApp no celular a caixa fica travada e a dica explica', () => {
        const { wrapper } = mountContact({ whatsapp: false });

        expect(box(wrapper).attributes('disabled')).toBeDefined();
        expect(wrapper.find('#chkCareRemindersHint').text()).toContain('Marque que o celular tem WhatsApp');
    });

    it('a dica é ligada à caixa (acessível) e o erro do servidor aparece em alerta', () => {
        const { wrapper } = mountContact({
            errors: { care_reminders_consent: 'O campo precisa ser verdadeiro ou falso.' },
        });

        expect(box(wrapper).attributes('aria-describedby')).toBe('chkCareRemindersHint');
        expect(wrapper.find('[role="alert"]').text()).toContain('verdadeiro ou falso');
    });
});
