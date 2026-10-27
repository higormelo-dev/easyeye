// Medição de densidade dos controles (design-system/MASTER.md §1 e §6; plano de adoção, D4).
//
// Dentro de uma região `[data-ee-density]` todo controle de uma linha tem a MESMA altura externa: 40 px em
// tela larga com mouse e 44 px em toque ou largura < 576 px. Este módulo mede a tela renderizada (não a conta
// do SCSS, que `tests/JavaScript/Components/ControlDensity.test.js` já prova). Precedente de medição em
// navegador: profiles/billing.cy.js. Usado por e2e/design-system/density.cy.js.
//
// Mede `offsetHeight` (altura de layout, com borda): `getBoundingClientRect()` mudaria com a transição de
// `transform: scale()` dos modais ainda em andamento.

const TOLERANCE_PX = 1;
const TEXTAREA_MIN_PX = 96;

// Controles de formulário e botões (inclui `a.btn` e `label.btn`; `.page-link` é o botão da paginação).
const CONTROL_SELECTOR = [
    'button',
    '.btn',
    '.page-link',
    '.page-header-toggle',
    '.multiselect',
    'input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=color])',
    'select',
    '.form-control:not(textarea)',
    '.form-select',
].join(', ');

// O que a região deixa como está por contrato (MASTER §1): ação mínima em chip, link de texto e fechar.
const CONTRACT_EXEMPT = '.btn-xs, .btn-link, .btn-close';

// Fora da medida por desenho, cada um com o motivo:
//  - `.dropdown-item`: o item do menu tem asserção própria (assertDropdownDensity);
//  - `thead th > button`: o botão de ordenação do cabeçalho é texto sem caixa (o alvo é a célula);
//  - `.page-header-toggle > button`: o contêiner (Tabela/Cards) tem borda de 1px e os botões de dentro descontam
//    2px para a caixa EXTERNA fechar na medida (_control-tokens.scss); a medida vale no contêiner, que entra
//    no CONTROL_SELECTOR;
//  - `.multiselect-search`, `.multiselect-tags-search`: o campo de busca de DENTRO do SearchSelect (@vueform/multiselect)
//    fica 2px abaixo do contêiner `.multiselect` (borda do wrapper); o contêiner tem a medida externa e entra no
//    CONTROL_SELECTOR.
const NOT_A_FIELD_CONTROL =
    '.dropdown-item, thead th > button, thead th > .btn-link, .page-header-toggle > button, .multiselect-search, .multiselect-tags-search';

const VISUALLY_HIDDEN = '.visually-hidden, .sr-only, [hidden]';

function isRendered(el) {
    const style = el.ownerDocument.defaultView.getComputedStyle(el);
    return (
        style.display !== 'none' &&
        style.visibility !== 'hidden' &&
        el.getClientRects().length > 0 &&
        el.offsetHeight > 1 &&
        !el.closest(VISUALLY_HIDDEN)
    );
}

// O controle passa da borda direita da janela sem estar dentro de uma área com rolagem própria (tabela
// responsiva): um ancestral com `overflow` esconde o excesso e o `scrollWidth` da página não acusa.
function overflowsViewport(el) {
    const win = el.ownerDocument.defaultView;
    if (el.getBoundingClientRect().right <= win.innerWidth + TOLERANCE_PX) return false;

    for (let parent = el.parentElement; parent; parent = parent.parentElement) {
        const { overflowX } = win.getComputedStyle(parent);
        if (overflowX === 'auto' || overflowX === 'scroll') return false;
    }
    return true;
}

