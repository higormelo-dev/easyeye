/**
 * Ordem de uma coluna do "Meu prontuário" (layout personalizado do médico).
 *
 * Mantém a ordem salva pelo médico só com as seções que (ainda) pertencem a
 * esta coluna. As que faltam no modelo salvo — seção nova, ou que mudou de
 * coluna (ex.: "A/V com correção" passou para a esquerda, logo abaixo de
 * "A/V sem correção") — entram na POSIÇÃO PADRÃO: logo depois da seção que as
 * antecede no padrão EasyEye. Nenhuma seção some por causa de um modelo
 * salvo antes da mudança.
 *
 * @param {string[]} defaults chaves da coluna, na ordem padrão
 * @param {unknown}  saved    ordem salva (esperado: string[])
 * @returns {string[]}
 */
export function recordColumnOrder(defaults, saved) {
    const order = (Array.isArray(saved) ? saved : [])
        .filter((key, index, list) => defaults.includes(key) && list.indexOf(key) === index);

    defaults.forEach((key, index) => {
        if (order.includes(key)) return;

        const previous = defaults.slice(0, index).reverse().find((candidate) => order.includes(candidate));
        order.splice(previous === undefined ? 0 : order.indexOf(previous) + 1, 0, key);
    });

    return order;
}
