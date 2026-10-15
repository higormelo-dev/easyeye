<?php

declare(strict_types=1);

use App\Enums\Surgery\{ImplantKind, ImplantStatus, ImplantStockSource, SurgeryDescriptionStatus};
use Illuminate\Support\Arr;

/*
 * Fase 4 / M4 — textos: pt_BR e en com as mesmas chaves, nenhuma chave usada no
 * PHP ou na tela sem tradução, e rótulos de enum nos dois idiomas.
 */
function sopLang(string $locale): array
{
    return require lang_path("{$locale}/surgery_operation.php");
}

/** @return list<string> */
function sopLeafKeys(array $tree): array
{
    return array_keys(Arr::dot($tree));
}

/** @return list<string> */
function sopFiles(string $dir, string $pattern): array
{
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $out = [];

    foreach ($it as $file) {
        if (preg_match($pattern, $file->getPathname()) === 1) {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

it('pt_BR e en têm exatamente as mesmas chaves', function () {
    $pt = sopLeafKeys(sopLang('pt_BR'));
    $en = sopLeafKeys(sopLang('en'));

    expect(array_values(array_diff($pt, $en)))->toBe([])->and(array_values(array_diff($en, $pt)))->toBe([]);
});

it('nenhum texto vazio, e os parâmetros (:nome) são os mesmos nos dois idiomas', function () {
    $pt = Arr::dot(sopLang('pt_BR'));
    $en = Arr::dot(sopLang('en'));

    foreach ($pt as $key => $text) {
        expect(trim((string) $text))->not->toBe('', "pt_BR vazio: {$key}");
        expect(trim((string) $en[$key]))->not->toBe('', "en vazio: {$key}");

        preg_match_all('/:[a-z_]+/', (string) $text, $a);
        preg_match_all('/:[a-z_]+/', (string) $en[$key], $b);
        sort($a[0]);
        sort($b[0]);

        expect($a[0])->toBe($b[0], "parâmetros diferentes em {$key}");
    }
});

it('toda chave literal usada no PHP do M4 existe', function () {
    $keys = Arr::dot(sopLang('pt_BR'));
    $used = [];

    foreach (sopFiles(app_path(), '/(Surgery|Operation).*\.php$/') as $file) {
        preg_match_all("/['\"]surgery_operation\.([A-Za-z0-9_.]+)['\"]/", (string) file_get_contents($file), $m);
        $used = [...$used, ...$m[1]];
    }

    foreach (sopFiles(resource_path('views/pdf'), '/surgery_(description|implant).*\.blade\.php$/') as $file) {
        preg_match_all("/['\"]surgery_operation\.([A-Za-z0-9_.]+)['\"]/", (string) file_get_contents($file), $m);
        $used = [...$used, ...$m[1]];
    }

    // Prefixos de chave montada em tempo de execução (terminam em "_" ou ".") são cobertos pelos testes de rótulos.
    $missing = collect($used)->unique()
        ->reject(fn ($k) => str_ends_with($k, '_') || str_ends_with($k, '.') || isset($keys[$k]))
        ->values()->all();

    expect($used)->not->toBeEmpty()->and($missing)->toBe([]);
});

it('toda chave tr() usada nos componentes da aba existe em t.operation.ui', function () {
    $ui = Arr::dot(sopLang('pt_BR')['ui']);

    $files = [
        ...sopFiles(resource_path('js/Components/Surgery/Operation'), '/\.vue$/'),
        resource_path('js/Components/Surgery/Tabs/OperationTab.vue'),
    ];

    $missing = [];

    foreach ($files as $file) {
        preg_match_all("/\btr\(\s*'([A-Za-z0-9_.]+)'/", (string) file_get_contents($file), $m);

        foreach ($m[1] as $key) {
            if (! isset($ui[$key])) {
                $missing[] = basename($file) . ': ' . $key;
            }
        }

        // Chaves dinâmicas (template literal) — conferidas pelos valores possíveis.
        preg_match_all('/\btr\(\s*`([A-Za-z0-9_.]+)\$\{/', (string) file_get_contents($file), $d);
        $dynamicPrefixes = $d[1] ?? [];
        expect(array_diff($dynamicPrefixes, ['perform.override.', 'implant.void_action.']))->toBe([], basename($file) . ': prefixo dinâmico novo sem cobertura');
    }

    expect($missing)->toBe([]);

    foreach (['authorization', 'implant', 'time_out'] as $key) {
        expect($ui)->toHaveKey("perform.override.{$key}");
    }

    foreach (['returned', 'lost', 'none'] as $key) {
        expect($ui)->toHaveKey("implant.void_action.{$key}");
    }
});

it('rótulos dos enums e dos requisitos existem nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ([...ImplantKind::cases(), ...ImplantStatus::cases(), ...ImplantStockSource::cases(), ...SurgeryDescriptionStatus::cases()] as $case) {
            expect($case->label())->not->toContain('surgery_operation'); // chave sem tradução devolve o próprio caminho
        }

        foreach (['valid', 'expired', 'invalid'] as $state) {
            expect(__('surgery_operation.requirements.authorization_' . $state))->not->toContain('surgery_operation');
        }

        // Todo estado do termo de consentimento tem texto nos dois idiomas (inclui "desatualizado").
        foreach (['missing', 'unsigned', 'outdated'] as $state) {
            expect(__('surgery_operation.requirements.consent_' . $state))->not->toContain('surgery_operation');
        }
    }

    app()->setLocale('pt_BR');
});

it('datas e números dos documentos seguem o idioma', function () {
    expect(sopLang('pt_BR')['date_format'])->toBe('d/m/Y')->and(sopLang('en')['date_format'])->toBe('m/d/Y')
        ->and(sopLang('pt_BR')['decimal_separator'])->toBe(',')->and(sopLang('en')['decimal_separator'])->toBe('.');
});
