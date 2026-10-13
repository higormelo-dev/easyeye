import { nextTick, onBeforeUnmount, watch } from 'vue';

// Pilha compartilhada entre TODAS as instâncias (módulo, não por componente)
// — é o que permite 2 diálogos abertos ao mesmo tempo (ex.: confirmação
// sobre um formulário) sem que o Tab/Esc de um vaze pro outro: só o topo
// da pilha reage a teclado; os de baixo ficam "congelados" até o de cima
// fechar. Fundo inerte usa a mesma contagem (só liga/desliga ao cruzar
// 0↔1 diálogo aberto, não a cada abre/fecha de um aninhado).
const openInstances = []; // { token, dialogRef }
const inertedElements = new Set();

function isTopmost(token) {
    return openInstances[openInstances.length - 1]?.token === token;
}

function removeInstance(token) {
    const index = openInstances.findIndex((entry) => entry.token === token);
    if (index === -1) return false;

    openInstances.splice(index, 1);

    return true;
}

function focusableElements(container) {
    if (!container) return [];

    const selector = 'a[href],button,input,select,textarea,iframe,[tabindex]';

    return Array.from(container.querySelectorAll(selector)).filter(
        (el) => !el.disabled && el.getAttribute('tabindex') !== '-1',
    );
}

function updateBackgroundInert() {
    const app = document.getElementById('app');
    if (!app) return;

    if (openInstances.length > 0) {
        if (!inertedElements.has(app)) {
            app.inert = true;
            app.setAttribute('inert', '');
            inertedElements.add(app);
        }
    } else if (inertedElements.has(app)) {
        app.inert = false;
        app.removeAttribute('inert');
        inertedElements.delete(app);
    }
}

/**
 * Overlay "de fora": conteúdo do <body> que não é o fundo da página (#app) nem
 * um diálogo da pilha — confirmação do SweetAlert2 (inclusive o aviso de
 * sessão expirando), menu teleportado (ActionDropdown), UI do TinyMCE
 * (diálogos e menus próprios), modal avulso aberto por cima. Cada um é dono
 * do PRÓPRIO foco e teclado enquanto está aberto: o trap só protege o fundo
 * da página e os diálogos de baixo da pilha. window/document/body não são
 * overlay (foco "solto" continua sendo do diálogo do topo).
 */
function isForeignOverlay(target) {
    if (!(target instanceof Element) || target === document.body || target === document.documentElement) {
        return false;
    }

    if (target.closest('#app')) return false;

    return !openInstances.some((entry) => entry.dialogRef.value?.contains(target));
}

/**
 * Alerta MODAL do SweetAlert2 aberto — mesma definição que ele usa
 * (`isModal()`): classe `swal2-shown` no body, fora toast e backdrop
 * desligado. O teclado dele só escuta com o foco DENTRO do popup (listener no
 * popup): clicou no fundo do alerta, o foco vai pro <body> e o Escape cairia
 * no diálogo de baixo.
 */
function isSweetAlertModalOpen() {
    const { classList } = document.body;

    return (
        classList.contains('swal2-shown') &&
        !classList.contains('swal2-toast-shown') &&
        !classList.contains('swal2-no-backdrop')
    );
}

/**
 * Contrato WAI-ARIA de diálogo modal (https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/)
 * num único lugar: foco entra no diálogo ao abrir e volta pra quem abriu ao
 * fechar, Tab fica contido dentro do diálogo, Escape fecha, fundo vira
 * `inert`, e tudo isso respeita diálogos aninhados (só o do topo reage).
 *
 * Overlays de fora da pilha (SweetAlert2, menu teleportado do ActionDropdown,
 * UI do TinyMCE — ver isForeignOverlay) NÃO são brigados: foco que cai neles
 * não é puxado de volta e tecla vinda deles não fecha o diálogo. Componente
 * PRÓPRIO que abre por cima de um diálogo (ex.: PdfPreviewModal) chama este
 * composable e entra na pilha — assim Escape/Tab ficam com o do topo.
 *
 * Diálogo montado só enquanto aberto (`v-if` no pai, sem prop `open`): passe
 * `() => true` em `isOpenGetter` — entra na pilha ao montar e sai (devolvendo
 * o foco) ao desmontar.
 *
 * @param {import('vue').Ref<HTMLElement|null>} dialogRef elemento com o conteúdo focável do diálogo
 * @param {() => boolean} isOpenGetter
 * @param {() => void} onEscape
 */
export function useModalA11y(dialogRef, isOpenGetter, onEscape) {
    const token = Symbol('modal');
    let returnFocusTo = null;

    function focusFirst() {
        (focusableElements(dialogRef.value)[0] ?? dialogRef.value)?.focus();
    }

    // Só devolve se o foco ficou perdido: a página pode ter movido o foco de
    // propósito (ex.: abrir outro modal). Foco ainda DENTRO do diálogo que está
    // fechando conta como perdido: a transição de saída mantém o elemento no
    // DOM por ~200 ms e o foco só "cai" pro <body> quando ele enfim sai — sem
    // isso a checagem rodava cedo demais e nunca devolvia.
    function restoreFocus() {
        const target = returnFocusTo;
        const closing = dialogRef.value;
        returnFocusTo = null;

        nextTick(() => {
            const active = document.activeElement;
            const focusLost = !active || active === document.body || closing?.contains(active);

            if (target?.isConnected && focusLost) {
                target.focus({ preventScroll: true });
            }
        });
    }

    function handleKeydown(event) {
        if (!isTopmost(token)) return;

        // Outro overlay é dono deste teclado: alerta modal do SweetAlert2
        // aberto, ou tecla vinda de dentro de um overlay de fora. Sem isso, o
        // Escape pedido pra ele fechava o diálogo por trás.
        if (isSweetAlertModalOpen() || isForeignOverlay(event.target)) return;

        if (event.key === 'Escape') {
            onEscape();
            return;
        }

        if (event.key !== 'Tab') return;

        const focusable = focusableElements(dialogRef.value);
        if (focusable.length === 0) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    // Rede de segurança: foco que escapa do diálogo por um caminho que não
    // seja Tab (ex.: algum código alheio chamando .focus() direto) volta pro
    // diálogo — quando cai no fundo da página (#app) ou num diálogo de baixo
    // da pilha. Em overlay de fora, não (ver isForeignOverlay). Tab em si já
    // é resolvido acima, com direção certa.
    function handleFocusIn(event) {
        if (!isTopmost(token)) return;
        if (!event.target || dialogRef.value?.contains(event.target)) return;
        if (isForeignOverlay(event.target)) return;

        focusFirst();
    }

    watch(
        isOpenGetter,
        (isOpen, wasOpen) => {
            if (isOpen && !wasOpen) {
                returnFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                openInstances.push({ token, dialogRef });
                updateBackgroundInert();
                window.addEventListener('keydown', handleKeydown);
                document.addEventListener('focusin', handleFocusIn);

                nextTick(focusFirst);

                return;
            }

            if (!isOpen && wasOpen) {
                removeInstance(token);
                updateBackgroundInert();
                window.removeEventListener('keydown', handleKeydown);
                document.removeEventListener('focusin', handleFocusIn);

                restoreFocus();
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        // Desmontado ainda aberto (v-if no pai, troca de página): sai da pilha e
        // devolve o foco como se tivesse fechado.
        if (removeInstance(token)) {
            updateBackgroundInert();
            restoreFocus();
        }

        window.removeEventListener('keydown', handleKeydown);
        document.removeEventListener('focusin', handleFocusIn);
    });
}
