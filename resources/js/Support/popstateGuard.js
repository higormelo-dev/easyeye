/**
 * Voltar/Avançar do navegador (botão, atalho, gesto do trackpad) em tela com
 * alterações não salvas.
 *
 * O Inertia troca a página no `popstate` sem disparar o evento `before` e sem
 * como cancelar. No Chrome os listeners do window rodam na ordem de registro
 * (captura não adianta — conferido no navegador), então este listener precisa
 * ser instalado no bootstrap, ANTES do createInertiaApp: roda primeiro e,
 * quando alguma tela recusa a saída (guard devolve false), para o evento antes
 * de ele chegar ao Inertia. A tela é quem repõe a própria entrada no histórico.
 *
 * Sem a instalação (outro bundle), isPopstateGuardInstalled() é false e as
 * telas não interceptam o Voltar — interceptar depois do Inertia deixaria a
 * página trocada com a URL antiga.
 */
const guards = new Set();
let installed = false;

export function installPopstateGuard() {
    if (installed || typeof window === 'undefined') return;
    installed = true;

    window.addEventListener('popstate', (event) => {
        for (const guard of guards) {
            if (guard(event) === false) {
                event.stopImmediatePropagation();
                return;
            }
        }
    });
}

export function isPopstateGuardInstalled() {
    return installed;
}

/** @param {(event: PopStateEvent) => boolean|void} guard false = fica na tela */
export function addPopstateGuard(guard) {
    guards.add(guard);

    return () => guards.delete(guard);
}
