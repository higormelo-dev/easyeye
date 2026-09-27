import { describe, it, expect, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import maskDirective from '@/directives/mask.js';
import SupplierFormModal from '@/Pages/Panel/Stock/Suppliers/SupplierFormModal.vue';

/**
 * Fornecedor: CNPJ/CPF e telefone com v-mask. O backend grava só dígitos
 * (SupplierRequest) — ao editar, o valor cru precisa aparecer formatado.
 */

// O mock global de useForm (setup.js) não é reativo nem tem clearErrors();
// aqui o v-model precisa re-renderizar para a diretiva formatar.
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
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

const routes = { store: '/suppliers', update: '/suppliers/__ID__' };

const OffcanvasStub = {
    props: ['open'],
    template: '<div><slot name="header" /><slot /><slot name="footer" /></div>',
};

let wrapper;

async function mountModal(item = null) {
    wrapper = mount(SupplierFormModal, {
        props: { open: false, item, routes },
        attachTo: document.body,
        global: {
            directives: { mask: maskDirective },
            stubs: { OffcanvasPanel: OffcanvasStub },
        },
    });

    // O watcher de `open` é que preenche o form a partir do item.
    await wrapper.setProps({ open: true });
    await flushPromises();
    await nextTick();

    return wrapper;
}

function inputs(w) {
    const [, document, phone] = w.findAll('input[type="text"]');

    return { document: document.element, phone: phone.element };
}

async function type(input, value) {
    input.focus();
    input.value = value;
    input.setSelectionRange(value.length, value.length);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();
}

afterEach(() => wrapper?.unmount());

describe('SupplierFormModal — máscaras', () => {
    it('exibe formatados o CNPJ e o telefone gravados só com dígitos', async () => {
        const w = await mountModal({ id: '1', name: 'Fornecedor', document: '12345678000199', phone: '6133334444' });
        const { document, phone } = inputs(w);

        expect(document.value).toBe('12.345.678/0001-99');
        expect(phone.value).toBe('(61) 3333-4444');
    });

    it('aplica a máscara de CPF/CNPJ e de celular enquanto digita', async () => {
        const w = await mountModal();
        const { document, phone } = inputs(w);

        await type(document, '12345678901');
        expect(document.value).toBe('123.456.789-01');

        await type(document, '12345678000199');
        expect(document.value).toBe('12.345.678/0001-99');

        await type(phone, '61999998888');
        expect(phone.value).toBe('(61) 99999-8888');
    });

    it('ao digitar, o v-model recebe o valor formatado (é o que vai para o backend)', async () => {
        const w = await mountModal();
        const { document, phone } = inputs(w);

        await type(document, '12345678000199');
        await type(phone, '61999998888');

        expect(w.vm.form.document).toBe('12.345.678/0001-99');
        expect(w.vm.form.phone).toBe('(61) 99999-8888');
    });

    it('ao só exibir na edição, o form mantém o valor cru gravado (inclusive legado em texto livre)', async () => {
        // O SupplierRequest compara o phone enviado com o gravado para não
        // reescrever legado que o usuário não tocou — depende deste contrato.
        const w = await mountModal({ id: '1', name: 'Fornecedor', document: '12345678000199', phone: '(61) 3333-4444 r.21' });

        expect(w.vm.form.document).toBe('12345678000199');
        expect(w.vm.form.phone).toBe('(61) 3333-4444 r.21');
    });

    it('telefone abre teclado numérico; documento abre teclado de texto (CNPJ alfanumérico); nenhum corta colagem com maxlength', async () => {
        const w = await mountModal();
        const { document, phone } = inputs(w);

        expect(phone.getAttribute('inputmode')).toBe('numeric');
        expect(document.hasAttribute('inputmode')).toBe(false);
        expect(document.getAttribute('autocapitalize')).toBe('characters');

        for (const input of [document, phone]) {
            expect(input.hasAttribute('maxlength')).toBe(false);
        }
    });
});
