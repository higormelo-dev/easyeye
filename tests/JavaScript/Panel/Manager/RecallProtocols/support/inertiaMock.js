import { reactive } from 'vue';
import { vi } from 'vitest';

/**
 * Mock de @inertiajs/vue3 com um useForm COM ESTADO (data/defaults/reset/isDirty/put/
 * clearErrors) e a página reativa (props.errors) — o mock global de tests/JavaScript/
 * setup.js não rastreia mudança. Uso (no topo do teste):
 *   vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());
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
    on: vi.fn(() => () => {}),
};

export const page = reactive({ props: { locale: 'pt_BR', flash: {}, errors: {} } });

const clone = (value) => JSON.parse(JSON.stringify(value ?? null));

export function useForm(initial) {
    const keys = Object.keys(initial);
    let defaults = clone(initial);
    // Como no Inertia: `transform(callback)` muda só o que VAI na requisição, não o estado do formulário.
    let transformer = (data) => data;

    const form = reactive({
        ...clone(initial),
        errors: {},
        processing: false,
        lastRequest: null,
        get isDirty() {
            return keys.some((key) => JSON.stringify(form[key]) !== JSON.stringify(defaults[key]));
        },
        data() {
            return Object.fromEntries(keys.map((key) => [key, form[key]]));
        },
        transform(callback) {
            transformer = callback;
            return form;
        },
        defaults(values) {
            defaults = { ...defaults, ...clone(values) };
            return form;
        },
        reset() {
            keys.forEach((key) => {
                form[key] = clone(defaults[key]);
            });
            return form;
        },
        clearErrors(...fields) {
            form.errors = fields.length
                ? Object.fromEntries(Object.entries(form.errors).filter(([key]) => !fields.includes(key)))
                : {};
            return form;
        },
        setError(key, message) {
            form.errors = { ...form.errors, [key]: message };
            return form;
        },
        put: vi.fn((url, options = {}) => {
            form.lastRequest = { method: 'put', url, options, data: clone(transformer(form.data())) };
        }),
        post: vi.fn((url, options = {}) => {
            form.lastRequest = { method: 'post', url, options, data: clone(transformer(form.data())) };
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
    Object.values(router).forEach((fn) => fn.mockReset?.());
    router.on.mockImplementation(() => () => {});
    page.props = { locale: 'pt_BR', flash: {}, errors: {} };
}
