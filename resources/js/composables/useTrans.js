/**
 * Lightweight translation helper for Inertia pages.
 *
 * Accepts a reactive getter (function) or a plain object.
 * When a getter is passed, `tx` always reads the latest translations,
 * so it stays correct after language switches via Inertia partial reloads.
 *
 * Usage with plain object (static, single-language):
 *   const { tx } = useTrans(props.t);
 *   tx('detail_interval_minutes', { value: 30 }) // "30 minutos"
 *
 * Usage with getter (reactive to Inertia lang changes):
 *   const { tx } = useTrans(() => props.t);
 *   tx('detail_deleted_at', { date: '01/01/2024' })
 */
export function useTrans(tOrGetter) {
    /**
     * Resolve the translation object — works for both plain objects and getters.
     * @returns {object}
     */
    function resolve() {
        return typeof tOrGetter === 'function' ? tOrGetter() : tOrGetter;
    }

    /**
     * Interpolates :param placeholders inside a translation string.
     *
     * @param {string} key    Key from the translation object
     * @param {object} params Key → value map for :placeholder substitution
     * @returns {string}
     */
    function tx(key, params = {}) {
        const t   = resolve();
        const str = String(t[key] ?? key);

        // Um placeholder = ':' + o nome INTEIRO (como no Laravel): ':page' não
        // corrompe ':pages', e um valor que contenha ':x' não é substituído de novo.
        return str.replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, (match, name) => (
            Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match
        ));
    }

    return { tx };
}
