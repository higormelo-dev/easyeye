/**
 * Tooltips do template (Bootstrap 5) em toda a SPA, sem marcar elemento por
 * elemento: qualquer elemento com `title` — ou com `data-bs-toggle="tooltip"`
 * / `data-bs-title`, como no template — ganha o balão estilizado ao passar o
 * mouse ou ao focar pelo teclado.
 *
 * Por que não a inicialização do template: o Preclinic cria os tooltips UMA
 * vez, quando o script carrega (`new bootstrap.Tooltip` em cada elemento já
 * na página). Numa SPA (Vue + Inertia) a tela é desenhada depois e trocada a
 * cada navegação — não havia elemento nenhum para inicializar. Aqui o tooltip
 * nasce sob demanda (hover/foco) e é descartado ao sair: nada fica "preso" na
 * tela quando o Vue troca a página ou remove o elemento.
 *
 * - Um tooltip por vez; atraso curto no mouse (não pisca ao cruzar listas) e
 *   tolerância para levar o mouse até o balão (WCAG 1.4.13).
 * - Botão desabilitado também mostra o motivo (o CSS reativa o ponteiro só
 *   nos desabilitados com title; o clique continua bloqueado pelo navegador).
 * - Teclado: aparece no foco visível; Esc fecha (WCAG 1.4.13).
 * - Toque/caneta: não ativa (o balão ficaria preso no toque).
 * - Texto sempre como texto (html: false) — nunca interpreta HTML do title.
 * - Fora: `data-tooltip="off"`, editor rico (TinyMCE tem os dele), iframes.
 * - Página sem o CSS do Bootstrap (site de marketing): fica o title nativo.
 * - Rodando sob Cypress (E2E): desligado — os testes acham elementos pelo title.
 * - O elemento volta ao estado original ao fechar (title, aria-label) — e com o
 *   texto mais novo, se o Vue trocou o title com o balão aberto.
 */

const SELECTOR = '[title], [data-bs-toggle="tooltip"], [data-bs-original-title]';
const SKIP = '.tox, iframe, object, embed, option, [data-tooltip="off"]';
const SHOW_DELAY_MS = 250;
// Tolerância para o mouse ir do elemento até o balão sem ele sumir (WCAG 1.4.13).
const HIDE_DELAY_MS = 120;
const DEFAULTS = { loadTooltip: defaultLoader, delay: SHOW_DELAY_MS, hideDelay: HIDE_DELAY_MS };

let installed = false;
let options = { ...DEFAULTS };
let stylesOk = null;
let tooltipClass = null;
let loading = null;

let current = null; // { el, instance, addedAriaLabel, describedBy, titleObserver }
let wanted = null; // elemento que deve ganhar o tooltip (hover/foco em curso)
let hideTimer = null;
let keyboardMode = false; // última interação foi pelo teclado (foco por Tab)
let timer = null;
let removalObserver = null;

/**
 * Painel: o vendor.js já tem o Bootstrap inteiro (window.bootstrap). Demais
 * bundles (site, Portal do Paciente): só o componente Tooltip, sob demanda —
 * sem ligar junto os data-api do Bootstrap (dropdown, collapse...).
 */
function defaultLoader() {
    if (typeof window !== 'undefined' && window.bootstrap?.Tooltip) return Promise.resolve(window.bootstrap.Tooltip);

    return import('bootstrap/js/dist/tooltip.js').then((module) => module.default ?? module);
}

/**
 * Liga os tooltips (uma vez por página). `loadTooltip`, `delay` e `hideDelay`
 * só existem para testes.
 */
export function installTooltips(overrides = {}) {
    // E2E (Cypress) acha elementos por [title="..."]; com o balão aberto o
    // title sai do elemento por um instante — sem tooltip, sem teste instável.
    if (installed || typeof document === 'undefined' || window.Cypress) return;
    installed = true;
    options = { ...options, ...overrides };

    document.addEventListener('pointerover', onPointerOver, true);
    document.addEventListener('pointerout', onPointerOut, true);
    document.addEventListener('focusin', onFocusIn, true);
    document.addEventListener('focusout', onFocusOut, true);
    document.addEventListener('pointerdown', onPointerDown, true);
    document.addEventListener('keydown', onKeyDown, true);
    // Navegação da SPA, rolagem e janela sem foco: o balão sai junto.
    document.addEventListener('inertia:start', hideTooltip);
    window.addEventListener('scroll', hideTooltip, { capture: true, passive: true });
    window.addEventListener('blur', hideTooltip);
}

