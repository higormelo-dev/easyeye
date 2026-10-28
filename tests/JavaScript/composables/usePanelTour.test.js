import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * Tour guiado do painel (driver.js): passos a partir do menu que o usuário
 * vê, textos escapados (o driver.js usa innerHTML), celular com o menu
 * fechado, alvo checado na hora do passo, encerramento sempre limpo (mesmo
 * fechando logo no início), gravação só do tour que mudou, impersonação sem
 * gravar, abertura automática uma vez e falha de carregamento tratada.
 */

const inertia = vi.hoisted(() => ({ props: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.props = reactive({ auth: { user: { preferences: {} } }, nav: [], tour: null });

    return { usePage: () => ({ props: inertia.props }) };
});

// driver.js simulado: destroy() público NÃO chama onDestroyStarted (igual à lib).
const driverMock = vi.hoisted(() => ({ configs: [], instances: [] }));
vi.mock('driver.js', () => ({
    driver: vi.fn((config) => {
        const instance = { drive: vi.fn(), destroy: vi.fn(), refresh: vi.fn() };
        driverMock.configs.push(config);
        driverMock.instances.push(instance);

        return instance;
    }),
}));
vi.mock('driver.js/dist/driver.css', () => ({}));

import {
    buildTourSteps,
    escapeHtml,
    isTargetVisible,
    sortByDocumentOrder,
    usePanelTour,
} from '@/composables/usePanelTour.js';

const T = {
    ui: {
        start: 'Take the tour',
        next: 'Next',
        previous: 'Previous',
        done: 'Finish',
        close: 'Close the tour',
        progress: '{{current}} of {{total}}',
    },
    intro: { title: 'Welcome', description: 'Use the arrow keys', description_touch: 'Use the buttons' },
    mobile_menu: { title: 'Menu', description: 'Open the menu here' },
    nav: { dashboard: 'Day summary', schedules: 'Book & follow <appointments>' },
    layout: {
        'entity-switcher': { title: 'Current clinic', description: 'Switch clinic' },
        locale: { title: 'Language', description: 'Choose' },
        theme: { title: 'Theme', description: 'Light or dark' },
        'user-menu': { title: 'Your account', description: 'Profile' },
        help: { title: 'See again', description: 'Click here' },
    },
    outro: { title: 'All set', description: 'Bye' },
};

const NAV = [
    { key: 'dashboard', label: 'Dashboard' },
    { key: 'schedules', label: 'Schedules <b>' },
    { key: 'stock', label: 'Stock' }, // sem texto no tour: fica de fora
    { section: 'Other' }, // separador: ignorado
];

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const titles = (steps) => steps.map((step) => step.popover.title);
const targets = (steps) => steps.map((step) => step.data?.target ?? null);
const hooks = (instance) => ({ driver: instance });

let tourSeq = 0;

function setTour(overrides = {}) {
    tourSeq += 1;
    inertia.props.tour = { id: `panel:role_${tourSeq}`, version: 1, seen: false, auto: true, t: T, ...overrides };
    inertia.props.nav = NAV;
    inertia.props.auth.user.preferences = {};

    return inertia.props.tour.id;
}

const lastConfig = () => driverMock.configs.at(-1);
const lastInstance = () => driverMock.instances.at(-1);
const sentBody = (call = 0) => JSON.parse(vi.mocked(fetch).mock.calls[call][1].body);

beforeEach(() => {
    driverMock.configs.length = 0;
    driverMock.instances.length = 0;
    vi.mocked(fetch).mockClear();
    document.body.innerHTML = '';
    sessionStorage.clear();
});

describe('buildTourSteps', () => {
    it('desktop: boas-vindas, clínica, itens do menu com texto (na ordem), cabeçalho e fim', () => {
        const steps = buildTourSteps({ nav: NAV, t: T, sidebarVisible: true, hasTarget: () => true });

        expect(titles(steps)).toEqual([
            'Welcome',
            'Current clinic',
            'Dashboard',
            'Schedules &lt;b&gt;',
            'Language',
            'Theme',
            'Your account',
            'See again',
            'All set',
        ]);
        expect(targets(steps)).toEqual([
            null,
            'entity-switcher',
            'nav-dashboard',
            'nav-schedules',
            'locale',
            'theme',
            'user-menu',
            'help',
            null,
        ]);
        expect(steps[2].popover).toEqual({
            title: 'Dashboard',
            description: 'Day summary',
            side: 'right',
            align: 'start',
        });
        expect(steps[3].popover.description).toBe('Book &amp; follow &lt;appointments&gt;');
        expect(steps[0].popover.description).toBe('Use the arrow keys');
    });

    it('o alvo é resolvido na hora do passo: fora da tela vira balão centralizado', () => {
        const step = buildTourSteps({ nav: NAV, t: T, sidebarVisible: true, hasTarget: () => true })[2];

        const link = document.createElement('a');
        link.setAttribute('data-tour', 'nav-dashboard');
        document.body.append(link);

        link.getBoundingClientRect = () => ({ width: 200, height: 40, right: 200 });
        expect(step.element()).toBe(link);

        link.getBoundingClientRect = () => ({ width: 200, height: 40, right: -375 }); // tablet girado: menu fechou
        expect(step.element()).toBeUndefined();
    });

    it('celular (menu lateral fora da tela): aponta o botão do menu e explica clínica e itens em balões centralizados', () => {
        const visible = new Set(['mobile-menu', 'user-menu', 'help']);
        const steps = buildTourSteps({
            nav: NAV,
            t: T,
            sidebarVisible: false,
            hasTarget: (name) => visible.has(name),
            touch: true,
        });

        expect(titles(steps)).toEqual([
            'Welcome',
            'Menu',
            'Current clinic',
            'Dashboard',
            'Schedules &lt;b&gt;',
            'Your account',
            'See again',
            'All set',
        ]);
        expect(targets(steps)).toEqual([null, 'mobile-menu', 'entity-switcher', null, null, 'user-menu', 'help', null]);
        expect(steps[2].element()).toBeUndefined(); // seletor de clínica escondido no menu fechado: balão centralizado
        expect(steps[3].element).toBeUndefined();
        expect(steps[0].popover.description).toBe('Use the buttons'); // sem teclado
    });

    it('passos da tela atual entram depois das boas-vindas, na ordem, e só os visíveis para o usuário', () => {
        const page = {
            'dashboard-customize': { title: 'Customize', description: 'Order <sections>' },
            'dashboard-activation': { title: 'Setup', description: 'Hidden when done' },
            'dashboard-kpis': { title: 'Indicators', description: 'Numbers' },
        };
        const visible = new Set(['dashboard-customize', 'dashboard-kpis', 'nav-dashboard']);
        const steps = buildTourSteps({
            nav: NAV,
            t: T,
            page,
            sidebarVisible: true,
            hasTarget: (name) => visible.has(name),
        });

        expect(titles(steps).slice(0, 5)).toEqual([
            'Welcome',
            'Customize',
            'Indicators',
            'Current clinic',
            'Dashboard',
        ]);
        expect(targets(steps).slice(0, 5)).toEqual([
            null,
            'dashboard-customize',
            'dashboard-kpis',
            'entity-switcher',
            'nav-dashboard',
        ]);
        expect(steps[1].popover.description).toBe('Order &lt;sections&gt;');
    });

    it('passos da tela seguem a ordem da página (seções reordenadas pelo usuário), não a do arquivo de textos', () => {
        const page = {
            'dashboard-kpis': { title: 'Indicators', description: 'Numbers' },
            'dashboard-recent-patients': { title: 'Patients', description: 'Latest' },
        };
        const steps = buildTourSteps({
            nav: [],
            t: T,
            page,
            sidebarVisible: true,
            hasTarget: () => true,
            pageOrder: (names) => [...names].reverse(),
        });

        expect(targets(steps).slice(1, 3)).toEqual(['dashboard-recent-patients', 'dashboard-kpis']);
    });

    it('alvo que não está na tela (ex.: idioma único, tema escondido no celular) fica de fora', () => {
        const steps = buildTourSteps({ nav: [], t: T, sidebarVisible: true, hasTarget: (name) => name === 'help' });

        expect(titles(steps)).toEqual(['Welcome', 'Current clinic', 'See again', 'All set']); // clínica: sempre explicada
    });

    it('menu recolhível e assistente de IA: recolher antes da clínica; IA no rodapé, antes do "rever o tour"', () => {
        const t = {
            ...T,
            layout: {
                ...T.layout,
                'sidebar-toggle': { title: 'Collapse menu', description: 'Icons only' },
                'ai-assistant': { title: 'Virtual assistant', description: 'Ask the AI' },
            },
        };

        const desktop = buildTourSteps({ nav: [], t, sidebarVisible: true, hasTarget: () => true });
        expect(targets(desktop)).toEqual([
            null,
            'sidebar-toggle',
            'entity-switcher',
            'locale',
            'theme',
            'user-menu',
            'ai-assistant',
            'help',
            null,
        ]);
        expect(desktop[6].popover).toMatchObject({ side: 'top', align: 'end' });

        // Celular: sem botão de recolher (o menu abre pelo botão do topo); sem IA na clínica, sem passo.
        const phone = buildTourSteps({
            nav: [],
            t,
            sidebarVisible: false,
            hasTarget: (name) => name === 'mobile-menu',
        });
        expect(targets(phone)).toEqual([null, 'mobile-menu', 'entity-switcher', null]);
    });

    it('menu já recolhido: botão de recolher e seletor de clínica escondidos viram balões centralizados', () => {
        const t = {
            ...T,
            layout: { ...T.layout, 'sidebar-toggle': { title: 'Collapse menu', description: 'Icons only' } },
        };
        const [, toggleStep, entityStep] = buildTourSteps({ nav: [], t, sidebarVisible: true, hasTarget: () => false });

        const toggle = document.createElement('button');
        toggle.setAttribute('data-tour', 'sidebar-toggle');
        toggle.style.opacity = '0'; // .mini-sidebar #toggle_btn
        toggle.getBoundingClientRect = () => ({ width: 22, height: 22, right: 60 });
        const entity = document.createElement('div');
        entity.setAttribute('data-tour', 'entity-switcher');
        entity.getBoundingClientRect = () => ({ width: 0, height: 0, right: 0 }); // .mini-sidebar .sidebar-top { display: none }
        document.body.append(toggle, entity);

        expect(toggleStep.data.target).toBe('sidebar-toggle');
        expect(toggleStep.element()).toBeUndefined();
        expect(entityStep.element()).toBeUndefined();

        toggle.style.opacity = '1'; // menu expandido
        expect(toggleStep.element()).toBe(toggle);
    });
});

describe('sortByDocumentOrder', () => {
    it('ordena os data-tour pela posição na página; ausentes mantêm a posição relativa', () => {
        document.body.innerHTML = `
            <div data-tour="dashboard-live"></div>
            <section>
                <div data-tour="dashboard-recent-patients"></div>
                <div data-tour="dashboard-kpis"></div>
            </section>`;

        expect(sortByDocumentOrder(['dashboard-kpis', 'dashboard-recent-patients', 'dashboard-live'])).toEqual([
            'dashboard-live',
            'dashboard-recent-patients',
            'dashboard-kpis',
        ]);
        expect(sortByDocumentOrder([])).toEqual([]);
    });
});

describe('escapeHtml e isTargetVisible', () => {
    it('escapa os caracteres de HTML', () => {
        expect(escapeHtml(`<img src=x onerror="alert('x')">&`)).toBe(
            '&lt;img src=x onerror=&quot;alert(&#39;x&#39;)&quot;&gt;&amp;',
        );
        expect(escapeHtml(null)).toBe('');
    });

    it('visível = existe, tem tamanho e não está fora da tela à esquerda', () => {
        const element = (rect) => ({ getBoundingClientRect: () => rect });

        expect(isTargetVisible(null)).toBe(false);
        expect(isTargetVisible(element({ width: 0, height: 20, right: 100 }))).toBe(false);
        expect(isTargetVisible(element({ width: 276, height: 40, right: -299 }))).toBe(false);
        expect(isTargetVisible(element({ width: 276, height: 40, right: 276 }))).toBe(true);
    });

    it('transparente ou invisível (ex.: botão de recolher com o menu recolhido) não conta como visível', () => {
        const button = document.createElement('button');
        button.getBoundingClientRect = () => ({ width: 22, height: 22, right: 60 });
        document.body.append(button);

        expect(isTargetVisible(button)).toBe(true);

        button.style.opacity = '0';
        expect(isTargetVisible(button)).toBe(false);

        button.style.opacity = '';
        button.style.visibility = 'hidden';
        expect(isTargetVisible(button)).toBe(false);
    });
});

describe('usePanelTour', () => {
    it('sem tour no painel (ex.: painel SaaS): indisponível e não abre', async () => {
        inertia.props.tour = null;
        const tour = usePanelTour();

        expect(tour.available.value).toBe(false);
        expect(await tour.start()).toBe(false);
        expect(driverMock.configs).toHaveLength(0);
    });

    it('abre com textos escapados, tema do painel, sem rolagem animada e sem clicar no item destacado', async () => {
        setTour({
            t: { ...T, ui: { ...T.ui, next: 'Próximo <' } },
            page: { 'dashboard-customize': { title: 'Customize', description: 'Order' } },
        });
        const anchor = document.createElement('div');
        anchor.setAttribute('data-tour', 'dashboard-customize');
        anchor.getBoundingClientRect = () => ({ width: 100, height: 30, right: 900 });
        document.body.append(anchor);
        const tour = usePanelTour();

        expect(await tour.start()).toBe(true);

        const config = lastConfig();
        expect(titles(config.steps)).toContain('Customize'); // passos da tela (prop tour.page)
        expect(config).toMatchObject({
            popoverClass: 'ee-tour',
            nextBtnText: 'Próximo &lt;',
            progressText: '{{current}} of {{total}}',
            disableActiveInteraction: true,
            smoothScroll: false,
            overlayClickBehavior: 'nextStep',
            showProgress: true,
        });
        expect(lastInstance().drive).toHaveBeenCalledOnce();

        tour.stop();
    });

    it('reposiciona o destaque quando a página muda de altura (atualização automática) e para de observar ao fechar', async () => {
        const observers = [];
        const original = globalThis.ResizeObserver;
        globalThis.ResizeObserver = class {
            constructor(callback) {
                this.callback = callback;
                this.observe = vi.fn();
                this.disconnect = vi.fn();
                observers.push(this);
            }
        };

        try {
            setTour();
            const tour = usePanelTour();
            await tour.start();

            const [observer] = observers;
            expect(observer.observe).toHaveBeenCalledWith(document.body);

            observer.callback();
            expect(lastInstance().refresh).toHaveBeenCalledOnce();

            tour.stop();
            expect(observer.disconnect).toHaveBeenCalledOnce();
        } finally {
            globalThis.ResizeObserver = original;
        }
    });

    it('um tour por vez: abrir de novo com um aberto não cria outro', async () => {
        setTour();
        const tour = usePanelTour();

        await tour.start();
        expect(await tour.start()).toBe(false);
        expect(driverMock.configs).toHaveLength(1);

        tour.stop();
        expect(lastInstance().destroy).toHaveBeenCalledOnce();
    });

    it('balão acessível: modal, fechar traduzido e foco no "Próximo" depois do foco que o driver põe no X', async () => {
        setTour();
        const tour = usePanelTour();
        await tour.start();

        const wrapper = document.createElement('div');
        const closeButton = document.createElement('button');
        const nextButton = document.createElement('button');
        wrapper.append(closeButton, nextButton);
        document.body.append(wrapper);

        lastConfig().onPopoverRender({ wrapper, closeButton, nextButton });
        closeButton.focus(); // o driver.js foca o primeiro botão (o X) logo depois do hook
        await Promise.resolve();

        expect(wrapper.getAttribute('aria-modal')).toBe('true');
        expect(closeButton.getAttribute('aria-label')).toBe('Close the tour');
        expect(document.activeElement).toBe(nextButton);

        tour.stop();
    });

    it('concluir grava "completed" enviando só este tour (o servidor mescla)', async () => {
        const id = setTour();
        inertia.props.auth.user.preferences = { tours: { 'panel:other': { version: 1, status: 'dismissed' } } };
        await usePanelTour().start();

        const instance = lastInstance();
        lastConfig().onDoneClick(undefined, undefined, hooks(instance));
        await flush();

        expect(instance.destroy).toHaveBeenCalledOnce();
        expect(inertia.props.auth.user.preferences.tours).toEqual({
            'panel:other': { version: 1, status: 'dismissed' },
            [id]: { version: 1, status: 'completed' },
        });
        expect(fetch).toHaveBeenCalledOnce();

        const [url, request] = vi.mocked(fetch).mock.calls[0];
        expect(url).toBe('/_routes/panel.preferences.update');
        expect(request.method).toBe('PATCH');
        expect(JSON.parse(request.body)).toEqual({ tours: { [id]: { version: 1, status: 'completed' } } });
    });

    it('fechar (X, Esc, clique fora no último passo) — inclusive logo no início — grava "dismissed" e libera o botão', async () => {
        const id = setTour();
        const tour = usePanelTour();
        await tour.start();

        const instance = lastInstance();
        lastConfig().onDestroyStarted(undefined, undefined, hooks(instance));
        await flush();

        expect(instance.destroy).toHaveBeenCalledOnce();
        expect(sentBody()).toEqual({ tours: { [id]: { version: 1, status: 'dismissed' } } });

        // Não ficou preso: dá para abrir de novo.
        expect(await tour.start()).toBe(true);
        tour.stop();
    });

    it('grava para o tour que foi aberto, mesmo que a página mude antes de fechar', async () => {
        const id = setTour();
        const tour = usePanelTour();
        await tour.start();

        setTour(); // navegou para outra página/perfil com o tour aberto
        tour.stop();
        await flush();

        expect(sentBody()).toEqual({ tours: { [id]: { version: 1, status: 'dismissed' } } });
    });

    it('stop() no meio da abertura (import carregando) cancela: o tour não abre na página seguinte', async () => {
        setTour();
        const tour = usePanelTour();
        const opening = tour.start();

        tour.stop();

        expect(await opening).toBe(false);
        expect(driverMock.configs).toHaveLength(0);
    });

    it('impersonação: abre pelo botão mas não grava nada na conta do usuário', async () => {
        setTour({ auto: false });
        await usePanelTour().start();

        lastConfig().onDestroyStarted(undefined, undefined, hooks(lastInstance()));
        await flush();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('abre sozinho só se ainda não viu, sem impersonação e uma vez por sessão', async () => {
        setTour({ seen: true });
        expect(usePanelTour().autoStart()).toBe(false);

        setTour({ auto: false });
        expect(usePanelTour().autoStart()).toBe(false);

        const id = setTour();
        inertia.props.auth.user.preferences = { tours: { [id]: { version: 1, status: 'dismissed' } } };
        expect(usePanelTour().autoStart()).toBe(false); // já visto localmente (gravação em voo)

        setTour();
        const tour = usePanelTour();
        expect(tour.autoStart()).toBe(true);
        await flush();
        tour.stop();
        expect(tour.autoStart()).toBe(false);
        expect(driverMock.configs).toHaveLength(1);
    });

    it('voltar pelo histórico com as props antigas (seen: false) não reabre: marca na sessão do navegador', async () => {
        const id = setTour();
        const tour = usePanelTour();
        await tour.start();
        tour.stop();

        // Snapshot antigo do histórico: prop sem o estado novo e preferências vazias.
        inertia.props.tour = { ...inertia.props.tour, seen: false };
        inertia.props.auth.user.preferences = {};

        expect(tour.seen.value).toBe(true);
        expect(sessionStorage.getItem(`ee-tour:${id}@1`)).toBe('1');
    });
});

describe('falha ao carregar o driver.js (ex.: arquivo removido após deploy)', () => {
    it('não quebra: o botão só não abre e o erro vai para o Sentry', async () => {
        vi.resetModules();
        vi.doMock('driver.js', () => {
            throw new Error('Failed to fetch dynamically imported module');
        });
        window.Sentry = { captureException: vi.fn() };

        try {
            const { usePanelTour: fresh } = await import('@/composables/usePanelTour.js');
            inertia.props.tour = { id: 'panel:fail', version: 1, seen: false, auto: true, t: T };

            expect(await fresh().start()).toBe(false);
            expect(window.Sentry.captureException).toHaveBeenCalledOnce();
        } finally {
            vi.doUnmock('driver.js');
            delete window.Sentry;
        }
    });
});
