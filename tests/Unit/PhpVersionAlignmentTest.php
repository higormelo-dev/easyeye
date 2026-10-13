<?php

declare(strict_types=1);

/**
 * composer.json dizia PHP ^8.2, mas Docker e CI já usavam 8.4 e o lock
 * (laravel/framework 12.69 + pacotes Symfony de execução) exige >=8.4 de
 * verdade — achado via auditoria de encerramento, EE-12.
 * `composer check-platform-reqs` só valida a versão que RODOU o comando,
 * nunca a declarada — nada detectava esse descompasso sozinho. Os testes
 * abaixo são a checagem contínua: README/Docker/CI/Composer concordando
 * deixa de ser "verificado uma vez" e passa a ser "quebra se divergir".
 */

use Tests\TestCase;

uses(TestCase::class);

function phpVersionFromComposerJson(): string
{
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    preg_match('/\^(\d+\.\d+)/', (string) ($composer['require']['php'] ?? ''), $matches);

    return $matches[1] ?? '';
}

it('[GAP] composer.json declara PHP 8.4 como mínimo — bate com o que o projeto realmente exige (EE-12)', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require']['php'] ?? null)->toBe('^8.4');
});

it('Dockerfile usa a MESMA versão major.minor de PHP que composer.json declara (EE-12)', function () {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
    preg_match('/FROM php:(\d+\.\d+)-/', $dockerfile, $dockerMatch);

    expect($dockerMatch[1] ?? null)
        ->not->toBeNull('Dockerfile não declara uma versão de PHP reconhecível (FROM php:X.Y-...)')
        ->toBe(phpVersionFromComposerJson());
});

it('workflows de CI que rodam PHP usam a MESMA versão major.minor que composer.json declara (EE-12)', function () {
    $expected  = phpVersionFromComposerJson();
    $workflows = glob(base_path('.github/workflows/*.yml')) ?: [];
    $checked   = 0;

    foreach ($workflows as $workflow) {
        $content = (string) file_get_contents($workflow);

        if (preg_match_all("/php-version:\s*'?(\d+\.\d+)'?/", $content, $matches) === false || $matches[1] === []) {
            continue;
        }

        foreach ($matches[1] as $version) {
            $checked++;
            expect($version)->toBe($expected, "{$workflow} declara php-version {$version}, diferente do composer.json ({$expected})");
        }
    }

    expect($checked)->toBeGreaterThan(0, 'Nenhum workflow de CI com php-version encontrado pra comparar.');
});

it('composer check-platform-reqs confirma sucesso para PHP na faixa declarada', function () {
    exec('composer check-platform-reqs --no-dev 2>&1', $output, $exitCode);

    $phpLine = implode("\n", array_filter($output, fn ($line) => str_starts_with(trim($line), 'php ')));

    expect($exitCode)->toBe(0)
        ->and($phpLine)->toContain('success');
});
