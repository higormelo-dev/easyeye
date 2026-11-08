import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive, nextTick } from 'vue';

/**
 * Responsável legal (LGPD art. 14) no cadastro de paciente — componente
 * compartilhado por Pacientes e Agenda. Opcional: aparece para menor de 18 anos
 * (ou quando já há valor salvo, para poder corrigir/apagar), não bloqueia nada e
 * não existe caixa de "consentimento do responsável" (o atendimento não depende
 * de consentimento — art. 11, II, f).
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            t_ui: {
                patient_form: {
                    legal_guardian: 'Legal guardian name',
                    legal_guardian_hint: 'Not required for care.',
                    optional: '(optional)',
                    care_reminders: 'Authorises reminders',
                    care_reminders_hint: 'Reply STOP to stop.',
                    care_reminders_needs_whatsapp: 'Needs WhatsApp.',
                    care_reminders_minor: 'Guardian must authorise.',
                },
            },
        },
    }),
}));

const PatientFormSections = (await import('@/Pages/Panel/Patients/PatientFormSections.vue')).default;

function mountSection(section, overrides = {}) {
    const form = reactive({
        name: '',
        birth_date: '',
        mother_name: '',
        father_name: '',
        legal_guardian_name: '',
        occupation: '',
        covenant_id: '',
        card_number: '',
        whatsapp: true,
        care_reminders_consent: null,
        errors: {},
        ...overrides,
    });

    const wrapper = mount(PatientFormSections, {
        props: { form, section },
        global: { stubs: { SearchSelect: true }, directives: { mask: {} } },
    });

    return { wrapper, form };
}

const guardian = (wrapper) => wrapper.find('[data-testid="legal-guardian-name"]');

describe('Cadastro de paciente — responsável legal', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-07T12:00:00'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('menor de 18 anos: mostra o campo opcional, sem asterisco nem caixa de consentimento', async () => {
        const { wrapper, form } = mountSection('personal', { birth_date: '2015-05-01' });

        expect(guardian(wrapper).exists()).toBe(true);
        expect(guardian(wrapper).attributes('aria-required')).toBeUndefined();
        expect(wrapper.text()).toContain('(optional)');
        expect(wrapper.find('[id*="minor_guardian_consent"]').exists()).toBe(false);

        await guardian(wrapper).setValue('Maria Responsável');
        expect(form.legal_guardian_name).toBe('Maria Responsável');
    });

    it('adulto sem valor salvo: o campo não aparece', () => {
        const { wrapper } = mountSection('personal', { birth_date: '1990-01-01' });

        expect(guardian(wrapper).exists()).toBe(false);
    });

    it('fez 18 anos mas há responsável salvo: o campo continua visível para corrigir ou apagar', async () => {
        const { wrapper, form } = mountSection('personal', {
            birth_date: '2008-11-07',
            legal_guardian_name: 'MARIA',
        });

        expect(guardian(wrapper).exists()).toBe(true);

        form.legal_guardian_name = '';
        await nextTick();
        expect(guardian(wrapper).exists()).toBe(false);
    });

    it('apagar o último caractere com o campo em foco não o tira da tela; some só ao sair do campo', async () => {
        const { wrapper } = mountSection('personal', { birth_date: '1990-01-01', legal_guardian_name: 'M' });

        await guardian(wrapper).trigger('focus');
        await guardian(wrapper).setValue('');
        expect(guardian(wrapper).exists()).toBe(true);

        await guardian(wrapper).trigger('blur');
        expect(guardian(wrapper).exists()).toBe(false);
    });

    it('menor: o aviso dos lembretes por WhatsApp diz que a autorização é do responsável', () => {
        const minor = mountSection('contact', { birth_date: '2015-05-01' }).wrapper;
        const adult = mountSection('contact', { birth_date: '1990-01-01' }).wrapper;

        expect(minor.find('#chkCareRemindersHint').text()).toContain('Guardian must authorise.');
        expect(adult.find('#chkCareRemindersHint').text()).not.toContain('Guardian must authorise.');
    });
});
