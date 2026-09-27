import { describe, it, expect } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, reactive, nextTick } from 'vue';
import maskDirective from '@/directives/mask.js';

function mountMasked(mask, initial = '') {
    const form = reactive({ value: initial });

    const Host = defineComponent({
        directives: { mask: maskDirective },
        setup: () => ({ form, mask }),
        template: '<input v-model="form.value" v-mask="mask" type="text">',
    });

    const wrapper = mount(Host, { attachTo: document.body });

    return { wrapper, form, input: wrapper.find('input').element };
}

async function type(input, value, caret = value.length) {
    input.focus();
    input.value = value;
    input.setSelectionRange(caret, caret);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();
}

describe('v-mask', () => {
    it('formata o que é digitado e o v-model recebe o valor formatado', async () => {
        const { wrapper, form, input } = mountMasked('cpf');

        await type(input, '12345678901');

        expect(input.value).toBe('123.456.789-01');
        expect(form.value).toBe('123.456.789-01');
        wrapper.unmount();
    });

    it('telefone muda de fixo para celular conforme a quantidade de dígitos', async () => {
        const { wrapper, form, input } = mountMasked('phone');

        await type(input, '6133334444');
        expect(form.value).toBe('(61) 3333-4444');

        await type(input, '(61) 3333-44445');
        expect(form.value).toBe('(61) 33334-4445');
        wrapper.unmount();
    });

    it('descarta letras mesmo quando o valor formatado não muda', async () => {
        const { wrapper, form, input } = mountMasked('cpf');

        await type(input, '123');
        await type(input, '123a');

        expect(input.value).toBe('123');
        expect(form.value).toBe('123');
        wrapper.unmount();
    });

    it('exibe formatado o valor que veio do servidor sem alterar o model (form não fica sujo)', async () => {
        const { wrapper, form, input } = mountMasked('cpf', '12345678901');
        await flushPromises();

        expect(input.value).toBe('123.456.789-01');
        expect(form.value).toBe('12345678901');
        wrapper.unmount();
    });

    it('reformata quando o model é trocado programaticamente (ex.: abrir edição de outro registro)', async () => {
        const { wrapper, form, input } = mountMasked('phone');
        await flushPromises();

        form.value = '61999998888';
        await nextTick();

        expect(input.value).toBe('(61) 99999-8888');
        wrapper.unmount();
    });

    it('limpa o campo quando o model é resetado', async () => {
        const { wrapper, form, input } = mountMasked('cpf', '12345678901');
        await flushPromises();

        form.value = '';
        await nextTick();

        expect(input.value).toBe('');
        wrapper.unmount();
    });

    it('preserva o cursor ao editar no meio do valor', async () => {
        const { wrapper, input } = mountMasked('cpf');
        await type(input, '123.456.789-01');

        // Backspace no "4" (cursor estava depois dele) → "123.56.789-01", cursor em 4
        await type(input, '123.56.789-01', 4);

        expect(input.value).toBe('123.567.890-1');
        expect(input.selectionStart).toBe(3); // logo após o 3º dígito
        wrapper.unmount();
    });

    it('CNPJ alfanumérico: aceita letras, converte para maiúsculas e posiciona o cursor pelas letras também', async () => {
        const { wrapper, form, input } = mountMasked('cpfCnpj');

        await type(input, '12abc');

        expect(form.value).toBe('12.ABC');
        expect(input.selectionStart).toBe(6);
        wrapper.unmount();
    });

    it('exibe telefone legado gravado com DDI sem truncar e sem sujar o form', async () => {
        const { wrapper, form, input } = mountMasked('phone', '5561999998888');
        await flushPromises();

        expect(input.value).toBe('(61) 99999-8888');
        expect(form.value).toBe('5561999998888');
        wrapper.unmount();
    });

    it.each(['(61) 3333-4444 r.21', '6133334444 / 61999998888', '33334444'])(
        'legado fora do padrão vindo do servidor (%s) aparece como está, igual ao PHP',
        async (legacy) => {
            const { wrapper, form, input } = mountMasked('phone', legacy);
            await flushPromises();

            expect(input.value).toBe(legacy);
            expect(form.value).toBe(legacy);
            wrapper.unmount();
        },
    );

    it('ignora input durante composição (IME) e formata no fim', async () => {
        const { wrapper, form, input } = mountMasked('cpf');

        input.focus();
        input.value = '123456';
        input.dispatchEvent(new InputEvent('input', { bubbles: true, isComposing: true }));
        await nextTick();
        expect(input.value).toBe('123456');

        input.dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();
        expect(form.value).toBe('123.456');
        wrapper.unmount();
    });

    it('máscara desconhecida não altera o valor', async () => {
        const { wrapper, form, input } = mountMasked('inexistente');

        await type(input, 'abc-123');

        expect(form.value).toBe('abc-123');
        wrapper.unmount();
    });
});
