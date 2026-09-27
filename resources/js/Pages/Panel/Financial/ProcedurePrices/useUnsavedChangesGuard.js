import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Confirmação antes de sair da tela com alterações não salvas.
 *
 * - Navegação do Inertia (menu, links, logout…): `router.on('before')` pergunta
 *   com window.confirm (síncrono: a visita original segue intacta, com os
 *   callbacks dela, se o usuário confirmar).
 * - Recarregar/fechar a aba ou link comum (breadcrumb): `beforeunload`, com o
 *   diálogo nativo do navegador.
 * - `bypass(fn)`: visitas da própria tela (salvar, trocar de convênio já
 *   confirmada) passam sem perguntar — o Inertia dispara o `before` de forma
 *   síncrona dentro de router.get/post.
 * - Prefetch não é saída de página: ignorado.
 * Os dois listeners saem no unmount.
 *
 * @param {{ isDirty: () => boolean, message: () => string }} options
 */
export function useUnsavedChangesGuard({ isDirty, message }) {
    let bypassing   = false;
    let stopBefore  = null;

    function onBefore(event) {
        const visit = event?.detail?.visit;

        if (bypassing || visit?.prefetch || !isDirty()) return undefined;

        return window.confirm(message()) ? undefined : false;
    }

    function onBeforeUnload(event) {
        if (!isDirty()) return;

        event.preventDefault();
        // Navegadores antigos só mostram o diálogo com returnValue preenchido.
        event.returnValue = '';
    }

    function bypass(visit) {
        bypassing = true;

        try {
            return visit();
        } finally {
            bypassing = false;
        }
    }

    onMounted(() => {
        stopBefore = typeof router?.on === 'function' ? router.on('before', onBefore) : null;
        window.addEventListener('beforeunload', onBeforeUnload);
    });

    onBeforeUnmount(() => {
        if (typeof stopBefore === 'function') stopBefore();
        stopBefore = null;
        window.removeEventListener('beforeunload', onBeforeUnload);
    });

    return { bypass };
}
