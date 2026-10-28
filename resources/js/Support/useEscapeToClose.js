import { onBeforeUnmount, watch } from 'vue';

/**
 * Esc fecha o modal mesmo sem o foco estar dentro dele — achado via
 * auditoria de encerramento, EE-13 (ESLint vue/valid-v-on): 4 modais
 * usavam `@keydown.escape.window`, mas "window" não é modificador válido
 * no Vue 3. O listener ficava preso ao elemento local; como o gatilho que
 * abre o modal fica FORA dele (Teleport pro body), o Esc só funcionava se
 * o usuário já tivesse clicado dentro do modal antes — quebra o padrão
 * WAI-ARIA de diálogo (Esc fecha de qualquer lugar da página).
 *
 * @param {() => boolean} getIsOpen
 * @param {() => void} onEscape
 */
export function useEscapeToClose(getIsOpen, onEscape) {
    function handleKeydown(event) {
        if (event.key === 'Escape') onEscape();
    }

    watch(
        getIsOpen,
        (open) => {
            if (open) {
                window.addEventListener('keydown', handleKeydown);
            } else {
                window.removeEventListener('keydown', handleKeydown);
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));
}
