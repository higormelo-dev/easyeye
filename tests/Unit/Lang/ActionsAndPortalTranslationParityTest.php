<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

/*
 * EE-14: pdf.php e patient_portal.php ganharam chaves novas (paginação,
 * rodapé, auth/senha do Portal do Paciente); actions.php tinha 36 chaves
 * de report_settings/medication_presets/documentation.types.tonometry/
 * global faltando em en (achado durante o EE-06, só corrigido agora).
 * Mesma varredura de tests/Feature/ClinicalSafety/ClinicalSafetyTranslationsTest.php
 * — pt_BR e en nunca podem divergir de chave, nem agora nem numa adição futura.
 */
function assertSameLangKeys(string $file): void
{
    $pt = array_keys(Arr::dot(require lang_path("pt_BR/{$file}.php")));
    $en = array_keys(Arr::dot(require lang_path("en/{$file}.php")));

    expect(array_values(array_diff($pt, $en)))->toBe([], "faltando em en/{$file}.php")
        ->and(array_values(array_diff($en, $pt)))->toBe([], "sobrando em en/{$file}.php (sem par em pt_BR)");
}

it('pdf.php: pt_BR e en têm as mesmas chaves', fn () => assertSameLangKeys('pdf'));

it('patient_portal.php: pt_BR e en têm as mesmas chaves', fn () => assertSameLangKeys('patient_portal'));

it('actions.php: pt_BR e en têm as mesmas chaves', fn () => assertSameLangKeys('actions'));

it('tiss_send.php: pt_BR e en têm as mesmas chaves', fn () => assertSameLangKeys('tiss_send'));

it('ui.php: pt_BR e en têm as mesmas chaves (t_ui vai em toda página; chave só num idioma devolveria o texto cru)', fn () => assertSameLangKeys('ui'));

/*
 * Lote 1 de UI/UX do painel (docs/internal/planos/frontend-uiux-lote1-plano.md §3.6): 21 chaves de `ui.php` (enviadas
 * como `t_ui`) e 3 de `actions.medical_records` (erros da linha do tempo do prontuário). Os componentes leem
 * `ui.chave || 'fallback pt'`, então chave faltando em teste/SSR vira texto em português, nunca aria-label vazio — mas em
 * produção o idioma en precisa ter todas, com os MESMOS placeholders (:minutes).
 */
const UI_LOTE1_KEYS = [
    'retry', 'cep_lookup', 'password_show', 'password_hide', 'discard_changes', 'form_errors_summary', 'load_error', 'open_menu',
    'toggle_sidebar', 'close_menu', 'language', 'theme_toggle', 'theme_dark', 'theme_light', 'search_loading',
    'search_error', 'search_retry', 'calendar_duration_estimated', 'calendar_duration_estimated_short',
    'calendar_duration_estimated_aria', 'calendar_duration_minutes',
];

const ACTIONS_TIMELINE_KEYS = ['timeline_load_error', 'timeline_load_more_error', 'timeline_retry'];

it('as chaves do Lote 1 de UI/UX existem em pt_BR e en, não vazias, com os mesmos placeholders', function (string $file, array $keys, ?string $prefix) {
    $texts = fn (string $locale): array => Arr::dot(require lang_path("{$locale}/{$file}.php"));
    $pt    = $texts('pt_BR');
    $en    = $texts('en');

    foreach ($keys as $key) {
        $dotted = ($prefix !== null ? "{$prefix}." : '') . $key;

        expect($pt[$dotted] ?? '')->not->toBe('', "pt_BR/{$file}.php sem {$dotted}")
            ->and($en[$dotted] ?? '')->not->toBe('', "en/{$file}.php sem {$dotted}");

        preg_match_all('/:[a-z_]+/', $pt[$dotted], $ptPlaceholders);
        preg_match_all('/:[a-z_]+/', $en[$dotted], $enPlaceholders);

        expect($enPlaceholders[0])->toBe($ptPlaceholders[0], "placeholders de {$dotted} divergem entre pt_BR e en");
    }
})->with([
    'ui.php (t_ui)'               => ['ui', UI_LOTE1_KEYS, null],
    'actions.php medical_records' => ['actions', ACTIONS_TIMELINE_KEYS, 'medical_records'],
]);

it('as durações estimadas do calendário trazem o placeholder :minutes', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        $ui = require lang_path("{$locale}/ui.php");

        expect($ui['calendar_duration_estimated_short'])->toContain(':minutes')
            ->and($ui['calendar_duration_estimated_aria'])->toContain(':minutes')
            ->and($ui['calendar_duration_minutes'])->toContain(':minutes')
            ->and($ui['calendar_duration_estimated'])->not->toContain(':minutes');
    }
});