// Linhas de texto que o controle exibe: altura do conteúdo (sem padding e borda) ÷ line-height. Um botão com
// rótulo longo que quebra em duas linhas cresce por desenho (a altura da região é MÍNIMA); o D4 só exige a
// medida exata de controle de UMA linha, e o piso vale para todos.
function textLines(el) {
    const style = el.ownerDocument.defaultView.getComputedStyle(el);
    const lineHeight = parseFloat(style.lineHeight);
    if (!lineHeight) return 1;

    const chrome = ['paddingTop', 'paddingBottom', 'borderTopWidth', 'borderBottomWidth'].reduce(
        (sum, side) => sum + (parseFloat(style[side]) || 0),
        0,
    );
    return Math.round((el.offsetHeight - chrome) / lineHeight);
}

function describeEl(el) {
    const classes = [...el.classList].slice(0, 3).join('.');
    const text = (el.getAttribute('aria-label') || el.getAttribute('title') || el.textContent || '')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 28);
    return `${el.tagName.toLowerCase()}${classes ? `.${classes}` : ''}${text ? ` "${text}"` : ''}`;
}

// Achados do teste em curso. `assertDensity` e companhia só ANOTAM aqui: o teste continua e falha uma vez, no
// fim (`expectNoDensityIssues`), listando tudo — uma página com vários controles fora da medida não esconde o
// resto atrás do primeiro. Zere no `beforeEach`.
let issues = [];
// O que foi medido (escopo, controles, textarea): o spec grava em cypress/results/ (fora do git) como evidência.
const measured = [];

function measuredSummary() {
    return measured.map((entry) => ({ ...entry }));
}

function resetDensityIssues() {
    issues = [];
}

function record(label, lines) {
    lines.forEach((line) => issues.push(`${label}: ${line}`));
}

function expectNoDensityIssues() {
    return cy.then(() => {
        const found = [...issues];
        issues = [];
        expect(
            found,
            `${found.length} achado(s) de densidade:\n${found.map((line) => `  - ${line}`).join('\n')}`,
        ).to.have.length(0);
    });
}

/**
 * Mede os controles visíveis dentro dos escopos e devolve o que mediu e o que ficou fora da medida.
 * Função pura sobre o DOM: não chama cy.* (testável e chamável dentro de um `.then`).
 */
function measure(scopes, { control }) {
    const violations = [];
    let checked = 0;
    let textareas = 0;
    const multilineControls = [];
    const seen = new Set();

    scopes.forEach((scope) => {
        scope.querySelectorAll(CONTROL_SELECTOR).forEach((el) => {
            if (seen.has(el)) return;
            seen.add(el);
            if (!isRendered(el) || el.matches(CONTRACT_EXEMPT) || el.matches(NOT_A_FIELD_CONTROL)) return;

            if (!el.closest('[data-ee-density]')) {
                violations.push(`${describeEl(el)}: fora de uma região data-ee-density`);
                return;
            }

            checked += 1;
            if (overflowsViewport(el)) {
                violations.push(`${describeEl(el)}: passa da largura da janela (cortado ou fora da tela)`);
            }
            const height = el.offsetHeight;
            const multiline = height > control + TOLERANCE_PX && textLines(el) > 1;
            if (height < control - TOLERANCE_PX || (height > control + TOLERANCE_PX && !multiline)) {
                violations.push(`${describeEl(el)}: ${height}px (esperado ${control}px ±${TOLERANCE_PX})`);
            }
            if (multiline) multilineControls.push(`${describeEl(el)}: ${height}px em ${textLines(el)} linhas`);
        });

        scope.querySelectorAll('textarea').forEach((el) => {
            if (seen.has(el)) return;
            seen.add(el);
            if (!isRendered(el)) return;
            if (!el.closest('[data-ee-density]')) {
                violations.push(`${describeEl(el)}: fora de uma região data-ee-density`);
                return;
            }

            textareas += 1;
            if (el.offsetHeight < TEXTAREA_MIN_PX - TOLERANCE_PX) {
                violations.push(`${describeEl(el)}: ${el.offsetHeight}px (esperado ≥ ${TEXTAREA_MIN_PX}px)`);
            }
        });
    });

    return { checked, textareas, violations, multilineControls };
}

