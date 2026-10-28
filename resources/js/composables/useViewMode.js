import { ref } from 'vue';

/**
 * Alternância tabela/cards de uma listagem, persistida no navegador (mesmo
 * padrão de `patients_view`, `doctors_view`, `<catálogo>_view`).
 *
 * Protegido para SSR (`resources/js/ssr.js`, sem `window`) e para storage
 * bloqueado/cheio (modo privado): a preferência só não persiste, a tela não quebra.
 *
 * @param {string} storageKey chave no localStorage (ex.: 'stock_products_view')
 * @param {'table'|'cards'} fallback modo quando não há preferência salva
 */
export function useViewMode(storageKey, fallback = 'table') {
    const view = ref(read());

    function read() {
        if (typeof window === 'undefined') return fallback;

        try {
            const saved = window.localStorage.getItem(storageKey);

            return saved === 'table' || saved === 'cards' ? saved : fallback;
        } catch {
            return fallback;
        }
    }

    function setView(value) {
        if (value !== 'table' && value !== 'cards') return;

        view.value = value;
        try {
            window.localStorage.setItem(storageKey, value);
        } catch {
            // Storage bloqueado/privado — a preferência só não persiste.
        }
    }

    return { view, setView };
}
