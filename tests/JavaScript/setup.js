import { config } from '@vue/test-utils';
import { vi } from 'vitest';

/**
 * Setup global para testes Vue/Inertia.
 *
 * - Mocka `route()` (Ziggy) globalmente — retorna URL fake previsível
 * - Mocka `fetch()` por padrão — testes individuais sobrescrevem conforme necessidade
 * - Stub global do <Link> do Inertia (não queremos importar @inertiajs/vue3 real nos testes unitários)
 * - Helper `flushPromises` exposto para await microtasks
 */

// Helper Ziggy: route('name', params) -> '/route/name?params'
globalThis.route = vi.fn((name, params) => {
    if (!params) return `/_routes/${name}`;
    if (typeof params === 'object') {
        const qs = new URLSearchParams(params).toString();
        return `/_routes/${name}?${qs}`;
    }
    return `/_routes/${name}/${params}`;
});

// fetch mock padrão (cada teste pode sobrescrever)
globalThis.fetch = vi.fn(() =>
    Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({}),
    }),
);

// Stub do TinyMCE: o wrapper TinyMceEditor.vue injeta um <script> para carregar
// o vendor, mas o happy-dom bloqueia carregamento de script (DOMException). Ao
// pré-popular `window.tinymce`, o loader curto-circuita (sem appendChild) e
// inicializa um editor fake — zero ruído no stderr, sem rejeição pendente.
window.tinymce = {
    init: (cfg) => {
        const editor = {
            _content: '',
            getContent: () => editor._content,
            setContent: (v) => { editor._content = v ?? ''; },
            mode: { set: () => {} },
            remove: () => {},
            on: () => {},
        };
        if (typeof cfg?.setup === 'function') cfg.setup(editor);
        return Promise.resolve([editor]);
    },
};

// CSRF token meta tag (alguns componentes leem dele)
const csrfMeta = document.createElement('meta');
csrfMeta.setAttribute('name', 'csrf-token');
csrfMeta.setAttribute('content', 'test-csrf-token');
document.head.appendChild(csrfMeta);

// Stub global para componentes Inertia comuns
config.global.stubs = {
    Link: {
        template: '<a><slot /></a>',
        props: ['href', 'method', 'as', 'data', 'preserveScroll', 'preserveState', 'only', 'replace'],
    },
    Head: { template: '<div data-test-head><slot /></div>' },
};

// Mock comum do @inertiajs/vue3 para testes que importam usePage/router
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            auth: { user: { id: 'test-user-id', name: 'Test User', email: 'test@example.com' } },
            flash: {},
            errors: {},
        },
    }),
    router: {
        get:    vi.fn(),
        post:   vi.fn(),
        put:    vi.fn(),
        patch:  vi.fn(),
        delete: vi.fn(),
        reload: vi.fn(),
        visit:  vi.fn(),
    },
    Link: { template: '<a><slot /></a>', props: ['href'] },
    Head: { template: '<div><slot /></div>' },
    useForm: (initialData) => ({
        ...initialData,
        processing: false,
        errors: {},
        reset: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
    }),
}));
