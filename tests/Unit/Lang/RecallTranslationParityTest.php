<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Fase 6 (recall e validação clínica): pt_BR e en nunca divergem de chave nos
 * arquivos de idioma novos. Cada etapa acrescenta o seu arquivo aqui.
 */
dataset('recall_lang_files', [
    'recall_protocol', 'recall_rule', 'manager_recall', 'clinical_review', 'manager_reviews',
    'recall', 'recall_list', 'recall_settings', 'recall_conditions', 'recall_followup', 'recall_invite',
    'consent',
]);

it('lang/{pt_BR,en}/:file têm as mesmas chaves e nenhum valor vazio', function (string $file) {
    $pt = Arr::dot(require lang_path("pt_BR/{$file}.php"));
    $en = Arr::dot(require lang_path("en/{$file}.php"));

    expect(array_values(array_diff(array_keys($pt), array_keys($en))))->toBe([], "faltando em en/{$file}.php")
        ->and(array_values(array_diff(array_keys($en), array_keys($pt))))->toBe([], "sobrando em en/{$file}.php")
        ->and(array_keys(array_filter($pt, fn ($value) => trim((string) $value) === '')))->toBe([], "vazio em pt_BR/{$file}.php")
        ->and(array_keys(array_filter($en, fn ($value) => trim((string) $value) === '')))->toBe([], "vazio em en/{$file}.php");
})->with('recall_lang_files');

it('lang/{pt_BR,en}/whatsapp.php: os textos do convite de retorno existem nos dois idiomas, sem valor vazio', function () {
    $keys = [
        'templates.care_recall',
        'patient.recall_book', 'patient.recall_done', 'patient.recall_use_buttons',
        'recall.toggle', 'recall.toggle_hint', 'recall.enabled', 'recall.badge_paused', 'recall.badge_active', 'recall.pause_title',
        'recall.pause_text', 'recall.paused_since', 'recall.paused_reason', 'recall.impact', 'recall.pause', 'recall.resume',
        'recall.reason', 'recall.reason_hint', 'recall.confirm_pause', 'recall.confirm_resume', 'recall.paused', 'recall.resumed',
        'recall.resume_reason',
    ];

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = Arr::dot(require lang_path("{$locale}/whatsapp.php"));

        foreach ($keys as $key) {
            expect(trim((string) ($texts[$key] ?? '')))->not->toBe('', "{$locale}/whatsapp.php sem '{$key}'");
        }
    }
});
