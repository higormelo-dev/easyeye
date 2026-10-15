import { reactive } from 'vue';
import { vi } from 'vitest';

/**
 * Mock de @inertiajs/vue3 para as telas do Revisor Clínico: useForm COM ESTADO (data,
 * defaults, reset, transform, post/put) e página reativa (props.flash/errors/locale/locales).
 * O router só registra as chamadas; o teste decide o que o servidor "responde" chamando os
 * callbacks (onError/onSuccess/onFinish) guardados em `router.post.mock.calls`.
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

export const page = reactive({
    props: {
        locale: 'pt_BR',
        locales: [
            { code: 'pt_BR', native: 'Português', flag: '🇧🇷', active: true },
            { code: 'en', native: 'English', flag: '🇺🇸', active: false },
        ],
        flash: {},
        errors: {},
    },
});

const clone = (value) => JSON.parse(JSON.stringify(value ?? null));

export function useForm(initial) {
    const keys = Object.keys(initial);
    let defaults = clone(initial);
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
        reset(...fields) {
            (fields.length ? fields : keys).forEach((key) => {
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
    });

    for (const method of ['post', 'put']) {
        form[method] = vi.fn((url, options = {}) => {
            form.lastRequest = { method, url, options, data: clone(transformer(form.data())) };
        });
    }

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
    page.props = {
        locale: 'pt_BR',
        locales: [
            { code: 'pt_BR', native: 'Português', flag: '🇧🇷', active: true },
            { code: 'en', native: 'English', flag: '🇺🇸', active: false },
        ],
        flash: {},
        errors: {},
    };
}
