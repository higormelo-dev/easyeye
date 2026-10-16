const { defineConfig } = require('cypress');
const path = require('path');
const { spawnSync } = require('child_process');

// Sandbox real é opt-in: nunca pode atingir o gateway numa regressão local ou CI.
const includeSandbox = process.env.CYPRESS_SANDBOX === '1';

function hasAsaasSandboxConfig() {
    // Resolve a credencial efetiva (env ou Manager); não confia no .env nem
    // devolve segredo. O comando sai 0 e escreve "sandbox" só no ambiente seguro.
    const result = spawnSync('php', ['artisan', 'billing:gateway-environment', 'asaas'], {
        cwd: path.resolve(__dirname, '..'),
        encoding: 'utf8',
    });

    return result.status === 0 && result.stdout.trim() === 'sandbox';
}

module.exports = defineConfig({
    e2e: {
        baseUrl: process.env.CYPRESS_BASE_URL || 'http://localhost:8085',
        specPattern: includeSandbox ? 'cypress/e2e/sandbox/**/*.cy.js' : 'cypress/e2e/**/*.cy.js',
        // Geradores de manuais e a homologação real do Asaas rodam SOB DEMANDA.
        // CYPRESS_SANDBOX=1 libera apenas a pasta sandbox; docs continua explícito.
        excludeSpecPattern: ['cypress/e2e/docs/**', ...(includeSandbox ? [] : ['cypress/e2e/sandbox/**'])],
        setupNodeEvents(on) {
            on('task', {
                // Não exponha URL/chave no browser nem nos logs do Cypress.
                asaasSandboxConfigured: hasAsaasSandboxConfig,
            });
        },
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
