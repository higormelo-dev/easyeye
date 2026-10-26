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
            let initial = { ...data };
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                data: () => Object.fromEntries(Object.keys(data).map((key) => [key, form[key]])),
                defaults: (next) => {
                    initial = { ...next };
                },
                get isDirty() {
                    return Object.keys(data).some((key) => form[key] !== initial[key]);
                },
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

describe('DoctorFormModal — acessibilidade e recuperação', () => {
    it('edição carregada define baseline limpo e reabrir criação não reutiliza defaults antigos', async () => {
        mockEditData();
        const wrapper = await mountOpenModal('doc-1');
        expect(wrapper.vm.form.isDirty).toBe(false);
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true, doctorId: null });
        await flushPromises();
        expect(wrapper.vm.form.name).toBe('');
        expect(wrapper.vm.form.isDirty).toBe(false);
        wrapper.unmount();
    });
    it('500 não expõe formulário vazio; retry carrega e limpa o estado', async () => {
        const json = vi.fn();
        globalThis.fetch = vi
            .fn()
            .mockResolvedValueOnce({ ok: false, json })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ data: { name: 'Sintético' } }) });
        const wrapper = await mountOpenModal('doc-1');
        expect(json).not.toHaveBeenCalled();
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.get('[data-testid="doctor-load-error"]').text()).toContain('Não foi possível carregar');
        // O alerta também é região de densidade padrão: o botão de repetir não carrega medida local.
        expect(wrapper.get('[role="dialog"]').attributes('data-ee-density')).toBe('default');
        expect(wrapper.get('[data-testid="doctor-load-error"] button').classes()).not.toContain('btn-sm');
        await wrapper.get('[data-testid="doctor-load-error"] button').trigger('click');
        await flushPromises();
        expect(wrapper.get('form').exists()).toBe(true);
        expect(wrapper.vm.form.name).toBe('Sintético');
        expect(wrapper.vm.form.isDirty).toBe(false);
        wrapper.unmount();
    });
    it('submit abre primeira aba com erro, associa mensagem e foca controle', async () => {
        const wrapper = await mountOpenModal();
        const form = wrapper.vm.form;
        await wrapper.vm.submit();
        form.errors = { record: 'CRM inválido', cellphone: 'Telefone inválido' };
        await form.post.mock.calls.at(-1)[1].onError();
        await flushPromises();
        expect(wrapper.vm.activeTab).toBe('doctor');
        const input = wrapper.get('#doctor-record');
        expect(document.activeElement).toBe(input.element);
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(wrapper.get(`#${input.attributes('aria-describedby')}`).text()).toBe('CRM inválido');
        expect(wrapper.get('[data-testid="form-error-summary"]').text()).toContain('Telefone inválido');
        wrapper.unmount();
    });
    it('labels e senhas são associados; toggle participa do teclado', async () => {
        const wrapper = await mountOpenModal();
        expect(wrapper.get('label[for="doctor-name"]').exists()).toBe(true);
        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Acesso'))
            .trigger('click');
        const button = wrapper.get('button[aria-label="Mostrar senha"]');
        expect(button.attributes('tabindex')).not.toBe('-1');
        await button.trigger('click');
        expect(button.attributes('aria-label')).toBe('Ocultar senha');
        expect(button.attributes('aria-pressed')).toBe('true');
        expect(wrapper.get('#doctor-password').attributes('type')).toBe('text');
        wrapper.unmount();
    });
    it('Cancelar e X respeitam veto; salvar fecha diretamente sem perguntar', async () => {
        const confirm = (window.confirm = vi.fn(() => false));
        const wrapper = await mountOpenModal();
        await wrapper.get('#doctor-name').setValue('Sintético');
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Cancelar')
            .trigger('click');
        await wrapper.get('.btn-close').trigger('click');
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(wrapper.emitted('close')).toBeUndefined();
        wrapper.vm.submit();
        wrapper.vm.form.post.mock.calls.at(-1)[1].onSuccess();
        expect(wrapper.emitted('close')).toHaveLength(1);
        expect(confirm).toHaveBeenCalledTimes(2);
        wrapper.unmount();
        confirm.mockRestore();
    });
    it('troca de médico aborta a carga e descarta resposta antiga', async () => {
        let resolve;
        globalThis.fetch = vi
            .fn()
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            )
            .mockResolvedValueOnce({ ok: true, json: async () => ({ data: { name: 'Novo sintético' } }) });
        const wrapper = await mountOpenModal('old');
        const signal = globalThis.fetch.mock.calls[0][1].signal;
        await wrapper.setProps({ doctorId: 'new' });
        await flushPromises();
        expect(signal.aborted).toBe(true);
        resolve({ ok: true, json: async () => ({ data: { name: 'ANTIGO' } }) });
        await flushPromises();
        expect(wrapper.vm.form.name).toBe('Novo sintético');
        wrapper.unmount();
    });
});

describe('DoctorFormModal — padrão de controles (design-system/MASTER.md)', () => {
    it('abre numa região de densidade padrão e o seletor de cor não carrega altura local', async () => {
        const wrapper = await mountOpenModal();

        expect(wrapper.get('[role="dialog"]').attributes('data-ee-density')).toBe('default');
        expect(wrapper.get('input[type="color"]').attributes('style')).toBeUndefined();
        wrapper.unmount();
    });

    it('o botão de buscar CEP só com ícone tem nome acessível', async () => {
        const wrapper = await mountOpenModal();
        const cep = wrapper.get('button.ee-action-icon');

        expect(cep.attributes('aria-label')).toBe('Buscar CEP');
        expect(cep.attributes('title')).toBe('Buscar CEP');
        wrapper.unmount();
    });
});
