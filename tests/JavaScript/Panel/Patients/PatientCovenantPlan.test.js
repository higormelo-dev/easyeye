import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import axios from 'axios';

/**
 * Plano do convênio na aba Clínico do cadastro de paciente (componente
 * compartilhado por Pacientes e Agenda): depende do convênio, busca no
 * servidor, limpa ao trocar de convênio e não se aplica a Particular.
 */
vi.mock('axios', () => ({ default: { get: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            t_ui: {
                patient_form: {
                    plan: 'Plan',
                    plan_placeholder: 'Search by name or ANS registry',
                    plan_select_covenant: 'Choose the insurer first',
                    plan_particular: 'Not applicable to self-pay',
                    plan_empty: 'No plans for this insurer',
                    plan_no_results: 'No plans found',
                    plan_none: 'No plans registered for this insurer.',
                    plan_unavailable: 'This plan is no longer available.',
                    plan_hint: 'Optional.',
                },
            },
        },
    }),
}));

// Stub do SearchSelect: expõe as props e deixa o teste simular a escolha.
const SearchSelectStub = {
    props: [
        'modelValue',
        'options',
        'labelKey',
        'disabled',
        'placeholder',
        'remoteSearchUrl',
        'noResultsText',
        'invalid',
    ],
    emits: ['update:modelValue', 'change', 'option-selected'],
    template: `<div class="ss" :data-label-key="labelKey ?? 'name'" :data-disabled="String(!!disabled)"
        :data-placeholder="placeholder" :data-remote="remoteSearchUrl" :data-invalid="String(!!invalid)"
        :data-options="(options ?? []).map((o) => o.id).join(',')">
        <button class="pick" @click="$emit('update:modelValue', options[0]?.id); $emit('change', options[0]?.id); $emit('option-selected', options[0])" />
    </div>`,
};

const PatientFormSections = (await import('@/Pages/Panel/Patients/PatientFormSections.vue')).default;

const covenants = [
    { id: 'amil', name: 'AMIL' },
    { id: 'part', name: 'PARTICULAR' },
    { id: 'brad', name: 'BRADESCO' },
];
const seed = [
    {
        id: 'p1',
        label: 'AMIL ONE S6500 (Reg. ANS 471234567)',
        sub_label: 'Coletivo empresarial · Coparticipação',
        active: true,
    },
    { id: 'p2', label: 'AMIL S380 (Reg. ANS 472000001)', sub_label: 'Individual ou familiar', active: true },
];

function mountClinical({ covenantId = '', planId = '', planOption = null, errors = {} } = {}) {
    const form = reactive({ covenant_id: covenantId, covenant_plan_id: planId, card_number: '123', errors });
    const wrapper = mount(PatientFormSections, {
        props: { form, section: 'clinical', covenants, planOption },
        global: { stubs: { SearchSelect: SearchSelectStub }, directives: { mask: {} } },
    });

    return { wrapper, form };
}

const planSelect = (wrapper) => wrapper.find('.patient-plan-field .ss');
const covenantSelect = (wrapper) =>
    wrapper.findAll('.ss').find((s) => s.attributes('data-options') === 'amil,part,brad');

describe('Cadastro de paciente — plano do convênio', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axios.get.mockResolvedValue({ data: { data: seed } });
    });

    it('sem convênio o plano fica desabilitado e nada é buscado', async () => {
        const { wrapper } = mountClinical();
        await flushPromises();

        expect(wrapper.find('.patient-plan-field label').text()).toBe('Plan');
        expect(planSelect(wrapper).attributes('data-disabled')).toBe('true');
        expect(planSelect(wrapper).attributes('data-placeholder')).toBe('Choose the insurer first');
        expect(axios.get).not.toHaveBeenCalled();
    });

    it('com convênio carrega os primeiros planos e busca no servidor ao digitar', async () => {
        const { wrapper } = mountClinical({ covenantId: 'amil' });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('covenant-plans.search'), {
            params: { covenant_id: 'amil' },
        });
        const select = planSelect(wrapper);
        expect(select.attributes('data-disabled')).toBe('false');
        expect(select.attributes('data-label-key')).toBe('label');
        expect(select.attributes('data-options')).toBe('p1,p2');
        expect(select.attributes('data-remote')).toContain('covenant_id=amil');
        expect(select.attributes('data-remote')).toContain('__Q__');
    });

    it('escolher o plano grava no formulário e mostra contratação/coparticipação', async () => {
        const { wrapper, form } = mountClinical({ covenantId: 'amil' });
        await flushPromises();

        await planSelect(wrapper).find('.pick').trigger('click');

        expect(form.covenant_plan_id).toBe('p1');
        expect(wrapper.find('.patient-plan-details').text()).toBe('Coletivo empresarial · Coparticipação');
    });

    it('edição: plano salvo entra na lista mesmo fora dos primeiros; indisponível mostra aviso', async () => {
        const saved = {
            id: 'old',
            label: 'AMIL VELHO (Reg. ANS 470000009)',
            sub_label: 'Coletivo por adesão',
            active: false,
        };
        const { wrapper } = mountClinical({ covenantId: 'amil', planId: 'old', planOption: saved });
        await flushPromises();

        expect(planSelect(wrapper).attributes('data-options')).toBe('old,p1,p2');
        expect(wrapper.text()).toContain('Coletivo por adesão');
        expect(wrapper.text()).toContain('This plan is no longer available.');
    });

    it('trocar o convênio na tela limpa o plano (cada plano é de um convênio)', async () => {
        const { wrapper, form } = mountClinical({ covenantId: 'amil', planId: 'p1' });
        await flushPromises();

        await covenantSelect(wrapper).find('.pick').trigger('click');

        expect(form.covenant_plan_id).toBe('');
    });

    it('Particular: plano e carteirinha não se aplicam', async () => {
        const { wrapper, form } = mountClinical({ covenantId: 'amil', planId: 'p1' });
        await flushPromises();

        form.covenant_id = 'part';
        await flushPromises();

        expect(form.covenant_plan_id).toBe('');
        expect(form.card_number).toBe('');
        expect(planSelect(wrapper).attributes('data-disabled')).toBe('true');
        expect(planSelect(wrapper).attributes('data-placeholder')).toBe('Not applicable to self-pay');
    });

    it('convênio sem planos cadastrados mostra a orientação', async () => {
        axios.get.mockResolvedValue({ data: { data: [] } });
        const { wrapper } = mountClinical({ covenantId: 'brad' });
        await flushPromises();

        expect(wrapper.find('.patient-plan-none').text()).toBe('No plans registered for this insurer.');
    });

    it('erro do servidor aparece no campo', async () => {
        const { wrapper } = mountClinical({
            covenantId: 'amil',
            errors: { covenant_plan_id: 'Este plano não é do convênio escolhido.' },
        });
        await flushPromises();

        expect(planSelect(wrapper).attributes('data-invalid')).toBe('true');
        expect(wrapper.find('.patient-plan-field').text()).toContain('Este plano não é do convênio escolhido.');
    });
});
