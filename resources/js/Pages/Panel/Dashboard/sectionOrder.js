/**
 * Ordem das seções do Dashboard salva nas preferências do usuário.
 *
 * Tolerante (mesma ideia dos atalhos favoritos em ModuleShortcuts.vue): as
 * chaves salvas que ainda existem ficam na ordem salva; as desconhecidas
 * saem; as novas entram LOGO DEPOIS da seção que as precede na ordem padrão
 * do perfil (sem nenhuma antes → no começo). Assim a ordem escolhida não
 * volta ao padrão quando uma seção aparece ou some (ex.: alertas de estoque),
 * nem ao trocar de clínica — e uma seção nova (ex.: "Confirmações" da
 * recepção) não vai parar no fim da página só porque a ordem foi salva antes
 * dela existir.
 *
 * @param {unknown} stored  valor salvo (esperado: string[])
 * @param {string[]} keys   todas as seções possíveis, na ordem padrão
 * @returns {string[]}
 */
export function normalizeSectionOrder(stored, keys) {
    const saved = Array.isArray(stored)
        ? stored.filter((key, index) => keys.includes(key) && stored.indexOf(key) === index)
        : [];

    if (saved.length === 0) return [...keys];

    const result = [...saved];

    keys.forEach((key, defaultIndex) => {
        if (result.includes(key)) return;

        const before = keys
            .slice(0, defaultIndex)
            .reverse()
            .find((previous) => result.includes(previous));

        result.splice(before ? result.indexOf(before) + 1 : 0, 0, key);
    });

    return result;
}

/**
 * Seções ocultas pelo usuário ("Personalizar" → olho): só chaves conhecidas,
 * sem repetição.
 *
 * @param {unknown} stored
 * @param {string[]} keys
 * @returns {string[]}
 */
export function normalizeHiddenSections(stored, keys) {
    return Array.isArray(stored)
        ? stored.filter((key, index) => keys.includes(key) && stored.indexOf(key) === index)
        : [];
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

/**
 * Agrupa as seções em blocos de layout: seções "compactas" vizinhas dividem
 * a mesma linha (grade que se adapta à largura); as demais ocupam a linha
 * inteira.
 *
 * @param {{ key: string, size?: string }[]} sections  na ordem de exibição
 * @returns {{ type: 'full'|'compact', key: string, sections: object[] }[]}
 */
export function groupSections(sections) {
    const blocks = [];

    for (const section of sections) {
        const last = blocks[blocks.length - 1];

        if (section.size === 'compact' && last?.type === 'compact') {
            last.sections.push(section);
            last.key = `${last.key}+${section.key}`;
            continue;
        }

        blocks.push({ type: section.size === 'compact' ? 'compact' : 'full', key: section.key, sections: [section] });
    }

    return blocks;
}
