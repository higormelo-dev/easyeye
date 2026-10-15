<?php

declare(strict_types=1);

/**
 * Contrato dos textos do curso de injeções intravítreas (Fase 6): pt_BR e en têm as mesmas chaves, todo código de
 * recusa que o servidor pode devolver, todo valor de enum e toda droga da configuração têm texto, e toda chave que os
 * componentes Vue pedem existe nos dois idiomas. Chave que falta apareceria na tela como o próprio caminho.
 */

use App\Domains\Recall\Intravitreal\Enums\{ApplicationStatus, AuthorizationStatus, CourseRegimen, CourseStatus};
use App\Domains\Recall\Intravitreal\Exceptions\IntravitrealException;
use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

function rctTexts(string $locale): array
{
    return require lang_path("{$locale}/recall_course.php");
}

it('pt_BR e en têm exatamente as mesmas chaves', function () {
    $pt = array_keys(Arr::dot(rctTexts('pt_BR')));
    $en = array_keys(Arr::dot(rctTexts('en')));

    expect(array_diff($pt, $en))->toBe([], 'faltam em en/recall_course.php')
        ->and(array_diff($en, $pt))->toBe([], 'faltam em pt_BR/recall_course.php');
});

it('todo código de recusa do curso tem texto nos dois idiomas', function () {
    $codes = array_values((new ReflectionClass(IntravitrealException::class))->getConstants());

    expect($codes)->not->toBeEmpty();

    foreach (['pt_BR', 'en'] as $locale) {
        foreach ($codes as $code) {
            expect(rctTexts($locale)['errors'][$code] ?? null)->toBeString("{$locale} errors.{$code}");
        }
    }
});

it('todo valor de enum e toda droga da configuração têm rótulo nos dois idiomas', function () {
    $groups = [
        'status'             => array_map(static fn ($case) => $case->value, CourseStatus::cases()),
        'regimen'            => array_map(static fn ($case) => $case->value, CourseRegimen::cases()),
        'application_status' => array_map(static fn ($case) => $case->value, ApplicationStatus::cases()),
        'authorization'      => array_map(static fn ($case) => $case->value, AuthorizationStatus::cases()),
        'drugs'              => array_keys((array) config('recall.intravitreal.drugs')),
    ];

    foreach (['pt_BR', 'en'] as $locale) {
        foreach ($groups as $group => $values) {
            expect($values)->not->toBeEmpty();

            foreach ($values as $value) {
                expect(rctTexts($locale)[$group][$value] ?? null)->toBeString("{$locale} {$group}.{$value}");
            }
        }
    }
});

it('toda chave tx() e t.* dos componentes existe em pt_BR e en', function (string $component) {
    $source = (string) file_get_contents(base_path($component));

    preg_match_all('/\btx\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', $source, $static);
    preg_match_all('/(?<![\w.])t\.([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*)/', $source, $direct);

    $paths = array_values(array_unique([...$static[1], ...$direct[1]]));

    expect($paths)->not->toBeEmpty();

    foreach (['pt_BR', 'en'] as $locale) {
        foreach ($paths as $path) {
            // `t.exec[`end_${option}`]` e `t.panel.eye[course.eye]` são montadas em tempo de execução: conferidas à parte.
            expect(Arr::has(rctTexts($locale), $path))->toBeTrue("{$locale}/recall_course.php não tem '{$path}'");
        }
    }
})->with([
    'painel'   => ['resources/js/Components/Panel/Intravitreal/IntravitrealCoursesPanel.vue'],
    'pedido'   => ['resources/js/Components/Panel/Intravitreal/IntravitrealRequestFields.vue'],
    'execução' => ['resources/js/Components/Panel/Intravitreal/IntravitrealExecutionFields.vue'],
]);

it('as chaves montadas em tempo de execução (olho, fim do curso) existem', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        $texts = rctTexts($locale);

        foreach (['right', 'left'] as $eye) {
            expect($texts['panel']['eye'][$eye] ?? null)->toBeString("{$locale} panel.eye.{$eye}");
        }

        foreach (['none', 'completed', 'discontinued'] as $end) {
            expect($texts['exec']["end_{$end}"] ?? null)->toBeString("{$locale} exec.end_{$end}");
        }
    }
});

it('o espelho em JSON dos textos (usado pelo Vitest) é idêntico ao pt_BR/recall_course.php', function () {
    $mirror = json_decode((string) file_get_contents(base_path('tests/JavaScript/Panel/Intravitreal/support/recall_course.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($mirror)->toBe(rctTexts('pt_BR'), 'regere com: php scripts/dump-lang-json.php recall_course tests/JavaScript/Panel/Intravitreal/support/recall_course.json');
});
