import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import maskDirective from '@/directives/mask.js';
import DoctorFormModal from '@/Pages/Panel/Doctors/DoctorFormModal.vue';

// O mock global de useForm (setup.js) não é reativo nem tem clearErrors();
// aqui o v-model precisa re-renderizar para a diretiva formatar.
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        router: { reload: vi.fn() },
        // OffcanvasPanel lê textos compartilhados (t_ui) da página.
        usePage: () => ({ props: {} }),
        useForm: (data) => {
            const initial = { ...data };
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                reset: () => Object.assign(form, initial),
                clearErrors: () => {
                    form.errors = {};
                },
                post: vi.fn(),
                put: vi.fn(),
            });

            return form;
        },
    };
});

/**
 * CPF, celular, telefone e CEP do médico usam v-mask (registrada globalmente
 * no panel.js). O DoctorRequest normaliza para só dígitos; aqui garantimos que
 * a tela mascara o que é digitado e exibe formatado o que vem do banco.
 */
function mockEditData(overrides = {}) {
    globalThis.fetch = vi.fn(() =>
        Promise.resolve({
            ok: true,
            json: () =>
                Promise.resolve({
                    data: {
                        name: 'DR TESTE',
                        national_registry: '12345678909',
                        telephone: '6133334444',
                        cellphone: '61999998888',
                        zipcode: '01310100',
                        ...overrides,
                    },
                }),
        }),
    );
}

async function mountOpenModal(doctorId = null) {
    const wrapper = mount(DoctorFormModal, {
        props: { open: false, doctorId },
        attachTo: document.body,
        global: {
            directives: { mask: maskDirective },
            stubs: { teleport: true, SearchSelect: true },
        },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
    await nextTick();
    return wrapper;
}

const byPlaceholder = (wrapper, placeholder) => wrapper.find(`input[placeholder="${placeholder}"]`);

async function type(input, value) {
    input.element.value = value;
    input.element.setSelectionRange(value.length, value.length);
    await input.trigger('input');
}

describe('DoctorFormModal — máscaras de CPF e telefones', () => {
    it('mascara CPF, celular e telefone enquanto digita, com teclado numérico', async () => {
        const wrapper = await mountOpenModal();

        const cpf = byPlaceholder(wrapper, '000.000.000-00');
        const cellphone = byPlaceholder(wrapper, '(00) 00000-0000');
        const telephone = byPlaceholder(wrapper, '(00) 0000-0000');

        await type(cpf, '12345678909');
        await type(cellphone, '61999998888');
        await type(telephone, '6133334444');

        expect(cpf.element.value).toBe('123.456.789-09');
        expect(cellphone.element.value).toBe('(61) 99999-8888');
        expect(telephone.element.value).toBe('(61) 3333-4444');
        [cpf, cellphone, telephone].forEach((input) => {
            expect(input.attributes('inputmode')).toBe('numeric');
            expect(input.attributes('autocomplete')).toBeUndefined();
        });
        wrapper.unmount();
    });

    it('exibe formatados CPF, telefones e CEP que vêm do banco só com dígitos', async () => {
        mockEditData();
        const wrapper = await mountOpenModal('doc-1');

        expect(byPlaceholder(wrapper, '000.000.000-00').element.value).toBe('123.456.789-09');
        expect(byPlaceholder(wrapper, '(00) 00000-0000').element.value).toBe('(61) 99999-8888');
        expect(byPlaceholder(wrapper, '(00) 0000-0000').element.value).toBe('(61) 3333-4444');
        expect(byPlaceholder(wrapper, '00000-000').element.value).toBe('01310-100');
        wrapper.unmount();
    });

    it.each(['+55 (61) 99999-8888', '5561999998888'])(
        'telefone legado com DDI (%s) não é reescrito sem edição — o DoctorRequest descarta o 55',
        async (legacy) => {
            mockEditData({ telephone: legacy, cellphone: legacy });
            const wrapper = await mountOpenModal('doc-1');

            expect(wrapper.vm.form.telephone).toBe(legacy);
            expect(wrapper.vm.form.cellphone).toBe(legacy);
            wrapper.unmount();
        },
    );

    it('exibe sem o DDI o telefone legado gravado com +55', async () => {
        mockEditData({ cellphone: '+55 (61) 99999-8888' });
        const wrapper = await mountOpenModal('doc-1');

        expect(byPlaceholder(wrapper, '(00) 00000-0000').element.value).toBe('(61) 99999-8888');
        wrapper.unmount();
    });

    it('CEP não oferece autofill do endereço do usuário logado', async () => {
        const wrapper = await mountOpenModal();

        expect(byPlaceholder(wrapper, '00000-000').attributes('autocomplete')).toBeUndefined();
        wrapper.unmount();
    });
});
