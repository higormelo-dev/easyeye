/**
 * Foco no título da página do Revisor — só nas TROCAS de página dentro do mesmo documento
 * (Inertia: Show → Done depois do envio, por exemplo), nunca na primeira carga.
 *
 * Na primeira carga o navegador não põe o foco em lugar nenhum e o primeiro Tab cai no atalho
 * "Ir para o conteúdo"; forçar o foco no título tiraria esse atalho de quem usa o teclado. Já numa
 * troca de página o botão que estava focado deixa de existir, e sem mover o foco o leitor de tela
 * não anuncia a página nova.
 */
let firstView = true;

export function focusHeading(element) {
    if (firstView) {
        firstView = false;

        return;
    }

    element?.focus?.({ preventScroll: true });
}

/** Volta ao estado de "primeira carga" (os testes montam várias páginas no mesmo documento). */
export function resetFocusHeading() {
    firstView = true;
}
