<?php

declare(strict_types=1);

use App\Domains\AI\Support\AiDispatchAudit;
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\{AiRiskLevel, AiRunMode};
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * Auditoria do envio à IA (LGPD): o que saiu, sem o conteúdo.
 */
it('resume o envio: impressão digital das instruções, categorias, chaves e imagens — sem conteúdo', function () {
    $request = new AiRequestData(
        workflow: 'eye_image_analysis',
        mode: AiRunMode::Economy,
        userPrompt: 'Analise <PATIENT_NAME_REDACTED>.',
        systemPrompt: 'INSTRUÇÕES DO SERVIDOR',
        riskLevel: AiRiskLevel::Medium,
        context: [
            'main_complaint' => 'dor ocular', 'age_years' => 58, 'gender' => 1,
            'selected_exams' => [['image' => 1]], 'conversation_history' => [], 'extra' => 'x', '_built_by' => 'builder',
        ],
        attachments: [['mime_type' => 'image/jpeg', 'data' => 'QUJD']],
    );

    $audit = AiDispatchAudit::summarize($request);

    expect($audit['system_prompt_sha256'])->toBe(hash('sha256', 'INSTRUÇÕES DO SERVIDOR'))
        ->and($audit['data_categories'])->toBe(['clinical_record', 'conversation_history', 'demographics', 'exam_metadata', 'images', 'other_context', 'request_text'])
        ->and($audit['context_keys'])->toBe(['age_years', 'conversation_history', 'extra', 'gender', 'main_complaint', 'selected_exams'])
        ->and($audit['images'])->toBe(1)
        ->and(json_encode($audit))->not->toContain('dor ocular')->not->toContain('QUJD')->not->toContain('INSTRUÇÕES');
});

it('posologia do catálogo: só dado do medicamento, sem prompt de sistema vazio virar hash', function () {
    $audit = AiDispatchAudit::summarize(new AiRequestData(
        workflow: 'medicine_posology',
        mode: AiRunMode::Economy,
        userPrompt: 'Sugira a posologia.',
        riskLevel: AiRiskLevel::Medium,
        context: ['medicamento' => ['name' => 'Colírio']],
    ));

    expect($audit['data_categories'])->toBe(['medicine_catalog', 'request_text'])
        ->and($audit['system_prompt_sha256'])->toBeNull()
        ->and($audit['images'])->toBe(0);
});
