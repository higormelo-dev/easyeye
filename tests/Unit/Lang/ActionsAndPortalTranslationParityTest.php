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
