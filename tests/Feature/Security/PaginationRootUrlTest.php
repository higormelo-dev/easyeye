<?php

/**
 * Regressão do bug no ambiente de teste: navegador em HTTP/3 e Nginx sem
 * repassar o Host ao PHP-FPM — o Laravel via o host "_" (server_name
 * catch-all) e os links de paginação saíam como https://_/panel/...?page=2.
 *
 * Roda o boot real da aplicação em subprocesso (mesmo motivo de
 * SessionCookieSecurityTest: APP_URL só é lido no boot do AppServiceProvider),
 * troca a requisição por uma com o host recebido no servidor e devolve o
 * caminho que o paginador usaria.
 */
function resolvePaginatorPathInFreshProcess(string $appUrl, string $requestUrl): string
{
    $basePath = base_path();

    $script = <<<'PHP'
        require '%s/vendor/autoload.php';
        $app = require '%s/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $app->instance('request', Illuminate\Http\Request::create('%s'));
        echo Illuminate\Pagination\Paginator::resolveCurrentPath();
        PHP;
    $script = sprintf($script, $basePath, $basePath, $requestUrl);

    $envPrefix = escapeshellarg("APP_URL={$appUrl}") . ' ' . escapeshellarg('APP_ENV=testing');

    return trim((string) shell_exec("env {$envPrefix} php -r " . escapeshellarg($script)));
}

test('links de paginacao seguem o APP_URL https mesmo quando o host recebido e "_" (HTTP/3)', function () {
    expect(resolvePaginatorPathInFreshProcess(
        'https://teste.easyeye.app',
        'https://_/panel/manager/medicines?page=1',
    ))->toBe('https://teste.easyeye.app/panel/manager/medicines');
});

test('links de paginacao nao usam host forjado da requisicao quando APP_URL e https', function () {
    expect(resolvePaginatorPathInFreshProcess(
        'https://easyeye.app',
        'https://evil.example/panel/patients?page=3',
    ))->toBe('https://easyeye.app/panel/patients');
});

test('com APP_URL http (dev local) o paginador continua usando o host da requisicao', function () {
    expect(resolvePaginatorPathInFreshProcess(
        'http://localhost:8085',
        'http://192.168.0.10:8085/panel/patients?page=2',
    ))->toBe('http://192.168.0.10:8085/panel/patients');
});
