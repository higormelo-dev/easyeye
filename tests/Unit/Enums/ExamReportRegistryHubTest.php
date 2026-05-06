<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ExamReportRegistry;
use Tests\TestCase;

uses(TestCase::class);

/**
 * F4b — contrato do hub visual de Laudos de Exame.
 */
it('hub exclui Tonometry e LensPrescription (têm fluxos próprios)', function () {
    $hub = ExamReportRegistry::examsForHub();

    expect($hub)->not->toContain(ExamReportRegistry::Tonometry)
        ->and($hub)->not->toContain(ExamReportRegistry::LensPrescription);
});

it('hub retorna 15 exames clínicos', function () {
    $hub = ExamReportRegistry::examsForHub();

    expect(count($hub))->toBe(15);
});

it('hub inclui exames críticos do fluxo oftalmológico', function () {
    $hubValues = array_map(fn (ExamReportRegistry $e) => $e->value, ExamReportRegistry::examsForHub());

    $expected = [
        'gonioscopia', 'retinal_mapping', 'ophthalmological_report',
        'ecografia', 'paquimetria', 'oct', 'pentacam',
    ];

    foreach ($expected as $value) {
        expect($hubValues)->toContain($value);
    }
});

it('cada exame tem ícone Font Awesome definido', function () {
    foreach (ExamReportRegistry::examsForHub() as $exam) {
        $icon = $exam->icon();
        expect($icon)->toStartWith('fa-')
            ->and(strlen($icon))->toBeGreaterThan(3);
    }
});

it('hub preserva ordem de declaração do enum', function () {
    $hub = ExamReportRegistry::examsForHub();

    // Ecografia é o primeiro caso clínico após Tonometry, deve abrir o hub
    expect($hub[0])->toBe(ExamReportRegistry::Ecografia);

    // BrancoDeclaration é o último caso clínico antes de LensPrescription
    expect(end($hub))->toBe(ExamReportRegistry::BrancoDeclaration);
});

// ── F4e — subtipos clínicos ─────────────────────────────────────────────────

it('Pentacam tem 5 subtipos clínicos', function () {
    $subs = ExamReportRegistry::Pentacam->subtypes();

    expect($subs)->toHaveCount(5);
    $slugs = array_column($subs, 'slug');
    expect($slugs)->toBe([
        'padrao',
        'ceratocone_oval',
        'ceratocone_bow_tie',
        'ceratocone_avancado',
        'laudo_refrativo',
    ]);
});

it('Pentacam hasSubtypes retorna true', function () {
    expect(ExamReportRegistry::Pentacam->hasSubtypes())->toBeTrue();
});

it('demais exames do hub não têm subtipos', function () {
    foreach (ExamReportRegistry::examsForHub() as $exam) {
        if ($exam === ExamReportRegistry::Pentacam) {
            continue;
        }
        expect($exam->subtypes())->toBe([], "Exame {$exam->value} não deveria ter subtipos");
        expect($exam->hasSubtypes())->toBeFalse();
    }
});

it('resolveSubtypeSlug retorna slug correto para Pentacam variantes válidas', function (string $subtype) {
    expect(ExamReportRegistry::Pentacam->resolveSubtypeSlug($subtype))->toBe($subtype);
})->with(['padrao', 'ceratocone_oval', 'ceratocone_bow_tie', 'ceratocone_avancado', 'laudo_refrativo']);

it('resolveSubtypeSlug fallback para padrao com subtype inválido em Pentacam', function () {
    expect(ExamReportRegistry::Pentacam->resolveSubtypeSlug('invalido_xyz'))->toBe('padrao');
    expect(ExamReportRegistry::Pentacam->resolveSubtypeSlug(null))->toBe('padrao');
    expect(ExamReportRegistry::Pentacam->resolveSubtypeSlug(''))->toBe('padrao');
});

it('resolveSubtypeSlug ignora subtype em exame sem variantes', function () {
    // Gonioscopia não tem subtypes, qualquer subtype deve retornar slug default
    expect(ExamReportRegistry::Gonioscopia->resolveSubtypeSlug('qualquer_coisa'))->toBe('padrao');
    expect(ExamReportRegistry::Gonioscopia->resolveSubtypeSlug(null))->toBe('padrao');
});

it('subtypeLabel retorna label do Pentacam para slug válido', function () {
    expect(ExamReportRegistry::Pentacam->subtypeLabel('ceratocone_avancado'))->toBe('Ceratocone Avançado');
    expect(ExamReportRegistry::Pentacam->subtypeLabel('laudo_refrativo'))->toBe('Laudo Refrativo');
});

it('subtypeLabel retorna null para slug inválido ou exame sem subtipos', function () {
    expect(ExamReportRegistry::Pentacam->subtypeLabel('xyz'))->toBeNull();
    expect(ExamReportRegistry::Pentacam->subtypeLabel(null))->toBeNull();
    expect(ExamReportRegistry::Gonioscopia->subtypeLabel('padrao'))->toBeNull();
});
