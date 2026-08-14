import { ref, watch } from 'vue';

/**
 * Ordem de colunas de uma tabela, personalizável por usuário e persistida em
 * localStorage (mesmo padrão de `mgr_entities_view` / `patients_view` já
 * usado no painel — preferência de UI presa ao navegador/dispositivo, sem
 * precisar de tabela/endpoint novo no backend).
 *
 * Guardado defensivamente (`typeof window`) porque este composable é
 * reutilizável e pode acabar importado em código que roda durante o build
 * SSR (`resources/js/ssr.js`), onde `localStorage` não existe.
 *
 * @param {string} storageKey     chave única no localStorage (ex.: 'pat_table_columns')
 * @param {string[]} defaultOrder ordem padrão das chaves de coluna
 */
export function useColumnOrder(storageKey, defaultOrder) {
    const order = ref(loadOrder(storageKey, defaultOrder));

    watch(order, (value) => {
        if (typeof window === 'undefined') return;

        try {
            window.localStorage.setItem(storageKey, JSON.stringify(value));
        } catch {
            // Storage cheio/privado — preferência só não persiste, não quebra a tela.
        }
    }, { deep: true });

    /** Move o item de `fromIndex` pra `toIndex` (drag-and-drop ou setas). */
    function moveTo(fromIndex, toIndex) {
        if (fromIndex === toIndex) return;
        if (fromIndex < 0 || fromIndex >= order.value.length) return;
        if (toIndex < 0 || toIndex >= order.value.length) return;

        const next = [...order.value];
        const [moved] = next.splice(fromIndex, 1);
        next.splice(toIndex, 0, moved);
        order.value = next;
    }

    function reset() {
        order.value = [...defaultOrder];
    }

    return { order, moveTo, reset };
}

function loadOrder(storageKey, defaultOrder) {
    if (typeof window === 'undefined') return [...defaultOrder];

    try {
        const raw = window.localStorage.getItem(storageKey);
        if (!raw) return [...defaultOrder];

        const parsed = JSON.parse(raw);

        // Só aceita se for exatamente o mesmo conjunto de chaves do default —
        // evita ordem quebrada (colunas somem/duplicam) se um deploy futuro
        // renomear/remover/adicionar uma coluna e o localStorage antigo ficar
        // com um conjunto diferente.
        const isValid = Array.isArray(parsed)
            && parsed.length === defaultOrder.length
            && defaultOrder.every((key) => parsed.includes(key));

        return isValid ? parsed : [...defaultOrder];
    } catch {
        return [...defaultOrder];
    }
}
