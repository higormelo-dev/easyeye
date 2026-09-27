import { watch, nextTick, onBeforeUnmount } from 'vue';

const FOCUSABLE = [
    '[data-autofocus]',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'button:not([disabled])',
].join(', ');

/**
 * Teclado nos modais do Faturamento — OffcanvasPanel/CenteredModal ainda não
 * tratam Esc nem foco inicial. Ao abrir, foca `focusRef` (ou o primeiro campo
 * dentro dele); Esc chama `onEscape`, que decide se fecha (ex.: pedir
 * confirmação antes de descartar um formulário alterado).
 *
 * @param {() => boolean} isOpen
 * @param {{ onEscape: () => void, focusRef?: import('vue').Ref<HTMLElement|null> }} options
 */
export function useDialogKeyboard(isOpen, { onEscape, focusRef = null }) {
    function onKeydown(event) {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        onEscape();
    }

    async function focusFirst() {
        await nextTick();
        const root = focusRef?.value;
        if (!root) return;

        const target = typeof root.matches === 'function' && root.matches(FOCUSABLE)
            ? root
            : root.querySelector?.(FOCUSABLE);

        target?.focus?.();
    }

    watch(isOpen, (open) => {
        document.removeEventListener('keydown', onKeydown);

        if (open) {
            document.addEventListener('keydown', onKeydown);
            focusFirst();
        }
    }, { immediate: true });

    onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
}
