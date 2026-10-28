import { describe, it, expect, vi } from 'vitest';
import { defineComponent, ref } from 'vue';
import { mount } from '@vue/test-utils';
import { useEscapeToClose } from '@/Support/useEscapeToClose.js';

/**
 * @keydown.escape.window não é modificador válido no Vue 3 (vue/valid-v-on)
 * — o handler ficava preso ao elemento local; o gatilho que abre o modal
 * fica FORA dele (Teleport pro body), então Esc só funcionava se o usuário
 * já tivesse clicado dentro do modal antes. Achado via auditoria de
 * encerramento, EE-13 (lint) — 4 modais com o mesmo engano.
 */
function pressEscape() {
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
}

function mountWithEscape(initialOpen = true) {
    const onEscape = vi.fn();
    const isOpen = ref(initialOpen);

    const wrapper = mount(
        defineComponent({
            setup() {
                useEscapeToClose(() => isOpen.value, onEscape);

                return {};
            },
            template: '<div />',
        }),
    );

    return { wrapper, isOpen, onEscape };
}

describe('useEscapeToClose', () => {
    it('[GAP] Esc fecha mesmo com o foco em QUALQUER lugar da página — nunca precisa estar dentro do modal', () => {
        const { onEscape } = mountWithEscape(true);

        // document.activeElement aqui é <body> — nunca um elemento do
        // modal. Com @keydown.escape.window (modificador inválido), o
        // handler nem existia de verdade nesse cenário.
        pressEscape();

        expect(onEscape).toHaveBeenCalledTimes(1);
    });

    it('não reage ao Esc quando o modal está fechado', () => {
        const { onEscape } = mountWithEscape(false);

        pressEscape();

        expect(onEscape).not.toHaveBeenCalled();
    });

    it('para de reagir depois que o modal fecha (sem listener pendurado)', async () => {
        const { isOpen, onEscape } = mountWithEscape(true);

        isOpen.value = false;
        await Promise.resolve(); // watch() roda no próximo tick

        pressEscape();

        expect(onEscape).not.toHaveBeenCalled();
    });

    it('[GAP] remove o listener do window ao desmontar — não acumula entre aberturas de modal', () => {
        const { wrapper, onEscape } = mountWithEscape(true);

        wrapper.unmount();
        pressEscape();

        expect(onEscape).not.toHaveBeenCalled();
    });

    it('ignora outras teclas', () => {
        const { onEscape } = mountWithEscape(true);

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));

        expect(onEscape).not.toHaveBeenCalled();
    });
});
