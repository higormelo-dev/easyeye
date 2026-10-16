const { defineConfig } = require('cypress');
const fs = require('fs');
const path = require('path');
const http = require('http');
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

// Rodando pelo túnel (e2e/scripts/sandbox-run.sh), o script exporta o host público do ngrok, a porta do app e a API
// local do ngrok DELE (instância própria, porta livre). Sem isso só localhost vale, como sempre.
const tunnelHost = (process.env.CYPRESS_SANDBOX_TUNNEL_HOST || '').trim().toLowerCase();

function isLoopback(hostname) {
    return ['localhost', '127.0.0.1', '::1', '[::1]'].includes(hostname);
}

// Lado do ngrok (API e destino do túnel): só IP numérico — `localhost` é nome resolvível —, como no billing:asaas-webhook.
function isNumericLoopback(hostname) {
    return ['127.0.0.1', '::1', '[::1]'].includes(hostname);
}

function isLoopbackUpstream(addr) {
    const value = String(addr ?? '').trim();

    if (value === '') return false;

    try {
        const upstream = new URL(value.includes('://') ? value : `http://${value}`);

        return (
            upstream.protocol === 'http:' &&
            isNumericLoopback(upstream.hostname) &&
            upstream.port === '8086' &&
            upstream.username === '' &&
            upstream.password === '' &&
            upstream.pathname === '/' &&
            upstream.search === '' &&
            upstream.hash === ''
        );
    } catch {
        return false;
    }
}

function ngrokApiUrl() {
    const configuredApi = String(process.env.CYPRESS_SANDBOX_NGROK_API ?? '').trim();

    if (configuredApi !== '') {
        try {
            const api = new URL(configuredApi);

            return api.protocol === 'http:' &&
                isNumericLoopback(api.hostname) &&
                api.pathname === '/api/tunnels' &&
                api.search === '' &&
                api.hash === '' &&
                api.username === '' &&
                api.password === ''
                ? api
                : null;
        } catch {
            return null;
        }
    }

    const webPort = String(process.env.CYPRESS_SANDBOX_NGROK_WEB || '4040');

    if (!/^\d{1,5}$/.test(webPort) || Number(webPort) < 1 || Number(webPort) > 65535) {
        return null;
    }

    return new URL(`http://127.0.0.1:${webPort}/api/tunnels`);
}

function ngrokTunnels() {
    return new Promise((resolve) => {
        // Só a API local do agente: este task nunca fala com host remoto.
        const api = ngrokApiUrl();

        if (!api) return resolve([]);

        const request = http.get(api, { timeout: 3000 }, (response) => {
            if (response.statusCode !== 200) {
                response.resume();

                return resolve([]);
            }

            let body = '';
            response.setEncoding('utf8');
            response.on('data', (chunk) => (body += chunk));
            response.on('end', () => {
                try {
                    const payload = JSON.parse(body);

                    resolve(Array.isArray(payload.tunnels) ? payload.tunnels : []);
                } catch {
                    resolve([]);
                }
            });
        });

        request.on('timeout', () => request.destroy());
        request.on('error', () => resolve([]));
    });
}

// Prova de que o app atrás de `baseUrl` é o DESTA máquina: localhost, ou o host do túnel que o ngrok local reporta
// apontando para a porta do serve do script (o `artisan` local só comprova a configuração do app local).
async function baseUrlIsLocalApp(baseUrl) {
    let url;

    try {
        url = new URL(baseUrl);
    } catch {
        return false;
    }

    if (isLoopback(url.hostname)) {
        return true;
    }

    if (url.protocol !== 'https:' || tunnelHost === '' || url.hostname !== tunnelHost) {
        return false;
    }

    return (await ngrokTunnels()).some((tunnel) => {
        try {
            return (
                new URL(tunnel.public_url).protocol === 'https:' &&
                new URL(tunnel.public_url).origin === url.origin &&
                isLoopbackUpstream(tunnel.config?.addr)
            );
        } catch {
            return false;
        }
    });
}

