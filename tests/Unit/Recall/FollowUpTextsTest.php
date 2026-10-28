<?php

declare(strict_types=1);

/**
 * Contrato dos textos da sugestão do campo "Retorno" (Fase 6): pt_BR e en têm as mesmas chaves, todo texto que o
 * componente Vue pede existe nos dois idiomas, o rótulo de cada unidade de intervalo que o servidor escreve na
 * frase existe (singular e plural) e o espelho em JSON do Vitest é idêntico ao arquivo de idioma.
 */

use App\Domains\Recall\Enums\IntervalUnit;
use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

const FUT_COMPONENT = 'resources/js/Components/Panel/Recall/FollowUpSuggestion.vue';

function futTexts(string $locale, string $group = 'recall_followup'): array
{
    return require lang_path("{$locale}/{$group}.php");
}

it('pt_BR e en têm exatamente as mesmas chaves', function () {
    $pt = array_keys(Arr::dot(futTexts('pt_BR')));
    $en = array_keys(Arr::dot(futTexts('en')));

    expect(array_diff($pt, $en))->toBe([], 'faltam em en')
        ->and(array_diff($en, $pt))->toBe([], 'faltam em pt_BR');
});

it('toda chave tx() do componente existe nos dois idiomas, inclusive as duas do aviso de teto (escolha por condição)', function () {
    $source = (string) file_get_contents(base_path(FUT_COMPONENT));

    preg_match_all('/\btx\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', $source, $static);
    preg_match_all('/\?\s*[\'"]([A-Za-z0-9_.]+)[\'"]\s*:\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', $source, $ternary);

    $paths = array_values(array_unique([...$static[1], ...$ternary[1], ...$ternary[2]]));

    expect($paths)->toContain('title', 'line', 'days', 'use', 'in_use', 'cap', 'cap_hit');

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = futTexts($locale);

        foreach ($paths as $path) {
            expect(Arr::has($texts, $path))->toBeTrue("{$locale}/recall_followup.php não tem '{$path}'");
        }
    }
});

it('os placeholders de cada frase são os que o componente e o servidor mandam (:title :eye :interval :unit :days)', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        $texts = futTexts($locale);

        foreach ([':title', ':eye', ':interval', ':unit'] as $placeholder) {
            expect($texts['line'])->toContain($placeholder);
        }

        foreach (['days', 'use', 'cap', 'cap_hit'] as $key) {
            expect($texts[$key])->toContain(':days');
        }
    }
});

it('o servidor escreve a unidade no singular e no plural: toda unidade de intervalo tem os dois rótulos nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach (IntervalUnit::cases() as $unit) {
            expect($unit->label())->toBeString()->and($unit->labelOne())->toBeString();
            $this->assertStringNotContainsString('recall_protocol.', $unit->label(), "{$locale} {$unit->value} (plural) vazou a chave");
            $this->assertStringNotContainsString('recall_protocol.', $unit->labelOne(), "{$locale} {$unit->value} (singular) vazou a chave");
        }
    }
});

it('o espelho em JSON dos textos (usado pelo Vitest) é idêntico ao pt_BR/recall_followup.php', function () {
    $mirror = json_decode((string) file_get_contents(base_path('tests/JavaScript/Panel/Recall/support/recall_followup.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($mirror)->toBe(futTexts('pt_BR'), 'regere com: php scripts/dump-lang-json.php recall_followup tests/JavaScript/Panel/Recall/support/recall_followup.json');
});
