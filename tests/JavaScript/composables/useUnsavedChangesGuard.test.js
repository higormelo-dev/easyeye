import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { mount, enableAutoUnmount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard.js';
import { installPopstateGuard } from '@/Support/popstateGuard.js';

/**
 * Confirmação antes de sair com alterações não salvas: links/visitas do
 * Inertia, recarregar/fechar a aba e Voltar/Avançar do navegador (o Inertia
 * troca a página no popstate sem disparar o evento `before`).
 *
 * Ordem dos listeners como no navegador: o do popstateGuard é instalado no
 * bootstrap (panel.js), antes do Inertia; o do "Inertia" é registrado depois.
 */
vi.mock('@inertiajs/vue3', () => ({ router: { on: vi.fn(() => vi.fn()) } }));

installPopstateGuard();

const OWN_URL = '/panel/patients/p1/medicalrecords/r1/edit';
const ownState = { page: { url: OWN_URL, component: 'Panel/MedicalRecords/Edit' } };
const previousState = { page: { url: '/panel/patients/p1/medicalrecords', component: 'Panel/MedicalRecords/Index' } };

let inertiaPopstate;

enableAutoUnmount(afterEach);

function mountGuard(dirty = false, useGuard = useUnsavedChangesGuard) {
    const isDirty = ref(dirty);
    let api;
    const Host = defineComponent({
        setup() {
            api = useGuard({ isDirty: () => isDirty.value, message: () => 'Sair sem salvar?' });

            return () => h('div');
        },
    });

    return { isDirty, wrapper: mount(Host), api: () => api };
}

const handler = (type) =>
    vi
        .mocked(router.on)
        .mock.calls.filter(([name]) => name === type)
        .at(-1)[1];
const fireBefore = (visit = { method: 'get' }) => handler('before')({ detail: { visit } });

// Voltar do navegador: a URL já mudou quando o popstate chega.
function pressBack() {
    window.history.replaceState(previousState, '', previousState.page.url);
    window.dispatchEvent(new PopStateEvent('popstate', { state: previousState }));
}

beforeEach(() => {
    window.history.replaceState(ownState, '', OWN_URL);
    // Simula o listener do Inertia (registrado no createInertiaApp, depois do
    // popstateGuard e antes de a página montar).
    inertiaPopstate = vi.fn();
    window.addEventListener('popstate', inertiaPopstate);
    window.confirm = vi.fn(() => false);
    vi.mocked(router.on).mockClear();
});

afterEach(() => {
    window.removeEventListener('popstate', inertiaPopstate);
});

describe('useUnsavedChangesGuard — visitas do Inertia e aba', () => {
    it('sem alterações segue direto; com alterações pergunta e respeita a resposta', () => {
        const { isDirty } = mountGuard();

        expect(fireBefore()).toBeUndefined();
        expect(window.confirm).not.toHaveBeenCalled();

        isDirty.value = true;
        expect(fireBefore()).toBe(false);
        expect(window.confirm).toHaveBeenCalledWith('Sair sem salvar?');

        window.confirm = vi.fn(() => true);
        expect(fireBefore()).toBeUndefined();
    });

    it('prefetch e visitas da própria tela (bypass) não perguntam', () => {
        const { api } = mountGuard(true);

        expect(fireBefore({ method: 'get', prefetch: true })).toBeUndefined();
        expect(api().bypass(() => fireBefore({ method: 'put' }))).toBeUndefined();
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('saída já confirmada (logout/troca de clínica recarregam a página) não pergunta de novo no beforeunload', () => {
        window.confirm = vi.fn(() => true);
        mountGuard(true);
        const unload = () => {
            const event = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(event);
            return event.defaultPrevented;
        };

        expect(fireBefore({ method: 'post' })).toBeUndefined();
        expect(unload()).toBe(false);

        // A visita terminou nesta mesma tela: as alterações seguem protegidas.
        handler('error')();
        expect(unload()).toBe(true);

        fireBefore({ method: 'post' });
        handler('success')();
        expect(unload()).toBe(true);
    });

    it('recarregar/fechar a aba com alterações aciona o aviso do navegador', () => {
        mountGuard(true);
        const event = new Event('beforeunload', { cancelable: true });

        window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });
});

describe('useUnsavedChangesGuard — Voltar/Avançar do navegador', () => {
    it('sem alterações o Inertia troca a página normalmente', () => {
        mountGuard();
        pressBack();

        expect(window.confirm).not.toHaveBeenCalled();
        expect(inertiaPopstate).toHaveBeenCalledOnce();
    });

    it('com alterações e "Cancelar": fica na página, com a URL e o histórico do prontuário', () => {
        mountGuard(true);
        const entries = window.history.length;
        pressBack();

        expect(window.confirm).toHaveBeenCalledWith('Sair sem salvar?');
        expect(inertiaPopstate).not.toHaveBeenCalled();
        expect(window.location.pathname).toBe(OWN_URL);
        expect(window.history.state).toEqual(ownState);
        // Entrada NOVA (pushState): o Voltar seguinte ainda chega à página anterior.
        expect(window.history.length).toBe(entries + 1);
    });

    it('Voltar para outra entrada da MESMA URL (ex.: link "#" no breadcrumb) também é saída: o Inertia remontaria a tela', () => {
        mountGuard(true);
        const olderSameUrl = { page: { ...ownState.page, props: { versao: 'anterior' } } };
        window.history.replaceState(olderSameUrl, '', OWN_URL);
        window.dispatchEvent(new PopStateEvent('popstate', { state: olderSameUrl }));

        expect(window.confirm).toHaveBeenCalledWith('Sair sem salvar?');
        expect(inertiaPopstate).not.toHaveBeenCalled();
        expect(window.history.state).toEqual(ownState);
    });

    it('depois de salvar na mesma URL, "Cancelar" repõe a página JÁ salva (retrato atualizado no success)', () => {
        mountGuard(true);
        const saved = { page: { ...ownState.page, props: { salvo: true } } };
        window.history.replaceState(saved, '', OWN_URL);
        handler('success')();

        pressBack();

        expect(window.history.state).toEqual(saved);
    });

    it('troca de URL sem remontar (navigate) também atualiza o retrato', () => {
        mountGuard(true);
        const moved = { page: { ...ownState.page, url: `${OWN_URL}?aba=docs` } };
        window.history.replaceState(moved, '', moved.page.url);
        handler('navigate')();

        pressBack();

        expect(window.location.search).toBe('?aba=docs');
        expect(window.history.state).toEqual(moved);
    });

    it('com alterações e "OK": o Inertia segue para a página anterior', () => {
        window.confirm = vi.fn(() => true);
        mountGuard(true);
        pressBack();

        expect(inertiaPopstate).toHaveBeenCalledOnce();
        expect(window.location.pathname).toBe(previousState.page.url);
    });

    it('âncora na mesma página (#) não é saída', () => {
        mountGuard(true);
        window.history.replaceState(ownState, '', `${OWN_URL}#refracao`);
        window.dispatchEvent(new PopStateEvent('popstate', { state: null }));

        expect(window.confirm).not.toHaveBeenCalled();
        expect(inertiaPopstate).toHaveBeenCalledOnce();
    });

    it('ao desmontar, para de interceptar', () => {
        const { wrapper } = mountGuard(true);
        wrapper.unmount();
        pressBack();

        expect(window.confirm).not.toHaveBeenCalled();
        expect(inertiaPopstate).toHaveBeenCalledOnce();
    });
});

describe('useUnsavedChangesGuard — link comum (iOS não dispara beforeunload)', () => {
    let decision;
    const keepPage = (event) => {
        // Registra o que o guard decidiu e impede o happy-dom de navegar.
        decision = event.defaultPrevented ? 'ficou' : 'saiu';
        event.preventDefault();
    };

    function clickLink(attrs = {}, init = {}, before = null) {
        const link = document.createElement('a');
        Object.entries({ href: '/panel/schedules', ...attrs }).forEach(([k, v]) => link.setAttribute(k, v));
        document.body.appendChild(link);
        if (before) link.addEventListener('click', before);
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init }));
        link.remove();
        return decision;
    }

    beforeEach(() => {
        decision = null;
        window.addEventListener('click', keepPage);
    });

    afterEach(() => {
        window.removeEventListener('click', keepPage);
    });

    it('com alterações pergunta; "Cancelar" fica, "OK" sai sem o segundo aviso do navegador', () => {
        mountGuard(true);

        expect(clickLink()).toBe('ficou');
        expect(window.confirm).toHaveBeenCalledWith('Sair sem salvar?');

        window.confirm = vi.fn(() => true);
        expect(clickLink()).toBe('saiu');
        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(false);
    });

    it('sem alterações, nova aba, download, Ctrl/Cmd+clique e âncora não perguntam', () => {
        mountGuard(false);
        expect(clickLink()).toBe('saiu');

        mountGuard(true);
        expect(clickLink({ target: '_blank' })).toBe('saiu');
        expect(clickLink({ download: '' })).toBe('saiu');
        expect(clickLink({}, { ctrlKey: true })).toBe('saiu');
        expect(clickLink({}, { metaKey: true })).toBe('saiu');
        expect(clickLink({ href: '#' })).toBe('saiu');
        expect(clickLink({ href: `${OWN_URL}#refracao` })).toBe('saiu');
        expect(clickLink({ href: 'javascript:void(0)' })).toBe('saiu');
        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('<Link> do Inertia (clique já com preventDefault) fica com o aviso do `before`, sem pergunta dupla', () => {
        mountGuard(true);

        clickLink({}, {}, (event) => event.preventDefault());

        expect(window.confirm).not.toHaveBeenCalled();
    });

    it('link para a própria página sem âncora recarrega: é saída', () => {
        mountGuard(true);

        expect(clickLink({ href: OWN_URL })).toBe('ficou');
    });
});

describe('useUnsavedChangesGuard — instalação do listener do Voltar', () => {
    it('sem a instalação no bootstrap, não intercepta (interceptar depois do Inertia quebraria a tela)', async () => {
        vi.resetModules();
        const fresh = await import('@/composables/useUnsavedChangesGuard.js');
        mountGuard(true, fresh.useUnsavedChangesGuard);
        pressBack();

        expect(window.confirm).not.toHaveBeenCalled();
        expect(inertiaPopstate).toHaveBeenCalledOnce();
    });

    it('panel.js instala o listener antes do createInertiaApp', () => {
        const source = readFileSync(resolve(__dirname, '../../../resources/js/panel.js'), 'utf8');
        const install = source.indexOf('installPopstateGuard();');

        expect(install).toBeGreaterThan(-1);
        expect(install).toBeLessThan(source.indexOf('createInertiaApp({'));
    });
});
