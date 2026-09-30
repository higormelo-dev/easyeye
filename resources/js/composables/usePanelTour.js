import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useUserPreferences } from './useUserPreferences.js';

/**
 * Tour guiado do painel da clínica com driver.js (MIT) — o que cada opção do
 * menu faz.
 *
 * - Passos da TELA ATUAL (prop `tour.page`: `data-tour` do elemento →
 *   título/descrição) logo depois das boas-vindas — ex.: Dashboard. Seguem a
 *   ordem em que aparecem na página (o usuário pode reordenar seções);
 *   elemento que não aparece para o usuário fica de fora.
 * - Passos do menu montados a partir da prop `nav` (o menu que ESTE usuário vê: já
 *   filtrado por perfil, plano e opções da clínica) + textos da prop `tour`
 *   (lang/{pt_BR,en}/tour.php). Item sem texto não entra; alvo que não está
 *   na tela na hora do passo (ex.: menu lateral fechado no celular, tablet
 *   girado) vira balão centralizado.
 * - Âncoras: atributos `data-tour` no AppLayout (`nav-<key>`, `entity-switcher`,
 *   `sidebar-toggle`, `locale`, `theme`, `user-menu`, `help`, `mobile-menu`) e
 *   no assistente de IA (`ai-assistant`, só quando a clínica tem o recurso).
 * - Textos vão como TEXTO: o driver.js usa innerHTML, então tudo é escapado.
 * - driver.js e o CSS dele só carregam ao abrir o tour (import dinâmico —
 *   nada no bundle inicial nem no SSR).
 * - Encerramento: o driver.js só chama onDestroyed depois da animação de
 *   entrada; toda a limpeza fica em finish(), chamada no fechar (X, Esc,
 *   clique fora no último passo), no concluir e no stop().
 * - Estado por usuário em preferences.tours[id] (App\Support\PanelTour),
 *   enviando só o tour que mudou (o servidor mescla); impersonação não grava
 *   (e o servidor também ignora). Marca na sessionStorage para voltar pelo
 *   histórico não reabrir o tour.
 */

const LAYOUT_TARGETS = ['locale', 'theme', 'user-menu', 'ai-assistant', 'help'];

// Lado do balão por alvo do cabeçalho; o assistente de IA fica no rodapé.
const LAYOUT_PLACEMENT = { 'ai-assistant': { side: 'top', align: 'end' } };

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ESCAPES[char]);
}

export const tourSelector = (name) => `[data-tour="${name}"]`;

/**
 * Alvo existe, tem tamanho, está na tela na horizontal (o menu do celular
 * fica fora, à esquerda) e não está transparente/oculto (ex.: botão de
 * recolher o menu com o menu já recolhido).
 */
export function isTargetVisible(element) {
    if (!element) return false;

    const rect = element.getBoundingClientRect();
    if (!(rect.width > 0 && rect.height > 0 && rect.right > 0)) return false;

    const style = element instanceof Element ? window.getComputedStyle(element) : null;

    return !style || (style.visibility !== 'hidden' && style.opacity !== '0');
}

/** Nomes de `data-tour` na ordem em que os elementos aparecem na página. */
export function sortByDocumentOrder(names) {
    const elements = new Map(names.map((name) => [name, document.querySelector(tourSelector(name))]));

    return [...names].sort((a, b) => {
        const first  = elements.get(a);
        const second = elements.get(b);
        if (!first || !second || first === second) return 0;

        return first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
    });
}

/** Elemento resolvido na hora do passo: fora da tela → balão centralizado. */
export function visibleTarget(name) {
    return () => {
        const element = document.querySelector(tourSelector(name));

        return isTargetVisible(element) ? element : undefined;
    };
}

function popover(title, description, extra = {}) {
    return { title: escapeHtml(title), description: escapeHtml(description), ...extra };
}

function targetStep(name, title, description, extra) {
    return { element: visibleTarget(name), data: { target: name }, popover: popover(title, description, extra) };
}

/**
 * Passos do tour: boas-vindas → tela atual (na ordem da página) → menu do
 * celular ou botão de recolher o menu → clínica atual → itens do menu (na
 * ordem do menu) → cabeçalho e assistente de IA → fim.
 *
 * @param {{ nav: Array, t: object, page?: Record<string, {title: string, description: string}>|null, sidebarVisible: boolean, hasTarget: (name: string) => boolean, pageOrder?: (names: string[]) => string[], touch?: boolean }} options
 */
