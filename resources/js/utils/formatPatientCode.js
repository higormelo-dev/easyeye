/**
 * Formata o código do paciente para exibição em listas.
 *
 * O código canônico gravado em `patients.code` é `PAC-0000000001` (prefixo +
 * 10 dígitos zero-padded) — formato usado na API de integradores (lookup por
 * código), seeders, exames e busca (`LIKE` no backend). NÃO mexe nesse
 * armazenamento: mudar o formato gravado quebraria lookups de hardware/API
 * externos e qualquer código já impresso/exportado.
 *
 * Puramente cosmético: tira o prefixo "PAC-" e os zeros à esquerda além do
 * mínimo, só pra listagem. Preserva pelo menos `minDigits` (padrão 4 →
 * "0001") e nunca trunca números que já passaram disso (12345 → "12345").
 *
 * @param {string|null|undefined} code
 * @param {number} [minDigits]
 * @returns {string}
 */
export function formatPatientCode(code, minDigits = 4) {
    if (!code) return '—';

    const digits = String(code).replace(/\D/g, '');

    if (!digits) return code;

    return String(parseInt(digits, 10)).padStart(minDigits, '0');
}
