// Support global EasyEye E2E: comandos + captura de console.error.
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

Cypress.on('window:before:load', (win) => {
  win.__cyConsoleErrors = [];
  const original = win.console.error;
  win.console.error = function (...args) {
    try {
      win.__cyConsoleErrors.push(
        args
          .map((a) => {
            if (typeof a === 'string') return a;
            try {
              return a instanceof Error ? `${a.name}: ${a.message}` : JSON.stringify(a);
            } catch (e) {
              return String(a);
            }
          })
          .join(' ')
      );
    } catch (e) {
      /* nunca quebrar a página por causa do wrapper */
    }
    return original.apply(win.console, args);
  };
});

// Falha o teste se a página logou console.error fora da allowlist.
afterEach(() => {
  cy.window({ log: false }).then((win) => {
    const all = win.__cyConsoleErrors || [];
    const offenders = all.filter(
      (msg) =>
        !CONSOLE_ERROR_ALLOWLIST.some((entry) =>
          entry instanceof RegExp ? entry.test(msg) : msg.includes(entry)
        )
    );
    expect(offenders, `console.error inesperado:\n${offenders.join('\n')}`).to.have.length(0);
  });
});
