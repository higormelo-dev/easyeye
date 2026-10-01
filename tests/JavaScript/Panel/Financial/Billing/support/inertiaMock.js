import { reactive } from 'vue';
import { vi } from 'vitest';

/**
 * Mock de @inertiajs/vue3 com um useForm com estado (defaults/reset/isDirty/
 * transform/post) — o mock global de tests/JavaScript/setup.js não tem esses
 * métodos. Uso: vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock()).
 *
 * `forms` guarda todos os useForm criados (na ordem), para inspecionar o
 * último post (url, options, data transformada) e simular onSuccess/erros.
 */
export const forms = [];

export const router = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    replace: vi.fn(),
};

export const page = reactive({ props: { locale: 'pt_BR', flash: {}, errors: {} } });

function snapshot(form, keys) {
    return Object.fromEntries(keys.map((key) => [key, form[key]]));
}

export function useForm(initial) {
    const keys = Object.keys(initial);
    let defaults = { ...initial };
    let transformer = (data) => data;

    const form = reactive({
        ...initial,
        errors: {},
        processing: false,
        lastPost: null,
        get isDirty() {
            return keys.some((key) => JSON.stringify(form[key]) !== JSON.stringify(defaults[key]));
        },
        defaults(values) {
            defaults = { ...defaults, ...values };
            return form;
        },
        reset() {
            Object.assign(form, defaults);
            return form;
        },
        clearErrors() {
            form.errors = {};
            return form;
        },
        setError(key, message) {
            form.errors = { ...form.errors, [key]: message };
            return form;
        },
        transform(fn) {
            transformer = fn;
            return form;
        },
        post: vi.fn((url, options = {}) => {
            form.lastPost = { url, options, data: transformer(snapshot(form, keys)) };
        }),
    });

    forms.push(form);

    return form;
}

export function buildInertiaMock() {
    return {
        router,
        useForm,
        usePage: () => page,
        Link: { name: 'Link', props: ['href', 'method'], template: '<a :href="href"><slot /></a>' },
        Head: { template: '<div><slot /></div>' },
    };
}

export function resetInertiaMock() {
    forms.length = 0;
    Object.values(router).forEach((fn) => fn.mockReset());
    page.props = { locale: 'pt_BR', flash: {}, errors: {} };
}
