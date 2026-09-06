<?php

declare(strict_types=1);

/**
 * BUGFIX (revisão de segurança, achado de auditoria da área session/cookie):
 * config/session.php 'secure' não tinha fallback (env('SESSION_SECURE_COOKIE')
 * sozinho), diferente de todo o resto do arquivo. Quando a env var não está
 * definida (nem .env.example nem .env a declaram), Symfony\Cookie assume
 * secure=false — o cookie de sessão (que carrega two_factor_verified_at,
 * auth.password_confirmed_at, selected_entity_id e impersonating.*) seria
 * enviado mesmo em HTTP puro. Fix: default agora segue a mesma regra que
 * AppServiceProvider::boot() já usa pra forçar HTTPS (production+testing) —
 * mas lido via env('APP_ENV') puro, NUNCA app()->environment(): arquivos de
 * config são require'd por LoadConfiguration ANTES de detectEnvironment()
 * rodar, então app()->environment() derruba o boot inteiro nesse ponto
 * (achado durante a implementação deste próprio fix — 500 em toda a app).
 *
 * Os testes de fallback por ambiente rodam em SUBPROCESSO com boot completo
 * da aplicação: 'files' => storage_path(...) e outras chaves do arquivo
 * dependem do container já resolvido, então um require isolado do arquivo
 * de config quebra antes de chegar em 'secure'. APP_ENV é passado como
 * variável de ambiente REAL do processo (não putenv() pós-boot) — o
 * Dotenv::createImmutable() do Laravel só respeita um valor já setado no
 * ambiente do processo antes do boot, nunca um putenv() feito depois.
 */
function resolveSessionSecureInFreshProcess(string $appEnv, ?string $sessionSecureCookie = null): bool
{
    $basePath = base_path();

    $script = <<<'PHP'
        require '%s/vendor/autoload.php';
        $app = require '%s/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo $app->make('config')->get('session.secure') ? '1' : '0';
        PHP;
    $script = sprintf($script, $basePath, $basePath);

    $env = ['APP_ENV' => $appEnv];

    if ($sessionSecureCookie !== null) {
        $env['SESSION_SECURE_COOKIE'] = $sessionSecureCookie;
    }

    $envPrefix = collect($env)
        ->map(fn ($value, $key) => escapeshellarg("{$key}={$value}"))
        ->implode(' ');

    $output = shell_exec("env {$envPrefix} php -r " . escapeshellarg($script));

    return trim((string) $output) === '1';
}

test('cookie de sessao e secure por padrao em production, sem env var definida', function () {
    expect(resolveSessionSecureInFreshProcess('production'))->toBeTrue();
});

test('cookie de sessao e secure por padrao em testing, sem env var definida', function () {
    expect(resolveSessionSecureInFreshProcess('testing'))->toBeTrue();
});

test('cookie de sessao NAO e forcado secure em local, preservando dev sem https', function () {
    expect(resolveSessionSecureInFreshProcess('local'))->toBeFalse();
});

test('SESSION_SECURE_COOKIE explicito no env sempre tem prioridade sobre o ambiente', function () {
    expect(resolveSessionSecureInFreshProcess('production', 'false'))->toBeFalse();
});

// Regressao do bug pego durante a implementacao: app()->environment() dentro
// de um arquivo de config derruba o boot inteiro (500 em toda a aplicacao),
// porque LoadConfiguration require's os arquivos de config ANTES de chamar
// detectEnvironment(). Garante que a aplicacao real (nao um require isolado)
// sobe normalmente e resolve o valor.
test('aplicacao inteira sobe normalmente e resolve session.secure sem quebrar o boot', function () {
    expect(config('session.secure'))->toBeBool();
});
