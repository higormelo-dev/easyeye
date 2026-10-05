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
        // CSP do checkout (Minha assinatura, /subscription/expired, /register,
        // IA → Uso e créditos): o Cypress tira o header por padrão. Mantemos
        // tudo menos default-src/script-src/script-src-elem — o Cypress põe um
        // nonce no script-src para injetar o runner e, com nonce, o navegador
        // ignora o 'unsafe-inline' da página (bloquearia o Ziggy/@routes, o
        // que não acontece fora do Cypress). connect-src (Reverb, APIs),
        // img-src, frame-src, style-src, font-src, media-src e worker-src
        // valem como em produção; violação vira falha (support/e2e.js).
        experimentalCspAllowList: ['child-src', 'frame-src', 'form-action'],
        viewportWidth: 1366,
        viewportHeight: 850,
    },
});
