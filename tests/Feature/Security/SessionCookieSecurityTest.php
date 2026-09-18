<?php

declare(strict_types=1);

/**
 * BUGFIX (revisão de segurança, achado de auditoria da área session/cookie):
 * config/session.php 'secure' não tinha fallback (env('SESSION_SECURE_COOKIE')
 * sozinho), diferente de todo o resto do arquivo. Quando a env var não está
 * definida (nem .env.example nem .env a declaram), Symfony\Cookie assume
 * secure=false — o cookie de sessão (que carrega two_factor_verified_at,
 * auth.password_confirmed_at, selected_entity_id e impersonating.*) seria
 * enviado mesmo em HTTP puro.
 *
 * BUGFIX 2 (achado real: localhost pedindo SSL sem precisar): a v1 deste fix
 * usava nome de ambiente (production/testing) como proxy pra "é HTTPS?",
 * igual o AppServiceProvider::boot() já fazia — mas isso quebra qualquer
 * setup local com APP_ENV=testing sem TLS (docker nginx local só com
 * `listen 80`, sem certificado): toda URL absoluta gerada virava https:// e
 * o browser tentava handshake TLS num servidor que só fala HTTP puro
 * (ERR_CONNECTION_CLOSED). Fix: a fonte de verdade agora é o scheme que
 * APP_URL já declara, não o nome do ambiente — mesma regra usada em
 * AppServiceProvider::boot(). env() puro aqui, NUNCA app()->environment():
 * arquivos de config são require'd por LoadConfiguration ANTES de
 * detectEnvironment() rodar, então app()->environment() derruba o boot
 * inteiro nesse ponto (achado durante a implementação do fix anterior — 500
 * em toda a app).
 *
 * Os testes de fallback rodam em SUBPROCESSO com boot completo da aplicação:
 * 'files' => storage_path(...) e outras chaves do arquivo dependem do
 * container já resolvido, então um require isolado do arquivo de config
 * quebra antes de chegar em 'secure'. APP_URL é passado como variável de
 * ambiente REAL do processo (não putenv() pós-boot) — o
 * Dotenv::createImmutable() do Laravel só respeita um valor já setado no
 * ambiente do processo antes do boot, nunca um putenv() feito depois.
 */
function resolveSessionSecureInFreshProcess(string $appUrl, ?string $sessionSecureCookie = null): bool
{
    $basePath = base_path();

    $script = <<<'PHP'
        require '%s/vendor/autoload.php';
        $app = require '%s/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo $app->make('config')->get('session.secure') ? '1' : '0';
        PHP;
    $script = sprintf($script, $basePath, $basePath);

    $env = ['APP_URL' => $appUrl];

    if ($sessionSecureCookie !== null) {
        $env['SESSION_SECURE_COOKIE'] = $sessionSecureCookie;
    }

    $envPrefix = collect($env)
        ->map(fn ($value, $key) => escapeshellarg("{$key}={$value}"))
        ->implode(' ');

    $output = shell_exec("env {$envPrefix} php -r " . escapeshellarg($script));

    return trim((string) $output) === '1';
}

test('cookie de sessao e secure quando APP_URL e https, sem env var definida', function () {
    expect(resolveSessionSecureInFreshProcess('https://app.easyeye.com.br'))->toBeTrue();
});

test('cookie de sessao NAO e forcado secure quando APP_URL e http, mesmo com APP_ENV=testing', function () {
    // Caso real que motivou o fix: docker local roda APP_ENV=testing sobre
    // HTTP puro (sem TLS) — o nome do ambiente não pode mais decidir isso.
    expect(resolveSessionSecureInFreshProcess('http://localhost:8085'))->toBeFalse();
});

test('SESSION_SECURE_COOKIE explicito no env sempre tem prioridade sobre APP_URL', function () {
    expect(resolveSessionSecureInFreshProcess('https://app.easyeye.com.br', 'false'))->toBeFalse();
});

// Regressao do bug pego durante a implementacao: app()->environment() dentro
// de um arquivo de config derruba o boot inteiro (500 em toda a aplicacao),
// porque LoadConfiguration require's os arquivos de config ANTES de chamar
// detectEnvironment(). Garante que a aplicacao real (nao um require isolado)
// sobe normalmente e resolve o valor.
test('aplicacao inteira sobe normalmente e resolve session.secure sem quebrar o boot', function () {
    expect(config('session.secure'))->toBeBool();
});

/**
 * Roda o boot real da aplicacao (todos os service providers, incluindo
 * AppServiceProvider) em subprocesso, com APP_URL/APP_ENV controlados via env
 * real do processo, e devolve a URL absoluta gerada por route('login'). Nao
 * reusa o $app do processo de teste atual para nao registrar os listeners de
 * AppServiceProvider::boot() (observers, rate limiters) uma segunda vez nele.
 */
function resolveLoginUrlInFreshProcess(string $appUrl, string $appEnv): string
{
    $basePath = base_path();

    $script = <<<'PHP'
        require '%s/vendor/autoload.php';
        $app = require '%s/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo route('login');
        PHP;
    $script = sprintf($script, $basePath, $basePath);

    $env       = ['APP_URL' => $appUrl, 'APP_ENV' => $appEnv];
    $envPrefix = collect($env)
        ->map(fn ($value, $key) => escapeshellarg("{$key}={$value}"))
        ->implode(' ');

    return trim((string) shell_exec("env {$envPrefix} php -r " . escapeshellarg($script)));
}

// Regressao do bug real reportado pelo usuario: URL::forceScheme('https') por
// nome de ambiente fazia toda URL absoluta gerada (redirect()->route(), etc)
// virar https:// mesmo num servidor local sem TLS, quebrando qualquer
// redirect (ex.: rota /go) com ERR_CONNECTION_CLOSED no browser.
test('URLs absolutas respeitam o scheme de APP_URL mesmo com APP_ENV=testing (caso real do bug)', function () {
    expect(resolveLoginUrlInFreshProcess('http://localhost:8085', 'testing'))->toStartWith('http://');
});

test('URLs absolutas continuam https em producao de verdade, com APP_URL https', function () {
    expect(resolveLoginUrlInFreshProcess('https://app.easyeye.com.br', 'production'))->toStartWith('https://');
});
