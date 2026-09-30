/**
 * Ordem das seções do Dashboard salva nas preferências do usuário.
 *
 * Tolerante (mesma ideia dos atalhos favoritos em ModuleShortcuts.vue): as
 * chaves salvas que ainda existem ficam na ordem salva; as desconhecidas
 * saem; as novas entram no fim. Assim a ordem escolhida não volta ao padrão
 * quando uma seção aparece ou some (ex.: alertas de estoque) nem ao trocar de
 * clínica.
 *
 * @param {unknown} stored  valor salvo (esperado: string[])
 * @param {string[]} keys   todas as seções possíveis, na ordem padrão
 * @returns {string[]}
 */
export function normalizeSectionOrder(stored, keys) {
    const saved = Array.isArray(stored)
        ? stored.filter((key, index) => keys.includes(key) && stored.indexOf(key) === index)
        : [];

    return [...saved, ...keys.filter((key) => !saved.includes(key))];
}

/**
 * Move uma seção dentro da lista VISÍVEL (índices do menu de ordenar) sem
 * perder a posição das seções ocultas no momento (ex.: estoque sem alerta).
 *
 * @param {string[]} order    ordem completa (todas as seções)
 * @param {string[]} visible  seções visíveis, na ordem mostrada
 * @returns {string[]|null}   nova ordem completa, ou null se o movimento é inválido
 */
export function moveVisibleSection(order, visible, fromIndex, toIndex) {
    if (fromIndex === toIndex || fromIndex < 0 || toIndex < 0) return null;
    if (fromIndex >= visible.length || toIndex >= visible.length) return null;

    const nextVisible = [...visible];
    const [moved] = nextVisible.splice(fromIndex, 1);
    nextVisible.splice(toIndex, 0, moved);

    // Seções ocultas voltam para a posição que ocupavam na ordem completa.
    const next = [...nextVisible];
    order.forEach((key, index) => {
        if (!visible.includes(key)) next.splice(Math.min(index, next.length), 0, key);
    });

    return next;
}