/** Desliga tudo (testes). */
export function uninstallTooltips() {
    if (!installed) return;
    hideTooltip();
    installed = false;
    stylesOk = null;
    tooltipClass = null;
    loading = null;
    keyboardMode = false;
    options = { ...DEFAULTS };

    document.removeEventListener('pointerover', onPointerOver, true);
    document.removeEventListener('pointerout', onPointerOut, true);
    document.removeEventListener('focusin', onFocusIn, true);
    document.removeEventListener('focusout', onFocusOut, true);
    document.removeEventListener('pointerdown', onPointerDown, true);
    document.removeEventListener('keydown', onKeyDown, true);
    document.removeEventListener('inertia:start', hideTooltip);
    window.removeEventListener('scroll', hideTooltip, { capture: true });
    window.removeEventListener('blur', hideTooltip);
}

/** Fecha o tooltip aberto (e cancela o que estava para abrir). */
export function hideTooltip() {
    wanted = null;
    clearTimeout(timer);
    clearTimeout(hideTimer);
    timer = null;
    hideTimer = null;
    disposeCurrent();
}

/** Fecha daqui a pouco — a não ser que o mouse chegue ao balão ou volte ao elemento. */
function hideSoon() {
    clearTimeout(timer);
    timer = null;
    wanted = null;

    if (!current) return;

    clearTimeout(hideTimer);
    hideTimer = setTimeout(hideTooltip, options.hideDelay);
}

function keepOpen() {
    clearTimeout(hideTimer);
    hideTimer = null;
}

function onPointerOver(event) {
    // Toque/caneta: o balão ficaria preso até o próximo toque — fica o nativo.
    if (event.pointerType && event.pointerType !== 'mouse') return;

    // O que está mesmo sob o ponteiro: sobre botão desabilitado o Chrome
    // alterna o alvo do evento entre o botão e o elemento pai.
    const node = elementAt(event) ?? event.target;

    // Mouse sobre o próprio balão: continua aberto (dá para ler).
    if (current && node instanceof Element && node.closest('.tooltip')) {
        keepOpen();
        return;
    }

    const el = targetOf(node);

    if (el && el === current?.el) {
        keepOpen();
        return;
    }

    if (el === wanted && el) return;

    if (!el) {
        // Botão desabilitado: o Chrome às vezes "passa" o evento para o pai
        // com o ponteiro ainda sobre o botão — pela geometria, nada mudou.
        const holder = current?.el ?? wanted;

        if (holder && pointerInside(holder, event)) {
            if (current) keepOpen();
            return;
        }

        hideSoon();
        return;
    }

    hideTooltip();
    wanted = el;
    timer = setTimeout(() => show(el), options.delay);
}

function pointerInside(el, event) {
    if (typeof event.clientX !== 'number' || !el.isConnected) return false;

    const r = el.getBoundingClientRect();

    return (
        r.width > 0 &&
        event.clientX >= r.left &&
        event.clientX <= r.right &&
        event.clientY >= r.top &&
        event.clientY <= r.bottom
    );
}

function elementAt(event) {
    if (typeof event.clientX !== 'number' || typeof document.elementFromPoint !== 'function') return null;

    try {
        return document.elementFromPoint(event.clientX, event.clientY);
    } catch {
        return null;
    }
}

function onPointerOut(event) {
    // Saiu da janela. (Sobre botão desabilitado o Chrome também manda
    // pointerout sem destino — aí o ponteiro continua sobre algo da página.)
    if (!event.relatedTarget && !elementAt(event)) hideTooltip();
}

function onFocusIn(event) {
    const el = targetOf(event.target);

    if (!el || el !== event.target || !keyboardFocus(el)) return;

    hideTooltip();
    wanted = el;
    show(el);
}

function onFocusOut(event) {
    if (current?.el === event.target || wanted === event.target) hideTooltip();
}

function onPointerDown() {
    keyboardMode = false;
    hideTooltip();
}

function onKeyDown(event) {
    if (!['Shift', 'Control', 'Alt', 'Meta'].includes(event.key)) keyboardMode = true;
    if (event.key === 'Escape' && (current || wanted)) hideTooltip();
}

/** Elemento com tooltip a partir do alvo do evento (o próprio ou um ancestral). */
function targetOf(node) {
    if (!(node instanceof Element)) return null;

    // Dentro do elemento já aberto (o title saiu dele enquanto o balão está visível).
    if (current?.el.contains(node)) return current.el;

    const el = node.closest(SELECTOR);

    if (!el || el.closest(SKIP) || !textOf(el)) return null;

    return el;
}

function textOf(el) {
    return (
        el.getAttribute('title') ??
        el.getAttribute('data-bs-title') ??
        el.getAttribute('data-bs-original-title') ??
        ''
    ).trim();
}

/** Foco vindo do teclado (Tab): clique não abre balão no foco. */
function keyboardFocus(el) {
    if (keyboardMode) return true;

    try {
        return el.matches(':focus-visible');
    } catch {
        return false;
    }
}

