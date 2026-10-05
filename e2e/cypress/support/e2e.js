// Support global EasyEye E2E: comandos + captura de console.error e de violação de CSP.
require('./commands');

/**
 * Allowlist de console.error tolerados (substring ou RegExp).
 * Começa VAZIA de propósito: qualquer console.error é falha
 * (inclui o marker de rota Ziggy quebrada: "[AppLayout] Rota de menu inválida").
 * Só adicionar entradas com comentário justificando o ruído (ex.: lib de terceiro).
 */
const CONSOLE_ERROR_ALLOWLIST = [
    // (vazio)
];

// Erros de TODAS as páginas carregadas no teste (não só da última): um erro
// numa tela intermediária (ex.: a que redireciona) sumia com a navegação.
let testErrors = [];

beforeEach(() => {
    testErrors = [];
});

function record(win, message) {
    let path = '';
    try {
        path = win.location.pathname;
    } catch (e) {
        /* about:blank em troca de página */
    }
    win.__cyConsoleErrors.push(message);
    testErrors.push(path ? `${message} [${path}]` : message);
}

// Tour guiado do painel (driver.js, usePanelTour): abre sozinho no 1º acesso
// ao Dashboard de quem ainda não o viu e cobre a tela (pointer-events: none
// no #app) — quebrava qualquer clique dos specs. Marca como visto NESTA aba
// (a mesma chave de sessionStorage que o app grava ao abrir o tour), sem
// mexer na preferência do usuário no banco. Um por perfil de clínica e
// por versão (subir PanelTour::VERSION não volta a quebrar os specs).
const TOUR_RULES = ['admin', 'financial', 'doctor', 'secretary', 'user'];

function skipPanelTour(win) {
    try {
        TOUR_RULES.forEach((rule) => {
            for (let version = 1; version <= 50; version += 1) {
                win.sessionStorage.setItem(`ee-tour:panel:${rule}@${version}`, '1');
            }
        });
    } catch (e) {
        /* storage indisponível: o tour pode aparecer */
    }
}

Cypress.on('window:before:load', (win) => {
    win.__cyConsoleErrors = [];
    skipPanelTour(win);
    const original = win.console.error;
    win.console.error = function (...args) {
        try {
            record(
                win,
                args
                    .map((a) => {
                        if (typeof a === 'string') return a;
                        try {
                            return a instanceof Error ? `${a.name}: ${a.message}` : JSON.stringify(a);
                        } catch (e) {
                            return String(a);
                        }
                    })
                    .join(' '),
            );
        } catch (e) {
            /* nunca quebrar a página por causa do wrapper */
        }
        return original.apply(win.console, args);
    };

    // Violação de Content-Security-Policy também é falha (a página do
    // checkout envia a CSP — cypress.config.js mantém o header com
    // experimentalCspAllowList). O navegador não passa isso pelo
    // console.error da página: o evento securitypolicyviolation é o caminho.
    win.addEventListener('securitypolicyviolation', (event) => {
        try {
            record(
                win,
                `[CSP] ${event.effectiveDirective || event.violatedDirective} bloqueou ` +
                    `${event.blockedURI || 'inline'} (${event.sourceFile || win.location.pathname}` +
                    `${event.lineNumber ? `:${event.lineNumber}` : ''})`,
            );
        } catch (e) {
            /* idem */
        }
    });
});

// Falha o teste se alguma página do teste logou console.error (ou violou a
// CSP) fora da allowlist.
afterEach(() => {
    cy.window({ log: false }).then(() => {
        const offenders = testErrors.filter(
            (msg) =>
                !CONSOLE_ERROR_ALLOWLIST.some((entry) =>
                    entry instanceof RegExp ? entry.test(msg) : msg.includes(entry),
                ),
        );
        expect(offenders, `console.error inesperado:\n${offenders.join('\n')}`).to.have.length(0);
    });
});
