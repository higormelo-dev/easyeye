import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import PatientFormModal from '@/Pages/Panel/Patients/PatientFormModal.vue';
vi.mock('@inertiajs/vue3', () => ({
    router: { on: () => () => {} },
    usePage: () => ({
        props: {
            t_ui: { form_errors_summary: 'Fix these fields', discard_changes: 'Discard edits?', patient_form: {} },
        },
    }),
}));
vi.mock('@/Support/usePatientForm.js', async () => {
    const { reactive, ref, computed } = await import('vue');
    return {
        usePatientForm: () => {
            const form = reactive({
                name: '',
                nickname: '',
                national_registry: '',
                birth_date: '',
                gender: '',
                marital_status: '',
                email: '',
                mother_name: '',
                father_name: '',
                occupation: '',
                covenant_id: '',
                covenant_plan_id: '',
                state: '',
                cellphone: '',
                zipcode: '',
                telephone: '',
                address: '',
                city: '',
                country: '',
                medications: [],
                allergies: [],
                errors: {},
                processing: false,
                isDirty: false,
                post: vi.fn(),
                put: vi.fn(),
            });
            return {
                form,
                loading: ref(false),
                planOption: ref(null),
                covenantOption: ref(null),
                resetForm: () => {
                    form.errors = {};
                    form.isDirty = false;
                },
                loadEditData: vi.fn(),
                savePatient: vi.fn(async () => ({ ok: false })),
                lookupCep: vi.fn(),
                genderOptions: ref([]),
                maritalOptions: ref([]),
                stateOptions: ref([]),
                tabHasErrors: computed(() => ({})),
                tabIncomplete: computed(() => ({})),
            };
        },
    };
});
let wrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.restoreAllMocks();
});
async function render(embedded = false) {
    wrapper = mount(PatientFormModal, {
        props: { open: false, embedded },
        attachTo: document.body,
        global: {
            directives: { mask: {} },
            stubs: { teleport: true, PatientAllergiesPanel: true, PatientMedicationsPanel: true },
        },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
}
describe('Paciente — foco e erros de formulário', () => {
    it.each([false, true])('submit embedded=%s abre endereço, foca campo e associa erros', async (embedded) => {
        await render(embedded);
        wrapper.vm.form.errors = { city: 'Cidade inválida', cellphone: 'Celular inválido' };
        // Contato vem antes de Endereço na ordem do formulário.
        await wrapper.vm.submit();
        if (!embedded) await wrapper.vm.form.post.mock.calls[0][1].onError();
        await flushPromises();
        expect(wrapper.vm.activeTab).toBe('contact');
        const input = wrapper.get('input[placeholder="(00) 00000-0000"]');
        expect(document.activeElement).toBe(input.element);
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(wrapper.get(`#${input.attributes('aria-describedby')}`).text()).toBe('Celular inválido');
        expect(wrapper.get('[data-testid="form-error-summary"]').text()).toContain('Cidade inválida');
    });
    it('erro global de homônimo não tira foco para campo sem erro nem entra no resumo', async () => {
        await render(true);
        const first = wrapper.get('input');
        first.element.focus();
        wrapper.vm.form.errors = { confirm_homonym: 'Confirme a identidade' };
        await wrapper.vm.submit();
        await flushPromises();
        expect(wrapper.find('[data-testid="form-error-summary"]').exists()).toBe(false);
        expect(document.activeElement).toBe(first.element);
        expect(wrapper.vm.activeTab).toBe('personal');
        wrapper.vm.form.errors = { city: 'Cidade inválida' };
        await wrapper.vm.submit();
        await flushPromises();
        expect(wrapper.vm.activeTab).toBe('address');
    });
    it('Cancelar com formulário sujo usa a mesma confirmação do X e preserva valores', async () => {
        await render();
        const confirm = (window.confirm = vi.fn(() => false));
        wrapper.vm.form.name = 'Sintético';
        wrapper.vm.form.isDirty = true;
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Cancelar')
            .trigger('click');
        await wrapper.get('.btn-close').trigger('click');
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(wrapper.emitted('close')).toBeUndefined();
        expect(wrapper.vm.form.name).toBe('Sintético');
        confirm.mockReturnValue(true);
        await wrapper.get('.btn-close').trigger('click');
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
