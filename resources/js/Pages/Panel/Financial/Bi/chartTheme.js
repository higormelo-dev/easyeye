/**
 * Tema dos gráficos do BI (Chart.js) — cores SEMPRE lidas das variáveis CSS do
 * Bootstrap 5.3 (--bs-success, --bs-border-color…), nunca hex fixo no código:
 * claro/escuro e a paleta da clínica vêm do CSS. Mesmo ciclo do MrrTrendChart
 * (reconstrói ao trocar `data-bs-theme` no <html>).
 *
 * Sem CSS carregado (testes) as leituras voltam `undefined` e o Chart.js usa o
 * padrão dele.
 */

/** Valor de uma custom property vista pelo elemento (herda de .card, :root…), ou undefined. */
export function cssVar(element, name) {
    if (typeof window === 'undefined' || !element || typeof window.getComputedStyle !== 'function') return undefined;

    const value = window.getComputedStyle(element).getPropertyValue(name).trim();

    return value || undefined;
}

/** Cor de um tom semântico do Bootstrap ('success' → --bs-success). */
export function toneColor(element, tone) {
    return cssVar(element, `--bs-${tone}`);
}

/** Cores de apoio (texto, grade, superfície do tooltip) do tema atual. */
export function chartChrome(element) {
    return {
        text:     cssVar(element, '--bs-secondary-color'),
        body:     cssVar(element, '--bs-body-color'),
        emphasis: cssVar(element, '--bs-emphasis-color') ?? cssVar(element, '--bs-body-color'),
        grid:     cssVar(element, '--bs-border-color-translucent') ?? cssVar(element, '--bs-border-color'),
        border:   cssVar(element, '--bs-border-color'),
        surface:  cssVar(element, '--bs-card-bg') ?? cssVar(element, '--bs-body-bg'),
    };
}

/** Tooltip no padrão do painel (superfície do card, borda do tema). */
export function tooltipTheme(chrome) {
    return {
        backgroundColor: chrome.surface,
        titleColor:      chrome.emphasis,
        bodyColor:       chrome.body,
        borderColor:     chrome.border,
        borderWidth:     1,
        padding:         10,
    };
}

/** Respeita "reduzir movimento" do sistema operacional (sem animação do Chart.js). */
export function prefersReducedMotion() {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Chama `callback` quando o tema (data-bs-theme do <html>) muda. Devolve a função que para de observar. */
export function observeTheme(callback) {
    if (typeof MutationObserver === 'undefined' || typeof document === 'undefined') return () => {};

    const observer = new MutationObserver(() => callback());
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });

    return () => observer.disconnect();
}
