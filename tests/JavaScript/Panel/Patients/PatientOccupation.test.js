import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';

/**
 * Profissão na aba Pessoal do cadastro de paciente — componente compartilhado
 * por Pacientes e Agenda (PatientFormSections). Rótulo traduzido via t_ui.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { t_ui: { patient_form: { occupation: 'Occupation', occupation_placeholder: 'E.g.: teacher' } } },
    }),
}));

const PatientFormSections = (await import('@/Pages/Panel/Patients/PatientFormSections.vue')).default;

function mountSection(errors = {}) {
    const form = reactive({
        name: '',
        occupation: '',
        mother_name: '',
        father_name: '',
        covenant_id: '',
        card_number: '',
        errors,
    });

    const wrapper = mount(PatientFormSections, {
        props: { form, section: 'personal' },
        global: { stubs: { SearchSelect: true }, directives: { mask: {} } },
    });

    return { wrapper, form };
}

describe('Cadastro de paciente — profissão', () => {
    it('campo na aba Pessoal com rótulo e placeholder traduzidos, ligado ao formulário', async () => {
        const { wrapper, form } = mountSection();

        expect(wrapper.find('label[for="patient-occupation"]').text()).toBe('Occupation');
        const input = wrapper.find('#patient-occupation');
        expect(input.attributes('placeholder')).toBe('E.g.: teacher');
        expect(input.attributes('maxlength')).toBe('120');

        await input.setValue('professora');
        expect(form.occupation).toBe('professora');
    });

    it('mostra o erro do servidor associado ao campo (acessível)', () => {
        const { wrapper } = mountSection({ occupation: 'O campo profissão não pode ser superior a 120 caracteres.' });

        const input = wrapper.find('#patient-occupation');
        expect(input.classes()).toContain('is-invalid');
        expect(input.attributes('aria-describedby')).toBe('patient-occupation-error');
        expect(wrapper.find('#patient-occupation-error').text()).toContain('profissão');
    });
});
