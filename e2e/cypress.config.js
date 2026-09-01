const { defineConfig } = require('cypress');

module.exports = defineConfig({
  e2e: {
    baseUrl: process.env.CYPRESS_BASE_URL || 'http://localhost:8085',
    specPattern: 'cypress/e2e/**/*.cy.js',
    // Geradores de screenshots dos manuais (docs/) rodam SOB DEMANDA com seed
    // próprio — fora da suíte de regressão padrão.
    excludeSpecPattern: 'cypress/e2e/docs/**',
    supportFile: 'cypress/support/e2e.js',
    video: false,
    screenshotOnRunFailure: true,
    retries: { runMode: 1, openMode: 0 },
    defaultCommandTimeout: 10000,
    viewportWidth: 1366,
    viewportHeight: 850,
  },
});
