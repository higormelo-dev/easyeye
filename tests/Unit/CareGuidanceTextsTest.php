<?php

declare(strict_types=1);

/**
 * Contrato dos textos das orientações pós-procedimento (Fase 6 / S14): pt_BR e en têm as mesmas chaves, todo código de
 * recusa do domínio tem texto e toda chave que as telas pedem existe nos dois idiomas. Chave que falta apareceria na
 * tela como o próprio caminho.
 */

use App\Domains\CareGuidance\Exceptions\CareGuidanceException;
use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

function cgtTexts(string $locale): array
{
    return require lang_path("{$locale}/care_guidance.php");
}

it('pt_BR e en têm exatamente as mesmas chaves', function () {
    $pt = array_keys(Arr::dot(cgtTexts('pt_BR')));
    $en = array_keys(Arr::dot(cgtTexts('en')));

    expect(array_diff($pt, $en))->toBe([], 'faltam em en/care_guidance.php')
        ->and(array_diff($en, $pt))->toBe([], 'faltam em pt_BR/care_guidance.php');
});

it('todo código de recusa do domínio tem texto nos dois idiomas', function () {
    $codes = array_values((new ReflectionClass(CareGuidanceException::class))->getConstants());

    expect($codes)->not->toBeEmpty();

    foreach (['pt_BR', 'en'] as $locale) {
        foreach ($codes as $code) {
            expect(cgtTexts($locale)['errors'][$code] ?? null)->toBeString("{$locale} errors.{$code}");
        }
    }
});

it('toda chave tx() e t.* das telas existe em pt_BR e en', function (string $component) {
    $source = (string) file_get_contents(base_path($component));

    preg_match_all('/\btx\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', $source, $static);
    // `t.settings...` (editor/página) e `state.t.issue...` (painel do prontuário).
    preg_match_all('/(?<![\w])(?:state\.)?t\.((?:settings|issue|pdf|errors|menu)(?:\.[A-Za-z0-9_]+)*)/', $source, $direct);

    $paths = array_values(array_unique([...$static[1], ...$direct[1]]));

    expect($paths)->not->toBeEmpty();

    foreach (['pt_BR', 'en'] as $locale) {
        foreach ($paths as $path) {
            expect(Arr::has(cgtTexts($locale), $path))->toBeTrue("{$locale}/care_guidance.php não tem '{$path}'");
        }
    }
})->with([
    'editor' => ['resources/js/Components/Panel/CareGuidance/CareGuidanceEditor.vue'],
    'página' => ['resources/js/Pages/Panel/Settings/CareGuidance/Index.vue'],
    'painel' => ['resources/js/Components/Panel/CareGuidance/CareGuidancePanel.vue'],
]);

it('o PDF só usa chaves que existem', function () {
    $source = (string) file_get_contents(resource_path('views/pdf/care_guidance.blade.php'));

    preg_match_all('/__\(\'(care_guidance\.[A-Za-z0-9_.]+)\'/', $source, $matches);

    foreach (['pt_BR', 'en'] as $locale) {
        foreach (array_unique($matches[1]) as $key) {
            // `care_guidance.pdf.eyes.` + olho: chave montada em tempo de execução (conferida abaixo).
            if (str_ends_with($key, '.')) {
                continue;
            }

            expect(Arr::has(cgtTexts($locale), substr($key, strlen('care_guidance.'))))->toBeTrue("{$locale} {$key}");
        }

        foreach (['right', 'left', 'both'] as $eye) {
            expect(cgtTexts($locale)['pdf']['eyes'][$eye] ?? null)->toBeString("{$locale} pdf.eyes.{$eye}");
        }
    }
});

it('o espelho em JSON dos textos (usado pelo Vitest) é idêntico ao pt_BR/care_guidance.php', function () {
    $mirror = json_decode((string) file_get_contents(base_path('tests/JavaScript/Panel/CareGuidance/support/care_guidance.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($mirror)->toBe(cgtTexts('pt_BR'), 'regere com: php scripts/dump-lang-json.php care_guidance tests/JavaScript/Panel/CareGuidance/support/care_guidance.json');
});