// Estado da rodada do spec de sandbox: cada e-mail que o spec gera é gravado ANTES do cadastro em
// cypress/.sandbox-run/<nonce>.json, e a limpeza (e2e/scripts/clean-sandbox-signups.php) apaga SÓ esses — nunca por
// padrão de e-mail. O nonce e o e-mail são validados aqui (o arquivo fica sempre dentro da pasta).
const SANDBOX_RUN_DIR = path.resolve(__dirname, 'cypress/.sandbox-run');

function sandboxRunFile(nonce) {
    if (!/^[A-Za-z0-9_-]{1,64}$/.test(String(nonce))) {
        throw new Error('sandboxRun: nonce inválido');
    }

    return path.join(SANDBOX_RUN_DIR, `${nonce}.json`);
}

function recordSandboxEmail({ nonce, email }) {
    if (!/^cy-sandbox-[a-z0-9._-]+@example\.test$/i.test(String(email))) {
        throw new Error('sandboxRun: e-mail fora do padrão cy-sandbox-*@example.test');
    }

    const file = sandboxRunFile(nonce);

    fs.mkdirSync(SANDBOX_RUN_DIR, { recursive: true });

    const state = fs.existsSync(file)
        ? JSON.parse(fs.readFileSync(file, 'utf8'))
        : { nonce, startedAt: new Date().toISOString(), emails: [] };

    if (!state.emails.includes(email)) {
        state.emails.push(email);
    }

    fs.writeFileSync(file, `${JSON.stringify(state, null, 2)}\n`);

    return file;
}

module.exports = defineConfig({
    e2e: {
        baseUrl: process.env.CYPRESS_BASE_URL || 'http://localhost:8085',
        specPattern: includeSandbox ? 'cypress/e2e/sandbox/**/*.cy.js' : 'cypress/e2e/**/*.cy.js',
        // Geradores de manuais e a homologação real do Asaas rodam SOB DEMANDA.
        // CYPRESS_SANDBOX=1 libera apenas a pasta sandbox; docs continua explícito.
        excludeSpecPattern: ['cypress/e2e/docs/**', ...(includeSandbox ? [] : ['cypress/e2e/sandbox/**'])],
        // O plano free do ngrok serve uma página de aviso a quem chega com User-Agent de navegador (quebraria o
        // fluxo); um User-Agent próprio passa direto. Só no modo túnel.
        ...(tunnelHost ? { userAgent: 'EasyEye-E2E-Sandbox' } : {}),
        setupNodeEvents(on, config) {
            on('task', {
                // Não exponha URL/chave no browser nem nos logs do Cypress. O baseUrl é o do próprio Cypress (node),
                // nunca um argumento vindo da página.
                asaasSandboxConfigured: async () =>
                    (await baseUrlIsLocalApp(config.baseUrl)) && hasAsaasSandboxConfig(),
                // Registra o e-mail da conta que a rodada vai criar (devolve o caminho do estado) e informa o estado
                // para o after() da limpeza (null = a rodada não criou conta nenhuma).
                sandboxRunRecord: (args) => recordSandboxEmail(args),
                sandboxRunState: ({ nonce }) => {
                    const file = sandboxRunFile(nonce);

                    return fs.existsSync(file) ? file : null;
                },
                // Aviso no TERMINAL do `cypress run` (console.log do navegador não chega lá): é como o caso de cartão
                // entrega ao humano a URL do checkout. Aceita várias linhas (`\n`); `banner` destaca o bloco.
                sandboxNotice: ({ message, banner = false }) => {
                    // \p{Cc}: caracteres de controle por linha (a mensagem vem da página de teste, nunca vai crua ao terminal).
                    const lines = String(message)
                        .slice(0, 2000)
                        .split('\n')
                        .map((line) => line.replace(/\p{Cc}/gu, ' '));
                    const body = lines.map((line) => `[sandbox] ${line}`).join('\n');

                    console.log(banner ? `\n${'='.repeat(100)}\n${body}\n${'='.repeat(100)}\n` : `\n${body}\n`);

                    return null;
                },
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
