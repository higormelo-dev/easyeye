<?php

declare(strict_types=1);

/**
 * Contrato dos textos das telas do Gerenciador de exames (laudo, rascunhos,
 * impressão, importação, ditado): toda chave que um componente Vue pede com
 * `tt('chave', 'texto padrão')` existe nos dois idiomas de lang/eye_images.php.
 * O `tt()` cai no texto padrão (em português) quando a chave falta — então a falta
 * nunca aparece como erro: em inglês, a tela mostra português sem ninguém perceber.
 * Este teste pega isso antes do navegador. Mesmo padrão de RecallViewTextsTest.
 */

use App\Enums\DraftStatus;
use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

/** @return list<string> chaves literais de tt('…') / tt("…") (a chamada pode quebrar linha) */
function eivStaticKeys(string $file): array
{
    $source = (string) file_get_contents(base_path($file));

    preg_match_all('/\btt\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', $source, $matches);

    return array_values(array_unique($matches[1]));
}

dataset('eiv_files', [
    'Index'                        => 'resources/js/Pages/Panel/ExamManager/Index.vue',
    'ExamManagerReportModal'       => 'resources/js/Pages/Panel/ExamManager/ExamManagerReportModal.vue',
    'ExamManagerReportDraftsModal' => 'resources/js/Pages/Panel/ExamManager/ExamManagerReportDraftsModal.vue',
    'ExamManagerCompareModal'      => 'resources/js/Pages/Panel/ExamManager/ExamManagerCompareModal.vue',
    'ExamManagerContextMenu'       => 'resources/js/Pages/Panel/ExamManager/ExamManagerContextMenu.vue',
    'ImportExternalExamModal'      => 'resources/js/Components/Panel/ImportExternalExamModal.vue',
    'DictationButton'              => 'resources/js/Components/Panel/DictationButton.vue',
]);

it('toda chave tt() literal dos componentes existe em lang/eye_images.php, em pt_BR e en', function (string $file) {
    $keys = eivStaticKeys($file);

    expect($keys)->not->toBeEmpty();

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = require lang_path("{$locale}/eye_images.php");

        foreach ($keys as $key) {
            expect($texts[$key] ?? null)->toBeString("{$file}: {$locale}/eye_images.php não tem '{$key}'");
            expect(trim((string) $texts[$key]))->not->toBeEmpty("{$file}: {$locale}/eye_images.php tem '{$key}' vazio");
        }
    }
})->with('eiv_files');

it('pt_BR e en têm exatamente as mesmas chaves em eye_images.php', function () {
    $pt = array_keys(require lang_path('pt_BR/eye_images.php'));
    $en = array_keys(require lang_path('en/eye_images.php'));

    expect(array_values(array_diff($pt, $en)))->toBe([])
        ->and(array_values(array_diff($en, $pt)))->toBe([]);
});

it('os marcadores :nome do texto são os mesmos nos dois idiomas (fill() troca por nome)', function () {
    $pt = require lang_path('pt_BR/eye_images.php');
    $en = require lang_path('en/eye_images.php');

    foreach ($pt as $key => $text) {
        if (! is_string($text) || ! is_string($en[$key] ?? null)) {
            continue;
        }

        preg_match_all('/(?<![A-Za-z0-9]):([a-z_]+)\b/', $text, $ptMarkers);
        preg_match_all('/(?<![A-Za-z0-9]):([a-z_]+)\b/', $en[$key], $enMarkers);

        // Só compara os marcadores que o front/servidor realmente preenche: o mesmo conjunto nos dois idiomas.
        expect(array_unique($enMarkers[1]))->toEqualCanonicalizing(array_unique($ptMarkers[1]));
    }
});

it('os rótulos das teclas do pedal cobrem todas as teclas permitidas, nos dois idiomas', function () {
    $source = (string) file_get_contents(base_path('resources/js/Components/Panel/ImportExternalExamModal.vue'));
    preg_match('/const PEDAL_KEY_LABEL_KEYS = \{(.*?)\};/s', $source, $block);
    $body = $block[1] ?? '';

    // Chaves como `Enter:` (sem aspas) ou `' ':` (com aspas) → 'pedal_key_*'.
    preg_match_all('/^\s*(?:([A-Za-z]+)|\'(.)\'):\s*\'([a-z_]+)\'/m', $body, $pairs, PREG_SET_ORDER);

    $map = [];

    foreach ($pairs as $pair) {
        $map[$pair[1] !== '' ? $pair[1] : $pair[2]] = $pair[3];
    }

    expect($map)->not->toBeEmpty('PEDAL_KEY_LABEL_KEYS não encontrado em ImportExternalExamModal.vue');

    foreach (config('eye_image_capture.pedal.allowed_keys') as $key) {
        expect(isset($map[$key]))->toBeTrue("tecla '{$key}' do pedal sem rótulo em PEDAL_KEY_LABEL_KEYS");
    }

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = require lang_path("{$locale}/eye_images.php");

        foreach ($map as $labelKey) {
            expect($texts[$labelKey] ?? null)->toBeString("{$locale}/eye_images.php não tem '{$labelKey}'");
        }
    }
});

it('os rótulos de status do rascunho cobrem todos os valores de DraftStatus, nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach (DraftStatus::cases() as $status) {
            $label = $status->label();

            expect($label)->toBeString()->not->toBeEmpty();
            $this->assertStringNotContainsString('eye_images.', $label, "{$locale} DraftStatus::{$status->name} vazou a chave de tradução");
        }
    }
});

it('os rótulos do painel de IA (aiLabel) existem no que o controller entrega e vêm de chaves reais nos dois idiomas', function () {
    $index      = (string) file_get_contents(base_path('resources/js/Pages/Panel/ExamManager/Index.vue'));
    $controller = (string) file_get_contents(base_path('app/Http/Controllers/ExamManagerController.php'));

    preg_match_all('/\baiLabel\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $index, $used);
    preg_match("/'labels'\s*=>\s*\[(.*?)\n\s{16}\],/s", $controller, $block);
    expect($block)->not->toBeEmpty('bloco ai.labels não encontrado em ExamManagerController');

    preg_match_all('/[\'"]([A-Za-z0-9_]+)[\'"]\s*=>\s*__\([\'"]([A-Za-z0-9_.]+)[\'"]/', $block[1], $entries, PREG_SET_ORDER);
    $labels = [];

    foreach ($entries as $entry) {
        $labels[$entry[1]] = $entry[2];
    }

    foreach (array_unique($used[1]) as $key) {
        expect(isset($labels[$key]))->toBeTrue("Index.vue pede aiLabel('{$key}') mas o controller não entrega");
    }

    foreach (['pt_BR', 'en'] as $locale) {
        foreach ($labels as $key => $langKey) {
            [$group, $path] = explode('.', $langKey, 2);
            $texts          = require lang_path("{$locale}/{$group}.php");

            expect(Arr::get($texts, $path))->toBeString("{$locale}/{$group}.php não tem '{$path}' (ai.labels.{$key})");
        }
    }
});