export function buildTourSteps({ nav = [], t = {}, page = null, sidebarVisible = true, hasTarget = () => false, pageOrder = (names) => names, touch = false }) {
    const intro = touch && t.intro?.description_touch ? t.intro.description_touch : t.intro?.description;
    const steps = [{ popover: popover(t.intro?.title, intro) }];

    const pageEntries = page ?? {};
    const pageNames   = Object.keys(pageEntries).filter((name) => pageEntries[name]?.title && hasTarget(name));
    for (const name of pageOrder(pageNames)) {
        steps.push(targetStep(name, pageEntries[name].title, pageEntries[name].description));
    }

    if (!sidebarVisible && t.mobile_menu && hasTarget('mobile-menu')) {
        steps.push(targetStep('mobile-menu', t.mobile_menu.title, t.mobile_menu.description, { side: 'bottom', align: 'start' }));
    }

    // Recolher/expandir só existe com o menu lateral fixo (telas largas); com
    // o menu já recolhido o botão fica transparente → balão centralizado.
    const toggle = t.layout?.['sidebar-toggle'];
    if (sidebarVisible && toggle) {
        steps.push(targetStep('sidebar-toggle', toggle.title, toggle.description, { side: 'right', align: 'start' }));
    }

    // Troca de clínica: sempre explicada; no celular ou com o menu recolhido
    // o seletor não aparece → balão centralizado (o texto diz onde fica).
    const entity = t.layout?.['entity-switcher'];
    if (entity) {
        steps.push(targetStep('entity-switcher', entity.title, entity.description, { side: 'right', align: 'start' }));
    }

    for (const item of nav) {
        const description = item?.key ? t.nav?.[item.key] : null;
        if (!description) continue;

        const name = `nav-${item.key}`;

        steps.push(sidebarVisible && hasTarget(name)
            ? targetStep(name, item.label, description, { side: 'right', align: 'start' })
            : { popover: popover(item.label, description) });
    }

    for (const name of LAYOUT_TARGETS) {
        const entry = t.layout?.[name];
        if (entry && hasTarget(name)) {
            steps.push(targetStep(name, entry.title, entry.description, LAYOUT_PLACEMENT[name] ?? { side: 'bottom', align: 'end' }));
        }
    }

    steps.push({ popover: popover(t.outro?.title, t.outro?.description) });

    return steps;
}

const sessionKey = (id, version) => `ee-tour:${id}@${version}`;

function markSeenInSession(id, version) {
    try {
        sessionStorage.setItem(sessionKey(id, version), '1');
    } catch { /* storage indisponível: vale só a preferência gravada */ }
}

function seenInSession(id, version) {
    try {
        return sessionStorage.getItem(sessionKey(id, version)) === '1';
    } catch {
        return false;
    }
}

async function loadDriver() {
    const [module] = await Promise.all([import('driver.js'), import('driver.js/dist/driver.css')]);

    return module.driver;
}

// Um tour por vez na página. `generation` cancela uma abertura em andamento
// (import ainda carregando) quando o layout sai da tela. Abertura automática
// no máximo uma vez por tour na sessão da SPA.
let active       = null;
let activeFinish = null;
let generation   = 0;
const autoStarted = new Set();

