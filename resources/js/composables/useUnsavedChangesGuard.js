import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { addPopstateGuard, isPopstateGuardInstalled } from '@/Support/popstateGuard.js';

/**
 * Confirmação antes de sair da tela com alterações não salvas.
 *
 * - Navegação do Inertia (menu, links, logout…): `router.on('before')` pergunta
 *   com window.confirm (síncrono: a visita original segue intacta, com os
 *   callbacks dela, se o usuário confirmar).
 * - Link comum (<a href>, recarga completa): clique interceptado com a mesma
 *   pergunta — o iOS/iPadOS não dispara beforeunload.
 * - Recarregar/fechar a aba: `beforeunload`, com o diálogo nativo do navegador.
 * - Voltar/Avançar do navegador (botão, atalho, gesto do trackpad): o Inertia
 *   troca a página no `popstate` SEM disparar o `before`. O listener instalado
 *   no bootstrap (Support/popstateGuard) roda antes do dele; se o usuário
 *   desistir de sair, o evento para ali e esta tela volta ao histórico (URL e
 *   estado do Inertia).
 * - `bypass(fn)`: visitas da própria tela (salvar, trocar de convênio já
 *   confirmada) passam sem perguntar — o Inertia dispara o `before` de forma
 *   síncrona dentro de router.get/post.
 * - Prefetch não é saída de página: ignorado.
 * Todos os listeners saem no unmount.
 *
 * @param {{ isDirty: () => boolean, message: () => string }} options
 */
export function useUnsavedChangesGuard({ isDirty, message }) {
    let bypassing = false;
    // Saída já confirmada no aviso do Inertia. Logout e troca de clínica
    // respondem com Inertia::location (recarga completa): sem isto o
    // navegador perguntaria de novo no beforeunload, e "Ficar" deixaria a tela
    // aberta já deslogada (ou na sessão de outra clínica). Volta a valer se a
    // visita terminar nesta mesma tela (sucesso parcial, erro, falha de rede).
    let leaveConfirmed = false;
    let stops = [];
    // Entrada do histórico desta tela (estado do Inertia + URL), reposta
    // quando o usuário desiste de sair pelo Voltar/Avançar.
    let ownEntry = null;

    let leaveConfirmedTimer = null;

    const withoutHash = (href) => String(href).split('#')[0];

    // Link comum (<a href> fora do Inertia: menu lateral, logo, perfil,
    // idioma…) recarrega a página. O beforeunload cobre o desktop, mas o
    // iOS/iPadOS não dispara beforeunload — por isso o clique é interceptado
    // aqui, na fase de bolha: <Link> do Inertia e dropdowns/@click.prevent já
    // chegam com preventDefault (o aviso do Inertia é o do `before`).
    function onDocumentClick(event) {
        if (event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;

        const url = new URL(link.href, window.location.href);
        if (!['http:', 'https:'].includes(url.protocol)) return;
        // Âncora na própria página ("#", "#secao") não sai da tela.
        if (url.href.includes('#') && withoutHash(url.href) === withoutHash(window.location.href)) return;
        if (!isDirty()) return;

        if (!window.confirm(message())) {
            event.preventDefault();
            return;
        }

        // Desktop: o beforeunload dessa navegação não pergunta de novo. Se o
        // link não sair da página (ex.: virar download), a proteção volta.
        leaveConfirmed = true;
        clearTimeout(leaveConfirmedTimer);
        leaveConfirmedTimer = setTimeout(() => {
            leaveConfirmed = false;
        }, 1000);
    }

    function rememberOwnEntry() {
        ownEntry = { state: window.history.state, url: window.location.href };
    }

    // false = ficar: o popstateGuard para o evento antes do Inertia.
    function onPopstate(event) {
        // Âncora (#): o navegador não traz estado e o Inertia só acerta a URL,
        // sem trocar a página. Qualquer entrada COM estado — inclusive da mesma
        // URL (ex.: link "#" seguido de Voltar) — o Inertia remonta: é saída.
        if (!ownEntry || event?.state == null) return undefined;
        if (!isDirty() || window.confirm(message())) return undefined;

        window.history.pushState(ownEntry.state, '', ownEntry.url);

        return false;
    }

    function onBefore(event) {
        const visit = event?.detail?.visit;

        if (bypassing || visit?.prefetch || !isDirty()) return undefined;
        if (!window.confirm(message())) return false;

        leaveConfirmed = true;

        return undefined;
    }

    function onBeforeUnload(event) {
        if (leaveConfirmed || !isDirty()) return;

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
        // Retrato do histórico desta tela: o Inertia grava a entrada antes de
        // montar a página. `navigate` cobre o carregamento inicial e troca de
        // URL; `success` cobre visita na MESMA URL (salvar, recarga parcial),
        // que substitui a entrada sem disparar `navigate`.
        rememberOwnEntry();

        if (typeof router?.on === 'function') {
            const stayedOnScreen = () => {
                leaveConfirmed = false;
            };
            stops.push(router.on('navigate', rememberOwnEntry));
            stops.push(
                router.on('success', () => {
                    stayedOnScreen();
                    rememberOwnEntry();
                }),
            );
            for (const type of ['error', 'httpException', 'networkError']) stops.push(router.on(type, stayedOnScreen));
            stops.push(router.on('before', onBefore));
        }
        if (isPopstateGuardInstalled()) stops.push(addPopstateGuard(onPopstate));
        window.addEventListener('beforeunload', onBeforeUnload);
        document.addEventListener('click', onDocumentClick);
    });

    onBeforeUnmount(() => {
        stops.forEach((stop) => {
            if (typeof stop === 'function') stop();
        });
        stops = [];
        clearTimeout(leaveConfirmedTimer);
        window.removeEventListener('beforeunload', onBeforeUnload);
        document.removeEventListener('click', onDocumentClick);
    });

    return { bypass };
}