/**
 * assertDensity(scope, { control: 40, label: 'Médicos / lista' })
 * `allowEmpty`: o escopo pode não ter controle nenhum (drawer só de leitura; o `.btn-close` é isento).
 * `scope` é um seletor CSS ou um elemento jQuery; todo controle visível dentro dele precisa estar numa região
 * e medir `control` px (±1); `textarea` mede ≥ 96 px. Falha listando cada controle fora da medida.
 */
function assertDensity(scope, { control, label, allowEmpty = false }) {
    const resolve = typeof scope === 'string' ? cy.get(scope) : cy.wrap(scope);

    return resolve.then(($scopes) => {
        const report = measure($scopes.toArray(), { control });

        cy.log(`**densidade** ${label}: ${report.checked} controles, ${report.textareas} textarea`);
        measured.push({
            label,
            controls: report.checked,
            textareas: report.textareas,
            issues: report.violations.length,
            multiline: report.multilineControls,
        });
        if (report.checked === 0 && !allowEmpty) {
            record(label, ['nenhum controle medido (escopo ou região errados?)']);
        }
        record(label, report.violations);
    });
}

/**
 * Menu do ActionDropdown aberto (teleportado para o <body>): precisa ter herdado a região e cada item deve
 * medir pelo menos `control` px (item de menu pode crescer com texto longo, nunca ficar abaixo).
 */
function assertDropdownDensity({ control, label }) {
    return cy.get('ul.dropdown-menu.show').then(($menu) => {
        const menu = $menu[0];
        const violations = [];
        const items = [...menu.querySelectorAll('.dropdown-item')].filter(isRendered);

        if (!menu.hasAttribute('data-ee-density')) violations.push('menu sem data-ee-density (não herdou a região)');
        items.forEach((item) => {
            if (item.offsetHeight < control - TOLERANCE_PX) {
                violations.push(`${describeEl(item)}: ${item.offsetHeight}px (esperado ≥ ${control}px)`);
            }
        });

        cy.log(`**densidade** ${label}: menu com ${items.length} itens`);
        measured.push({ label, menuItems: items.length, issues: violations.length });
        if (items.length === 0) violations.push('menu aberto sem itens medidos');
        record(label, violations);
    });
}

/** Os elementos que mais passam da borda direita (fora de área com rolagem própria): a causa da rolagem lateral. */
function widestOffenders(win, limit = 4) {
    const offenders = [];

    win.document.body.querySelectorAll('*').forEach((el) => {
        const rect = el.getBoundingClientRect();
        if (rect.width === 0 || rect.right <= win.innerWidth + TOLERANCE_PX) return;
        if (win.getComputedStyle(el).position === 'fixed' || !isRendered(el)) return;
        for (let parent = el.parentElement; parent; parent = parent.parentElement) {
            const { overflowX } = win.getComputedStyle(parent);
            if (overflowX === 'auto' || overflowX === 'scroll') return;
        }
        offenders.push({ el, right: Math.round(rect.right) });
    });

    return offenders
        .sort((a, b) => b.right - a.right)
        .slice(0, limit)
        .map(({ el, right }) => `${describeEl(el)} (borda direita em ${right}px)`);
}

/** Sem rolagem lateral: a página inteira cabe na largura da janela. */
function assertNoHorizontalScroll(label) {
    return cy.window({ log: false }).then((win) => {
        const doc = win.document.documentElement;
        if (doc.scrollWidth > win.innerWidth) {
            record(label, [
                `rolagem lateral: scrollWidth ${doc.scrollWidth} > innerWidth ${win.innerWidth}; ` +
                    `mais largos: ${widestOffenders(win).join('; ') || '(nenhum elemento fora da borda)'}`,
            ]);
        }
    });
}

module.exports = {
    assertDensity,
    assertDropdownDensity,
    assertNoHorizontalScroll,
    expectNoDensityIssues,
    measure,
    measuredSummary,
    resetDensityIssues,
};
