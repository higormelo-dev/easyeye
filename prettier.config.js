/**
 * Prettier do frontend (JS/Vue) — escopo em .prettierignore; PHP é do Pint.
 * Opções espelham o estilo que o código já usa (aspas simples, 4 espaços,
 * linhas > 120 colunas em ~4% do código), para o diff de adoção ser o menor
 * possível. O resto é o padrão do Prettier 3 (ponto e vírgula, vírgula final,
 * `(x) =>`, script do .vue sem indentação extra).
 *
 * @type {import('prettier').Config}
 */
export default {
    printWidth: 120,
    tabWidth: 4,
    singleQuote: true,
};
