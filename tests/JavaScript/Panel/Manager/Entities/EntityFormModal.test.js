import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import maskDirective from '@/directives/mask.js';
import EntityFormModal from '@/Pages/Panel/Manager/Entities/EntityFormModal.vue';

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
                clearErrors: () => { form.errors = {}; },
                post: vi.fn(),
                put: vi.fn(),
            });

            return form;
        },
    };
});

/**
 * Telefones e CEP da clínica usam v-mask. O CNPJ/CPF da clínica fica SEM
 * máscara de propósito: os formatadores só aceitam dígitos e apagariam as
 * letras de um CNPJ alfanumérico (IN RFB 2.229/2024), que o Entity model
 * preserva.
 */
function mockEntity(overrides = {}) {
    globalThis.fetch = vi.fn(() =>
        Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                data: {
                    name: 'CLINICA TESTE',
                    telephone: '6133334444',
                    cellphone: '61999998888',
                    national_registration: '12ABC345000195',
                    zipcode: '01310100',
                    ...overrides,
                },
            }),
        }),
    );
}

async function mountOpenModal(entityId = null) {
    const wrapper = mount(EntityFormModal, {
        props: { open: false, entityId, t: {} },
        attachTo: document.body,
        global: {
            directives: { mask: maskDirective },
            stubs: { teleport: true, SearchSelect: true, ConfirmationWithReasonModal: true },
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

describe('EntityFormModal — máscaras de telefone e CEP', () => {
    it('mascara telefone e celular enquanto digita, com teclado numérico e sem autofill', async () => {
        const wrapper = await mountOpenModal();

        const telephone = byPlaceholder(wrapper, '(00) 0000-0000');
        const cellphone = byPlaceholder(wrapper, '(00) 00000-0000');

        await type(telephone, '6133334444');
        await type(cellphone, '61999998888');

        expect(telephone.element.value).toBe('(61) 3333-4444');
        expect(cellphone.element.value).toBe('(61) 99999-8888');
        [telephone, cellphone].forEach((input) => {
            expect(input.attributes('inputmode')).toBe('numeric');
            expect(input.attributes('autocomplete')).toBeUndefined();
        });
        wrapper.unmount();
    });

    it('exibe telefones, CEP e CNPJ alfanumérico do banco formatados', async () => {
        mockEntity();
        const wrapper = await mountOpenModal('ent-1');

        expect(byPlaceholder(wrapper, '(00) 0000-0000').element.value).toBe('(61) 3333-4444');
        expect(byPlaceholder(wrapper, '(00) 00000-0000').element.value).toBe('(61) 99999-8888');
        // CEP: único input numérico sem placeholder de telefone (t = {} no teste) e sem maxlength
        const cep = wrapper.findAll('input[inputmode="numeric"]').find(i => !i.attributes('placeholder'));
        expect(cep.attributes('maxlength')).toBeUndefined();
        expect(cep.element.value).toBe('01310-100');
        expect(wrapper.find('input[autocapitalize="characters"]').element.value).toBe('12.ABC.345/0001-95');
        wrapper.unmount();
    });

    it.each(['+55 (61) 99999-8888', '5561999998888'])(
        'telefone legado com DDI (%s) não é reescrito sem edição — o EntityRequest descarta o 55',
        async (legacy) => {
            mockEntity({ telephone: legacy, cellphone: legacy });
            const wrapper = await mountOpenModal('ent-1');

            expect(wrapper.vm.form.telephone).toBe(legacy);
            expect(wrapper.vm.form.cellphone).toBe(legacy);
            wrapper.unmount();
        },
    );

    it('exibe sem o DDI o telefone legado gravado com +55', async () => {
        mockEntity({ cellphone: '+55 (61) 99999-8888' });
        const wrapper = await mountOpenModal('ent-1');

        expect(byPlaceholder(wrapper, '(00) 00000-0000').element.value).toBe('(61) 99999-8888');
        wrapper.unmount();
    });

    it('mascara o CNPJ/CPF da clínica aceitando letras do CNPJ alfanumérico (teclado de texto)', async () => {
        const wrapper = await mountOpenModal();
        const registration = wrapper.find('input[autocapitalize="characters"]');

        expect(registration.attributes('inputmode')).toBeUndefined();

        await type(registration, '12abc345000195');

        expect(registration.element.value).toBe('12.ABC.345/0001-95');
        expect(wrapper.vm.form.national_registration).toBe('12.ABC.345/0001-95');
        wrapper.unmount();
    });

    it('mascara CPF quando a clínica é pessoa física', async () => {
        const wrapper = await mountOpenModal();
        const registration = wrapper.find('input[autocapitalize="characters"]');

        await type(registration, '12345678901');

        expect(registration.element.value).toBe('123.456.789-01');
        wrapper.unmount();
    });
});