export function usePanelTour() {
    const page = usePage();
    const { preferences, savePreference } = useUserPreferences();

    const config    = computed(() => page.props.tour ?? null);
    const available = computed(() => !!config.value?.t);
    const seen      = computed(() => {
        const current = config.value;
        if (!current) return true;

        const local = preferences.value?.tours?.[current.id];

        return !!current.seen
            || Number(local?.version ?? 0) >= current.version
            || seenInSession(current.id, current.version);
    });

    /** Grava só o tour que mudou (o servidor mescla); na tela, o mapa completo. */
    function persist({ id, version }, status) {
        const state = { version, status };

        savePreference('tours', { [id]: state }, {
            debounceMs: 0,
            optimistic: { ...(preferences.value?.tours ?? {}), [id]: state },
        });
    }

    async function start() {
        const current = config.value;
        if (!current?.t || active) return false;

        const ticket = ++generation;
        let driver;

        try {
            driver = await loadDriver();
        } catch (error) {
            // Chunk removido após deploy ou rede: o botão só não abre o tour.
            window.Sentry?.captureException?.(error, { extra: { feature: 'panel-tour' } });

            return false;
        }

        if (ticket !== generation || active) return false;

        // Tour capturado na abertura: a gravação vale para ESTE tour, mesmo
        // que a página mude antes do fechamento.
        const { id, version, auto, t } = current;
        const reduced  = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false;
        const touch    = window.matchMedia?.('(pointer: coarse)')?.matches ?? false;
        const returnTo = document.activeElement;
        const onScroll = () => active?.refresh();
        // A atualização automática do Dashboard (30 s) muda a altura das
        // seções com o tour aberto: reposiciona o destaque junto.
        const onResize = typeof ResizeObserver === 'function' ? new ResizeObserver(() => active?.refresh()) : null;
        let finished   = false;

        const finish = (status) => {
            if (finished) return;
            finished = true;

            document.removeEventListener('scroll', onScroll, true);
            onResize?.disconnect();
            active       = null;
            activeFinish = null;
            markSeenInSession(id, version);

            if (auto) persist({ id, version }, status); // impersonação: não grava

            if (returnTo instanceof HTMLElement && document.contains(returnTo)) {
                returnTo.focus({ preventScroll: true });
            }
        };

        active = driver({
            steps: buildTourSteps({
                nav:            page.props.nav ?? [],
                t,
                page:           current.page ?? null,
                sidebarVisible: isTargetVisible(document.getElementById('sidebar')),
                hasTarget:      (name) => isTargetVisible(document.querySelector(tourSelector(name))),
                pageOrder:      sortByDocumentOrder,
                touch,
            }),
            showProgress:             true,
            progressText:             escapeHtml(t.ui?.progress),
            nextBtnText:              escapeHtml(t.ui?.next),
            prevBtnText:              escapeHtml(t.ui?.previous),
            doneBtnText:              escapeHtml(t.ui?.done),
            popoverClass:             'ee-tour',
            animate:                  !reduced,
            // Rolagem sem animação: com a suave o destaque e o balão ficavam
            // desalinhados no menu lateral (rolagem interna do simplebar).
            smoothScroll:             false,
            allowClose:               true,
            allowKeyboardControl:     true,
            // Toque/clique fora avança em vez de encerrar (toque acidental no
            // celular); fechar é no X ou Esc.
            overlayClickBehavior:     'nextStep',
            // O item destacado é um link do menu: clicar nele no meio do tour
            // trocaria de página com o tour aberto.
            disableActiveInteraction: true,
            stagePadding:             6,
            stageRadius:              8,
            onPopoverRender: (dom) => {
                dom.wrapper.setAttribute('aria-modal', 'true');
                dom.closeButton.setAttribute('aria-label', t.ui?.close ?? '');
                dom.closeButton.setAttribute('title', t.ui?.close ?? '');
                // Depois do foco que o próprio driver.js põe no primeiro botão (o X).
                queueMicrotask(() => dom.nextButton.focus({ preventScroll: true }));
            },
            onDestroyStarted: (element, step, { driver: instance }) => {
                finish('dismissed');
                instance.destroy();
            },
            onDoneClick: (element, step, { driver: instance }) => {
                finish('completed');
                instance.destroy();
            },
        });
        activeFinish = finish;

        // Menu lateral rola dentro do próprio container: reposiciona o destaque.
        document.addEventListener('scroll', onScroll, { capture: true, passive: true });
        onResize?.observe(document.body);

        active.drive();

        return true;
    }

    /** Fecha o tour aberto ou cancela a abertura em andamento (ex.: o layout saiu da tela). */
    function stop() {
        generation++;

        if (!active) return;

        const instance = active;
        activeFinish?.('dismissed');
        instance.destroy();
    }

    /** Abre sozinho: não visto, sem impersonação e só uma vez por sessão. */
    function autoStart() {
        const current = config.value;
        if (!current?.auto || !available.value || seen.value || autoStarted.has(current.id)) return false;

        autoStarted.add(current.id);
        start().catch(() => {});

        return true;
    }

    return { available, seen, start, stop, autoStart };
}
