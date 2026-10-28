import { reactive } from 'vue';
import { vi } from 'vitest';

/**
 * Mock enxuto do @inertiajs/vue3 para as telas do módulo cirúrgico: `useForm`
 * reativo de verdade (campos, errors, transform, dirty) e `router` espião.
 * Cada teste faz `vi.mock('@inertiajs/vue3', () => inertiaMock())` via
 * vi.hoisted/dynamic import — ver os testes ao lado.
 */
export function inertiaMock() {
    const router = {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        reload: vi.fn(),
        visit: vi.fn(),
    };
    const submitted = [];

    function useForm(initial) {
        let defaults = JSON.parse(JSON.stringify(initial));
        let transformer = (d) => d;

        const form = reactive({
            ...JSON.parse(JSON.stringify(initial)),
            errors: {},
            processing: false,
            isDirty: false,
        });

        const data = () => {
            const { errors, processing, isDirty, ...rest } = form; // eslint-disable-line no-unused-vars
            return JSON.parse(JSON.stringify(rest));
        };

        const send =
            (method) =>
            (url, options = {}) => {
                submitted.push({ method, url, payload: transformer(data()), options });
            };

        Object.assign(form, {
            defaults(next) {
                defaults = JSON.parse(JSON.stringify(next ?? data()));
            },
            reset() {
                Object.assign(form, JSON.parse(JSON.stringify(defaults)));
                form.isDirty = false;
            },
            clearErrors() {
                form.errors = {};
            },
            transform(fn) {
                transformer = fn;
                return form;
            },
            post: send('post'),
            put: send('put'),
            patch: send('patch'),
            delete: send('delete'),
        });

        return form;
    }

    return {
        usePage: () => ({ props: { locale: 'pt_BR' } }),
        router,
        Link: { name: 'InertiaLink', props: ['href'], template: '<a :href="href"><slot /></a>' },
        Head: { template: '<div><slot /></div>' },
        useForm,
        __submitted: submitted,
        __router: router,
    };
}