/** O CSS do tooltip do Bootstrap está na página? (vendor.css: panel, guest, portal) */
function hasStyles() {
    if (stylesOk === null) {
        stylesOk = getComputedStyle(document.documentElement).getPropertyValue('--bs-body-font-family').trim() !== '';
    }

    return stylesOk;
}

async function loadClass() {
    if (tooltipClass) return tooltipClass;

    loading ??= Promise.resolve()
        .then(() => options.loadTooltip())
        .then((Tooltip) => (tooltipClass = Tooltip))
        .catch(() => {
            // Falhou o carregamento (offline, chunk antigo após deploy): fica o nativo
            // e a próxima passada tenta de novo.
            loading = null;

            return null;
        });

    return loading;
}

async function show(el) {
    timer = null;

    if (!hasStyles()) return;

    const Tooltip = await loadClass();

    // Entre o pedido e o carregamento o mouse/foco pode ter saído, ou o Vue
    // pode ter removido o elemento.
    if (!Tooltip || wanted !== el || !el.isConnected || !textOf(el)) return;

    disposeCurrent();

    const hadAriaLabel = el.hasAttribute('aria-label');
    // O Bootstrap troca o aria-describedby pelo id do balão e o apaga ao fechar —
    // a dica do campo (ex.: form-text) sumiria; guarda para somar e devolver.
    const describedBy = el.getAttribute('aria-describedby');
    let instance;

    try {
        instance = Tooltip.getOrCreateInstance(el, {
            trigger: 'manual',
            container: 'body',
            html: false,
            placement: el.getAttribute('data-bs-placement') || 'top',
            fallbackPlacements: ['top', 'bottom', 'right', 'left'],
            // Posição pela janela: o template põe `position: relative` no
            // <html> e, com algo absoluto passando do fim da página, o cálculo
            // padrão (absolute) jogava o balão para fora da tela. O balão
            // fecha ao rolar, então fixo não tem desvantagem — e vale dentro
            // dos modais (também fixos).
            popperConfig: { strategy: 'fixed' },
        });
        instance.show();
    } catch {
        return;
    }

    const tipId = el.getAttribute('aria-describedby');

    if (describedBy && tipId && tipId !== describedBy) el.setAttribute('aria-describedby', `${describedBy} ${tipId}`);

    current = {
        el,
        instance,
        // O Bootstrap põe aria-label (= title) em botão só com ícone; sai junto ao fechar.
        addedAriaLabel: !hadAriaLabel && el.hasAttribute('aria-label'),
        describedBy,
        titleObserver: watchTitle(el, instance),
    };

    watchRemoval();
}

/**
 * O Vue trocou o title com o balão aberto (ex.: "Ativar" → "Desativar"):
 * atualiza o texto e guarda o novo para devolver ao fechar.
 */
function watchTitle(el, instance) {
    const observer = new MutationObserver(() => {
        const title = el.getAttribute('title');

        if (title === null) return; // a própria remoção feita pelo Bootstrap

        el.setAttribute('data-bs-original-title', title);
        el.removeAttribute('title');

        const inner = instance.tip?.querySelector('.tooltip-inner');

        // Direto no balão aberto: setContent() do Bootstrap reabre o balão e,
        // com disparo manual, ele se fecha sozinho ao fim da animação.
        if (!title.trim() || !inner) {
            hideTooltip();

            return;
        }

        inner.textContent = title;
        instance.update();
    });

    observer.observe(el, { attributes: true, attributeFilter: ['title'] });

    return observer;
}

/** Elemento removido com o balão aberto (página trocou, linha sumiu): fecha. */
function watchRemoval() {
    if (removalObserver || typeof MutationObserver === 'undefined') return;

    removalObserver = new MutationObserver(() => {
        if (current && !current.el.isConnected) hideTooltip();
    });
    removalObserver.observe(document.body, { childList: true, subtree: true });
}

function disposeCurrent() {
    removalObserver?.disconnect();
    removalObserver = null;

    if (!current) return;

    const { el, instance, addedAriaLabel, describedBy, titleObserver } = current;
    current = null;

    titleObserver.disconnect();

    const latest = el.getAttribute('data-bs-original-title');

    try {
        instance.dispose();
    } catch {
        // elemento já fora da página
    }

    // Devolve o title (o mais novo), a descrição original e tira o que o
    // Bootstrap deixou para trás.
    if (latest !== null && !el.hasAttribute('title')) el.setAttribute('title', latest);
    el.removeAttribute('data-bs-original-title');

    if (describedBy !== null) {
        el.setAttribute('aria-describedby', describedBy);
    } else {
        el.removeAttribute('aria-describedby');
    }

    if (addedAriaLabel) el.removeAttribute('aria-label');
}
