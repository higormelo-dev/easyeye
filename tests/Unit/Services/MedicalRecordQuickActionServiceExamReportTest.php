<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ExamReportRegistry;
use App\Services\{DayExtensionService, SurgerySchedulingDocService};
use App\Services\{MedicalRecordDocumentationService, MedicalRecordQuickActionService};
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

uses(TestCase::class);

/**
 * F4a foundation — contrato do branch 'exam-report' no MedicalRecordQuickActionService.
 *
 * Cobre apenas a lógica pura de resolução (sem DB). Testes de integração com
 * ReportSettingContent + emissão de PDF entram em F4c, quando seeds existirem.
 */
function callPrivate(MedicalRecordQuickActionService $service, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod($service, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($service, $args);
}

function makeService(): MedicalRecordQuickActionService
{
    /** @var MedicalRecordDocumentationService $doc */
    $doc = app(MedicalRecordDocumentationService::class);

    return new MedicalRecordQuickActionService(
        $doc,
        new SurgerySchedulingDocService(),
        new DayExtensionService(),
    );
}

it('resolves valid exam_type to its registry definition', function (string $examType, string $expectedSetting, string $expectedSlug) {
    $service = makeService();

    [$setting, $slug, $title] = callPrivate($service, 'resolveExamReportDefinition', [['exam_type' => $examType]]);

    expect($setting)->toBe($expectedSetting)
        ->and($slug)->toBe($expectedSlug)
        ->and($title)->toBe(ExamReportRegistry::from($examType)->label());
})->with([
    ['ecografia', 'LAUDO DE ECOGRAFIA', 'padrao'],
    ['gonioscopia', 'LAUDO DE GONIOSCOPIA', 'padrao'],
    ['ophthalmological_report', 'LAUDO OFTALMOLÓGICO', 'completo'],
    ['retinal_mapping', 'MAPEAMENTO DE RETINA', 'padrao'],
    ['oct', 'OCT (TOMOGRAFIA DE COERÊNCIA ÓTICA)', 'padrao'],
]);

it('honors custom title in payload over registry default', function () {
    $service = makeService();

    [, , $title] = callPrivate($service, 'resolveExamReportDefinition', [[
        'exam_type' => 'gonioscopia',
        'title'     => 'Laudo de Gonioscopia — Revisado',
    ]]);

    expect($title)->toBe('Laudo de Gonioscopia — Revisado');
});

it('throws on unknown exam_type', function () {
    $service = makeService();

    expect(fn () => callPrivate($service, 'resolveExamReportDefinition', [['exam_type' => 'foo_bar']]))
        ->toThrow(InvalidArgumentException::class, 'Tipo de exame inválido: foo_bar');
});

it('throws on missing exam_type', function () {
    $service = makeService();

    expect(fn () => callPrivate($service, 'resolveExamReportDefinition', [[]]))
        ->toThrow(InvalidArgumentException::class);
});

it('builds CONTEUDO_LIVRE replacement for exam-report action', function () {
    $service = makeService();

    $replacements = callPrivate($service, 'buildCustomReplacements', [
        'exam-report',
        ['content' => "Linha 1\nLinha 2"],
    ]);

    expect($replacements)->toHaveKey('{{CONTEUDO_LIVRE}}')
        ->and($replacements['{{CONTEUDO_LIVRE}}'])->toContain('Linha 1')
        ->and($replacements['{{CONTEUDO_LIVRE}}'])->toContain('Linha 2');
});

it('sanitizes HTML/script in CONTEUDO_LIVRE to prevent XSS', function () {
    $service = makeService();

    $replacements = callPrivate($service, 'buildCustomReplacements', [
        'exam-report',
        ['content' => '<p>Texto válido</p><script>alert(1)</script><img src=x onerror=alert(1)>'],
    ]);

    // exam-report agora usa HTMLPurifier (profile `medical`) — preserva
    // formatação clínica e remove tags/atributos perigosos por completo
    // (em vez de apenas escapar como o sanitizeMultiline antigo).
    expect($replacements['{{CONTEUDO_LIVRE}}'])->not->toContain('<script>')
        ->and($replacements['{{CONTEUDO_LIVRE}}'])->not->toContain('alert(1)')
        ->and($replacements['{{CONTEUDO_LIVRE}}'])->not->toContain('onerror')
        ->and($replacements['{{CONTEUDO_LIVRE}}'])->toContain('Texto válido');
});

it('exposes findActiveTemplateForExam wrapper signature', function () {
    $service = makeService();

    expect(method_exists($service, 'findActiveTemplateForExam'))->toBeTrue();
    $reflection = new ReflectionMethod($service, 'findActiveTemplateForExam');
    expect($reflection->isPublic())->toBeTrue();
    expect($reflection->getNumberOfParameters())->toBe(2);
});

// ── F4e — subtypes ─────────────────────────────────────────────────────────

it('resolveExamReportDefinition usa Pentacam slug correspondente ao subtype', function () {
    $service = makeService();

    [, $slug, $title] = callPrivate($service, 'resolveExamReportDefinition', [[
        'exam_type' => 'pentacam',
        'subtype'   => 'ceratocone_avancado',
    ]]);

    expect($slug)->toBe('ceratocone_avancado');
    expect($title)->toBe('Pentacam — Ceratocone Avançado');
});

it('resolveExamReportDefinition Pentacam sem subtype usa padrao', function () {
    $service = makeService();

    [, $slug, $title] = callPrivate($service, 'resolveExamReportDefinition', [[
        'exam_type' => 'pentacam',
    ]]);

    expect($slug)->toBe('padrao');
    expect($title)->toBe('Pentacam');
});

it('resolveExamReportDefinition Pentacam subtype inválido cai em padrao', function () {
    $service = makeService();

    [, $slug] = callPrivate($service, 'resolveExamReportDefinition', [[
        'exam_type' => 'pentacam',
        'subtype'   => 'inexistente',
    ]]);

    expect($slug)->toBe('padrao');
});

it('resolveExamReportDefinition gonioscopia ignora subtype', function () {
    $service = makeService();

    [, $slug] = callPrivate($service, 'resolveExamReportDefinition', [[
        'exam_type' => 'gonioscopia',
        'subtype'   => 'qualquer_coisa',
    ]]);

    expect($slug)->toBe('padrao');
});

it('título customizado prevalece sobre fallback de subtype', function () {
    $service = makeService();

    [, , $title] = callPrivate($service, 'resolveExamReportDefinition', [[
        'exam_type' => 'pentacam',
        'subtype'   => 'laudo_refrativo',
        'title'     => 'Custom — Cliente XYZ',
    ]]);

    expect($title)->toBe('Custom — Cliente XYZ');
});

it('registry covers all 17 expected exam types', function () {
    $values = array_map(fn (ExamReportRegistry $e) => $e->value, ExamReportRegistry::cases());

    $expected = [
        'tonometry', 'ecografia', 'microscopia_especular', 'paquimetria',
        'retinografia', 'angiofluoresceinografia', 'oct', 'schirmer', 'pentacam',
        'stress_curve', 'corneal_topography', 'computer_campimetry', 'gonioscopia',
        'retinal_mapping', 'ophthalmological_report', 'declaracao_branco',
        'lens_prescription',
    ];

    foreach ($expected as $value) {
        expect($values)->toContain($value);
    }
});
