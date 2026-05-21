<?php

declare(strict_types=1);

use App\Domains\AI\Services\AiSafetyService;
use App\Enums\AI\AiRiskLevel;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

test('inspect retorna vazio para conteúdo neutro e risco baixo', function () {
    $service = new AiSafetyService();

    $notes = $service->inspect(
        'Achados oculares sugestivos de catarata cortical inicial. Recomenda-se reavaliação pelo médico responsável.',
        AiRiskLevel::Low,
    );

    expect($notes)->toBe([]);
});

test('inspect detecta afirmação diagnóstica taxativa', function () {
    $service = new AiSafetyService();

    $notes = $service->inspect(
        'O paciente apresenta glaucoma de ângulo aberto e precisa iniciar tratamento.',
        AiRiskLevel::Medium,
    );

    expect(count($notes))->toBeGreaterThan(0);
    $joined = implode(' ', $notes);
    expect($joined)->toContain('afirmações diagnósticas taxativas');
});

test('inspect detecta prescrição direta com dose', function () {
    $service = new AiSafetyService();

    $notes = $service->inspect(
        'Inicie timolol 0,5% 1 gota de 12 em 12 horas no olho direito.',
        AiRiskLevel::Medium,
    );

    $joined = implode(' ', $notes);
    expect($joined)->toContain('prescrição');
});

test('inspect detecta linguagem dirigida ao paciente', function () {
    $service = new AiSafetyService();

    $notes = $service->inspect(
        'Você deve realizar o exame de campo visual. No seu caso, é o procedimento indicado.',
        AiRiskLevel::Low,
    );

    $joined = implode(' ', $notes);
    expect($joined)->toContain('paciente');
});

test('inspect adiciona aviso de alto risco quando AiRiskLevel::High mesmo sem padrão detectado', function () {
    $service = new AiSafetyService();

    $notes = $service->inspect(
        'Achados sugestivos de retinopatia diabética leve. Recomenda-se reavaliação periódica.',
        AiRiskLevel::High,
    );

    expect(count($notes))->toBeGreaterThan(0);
    $joined = implode(' ', $notes);
    expect($joined)->toContain('alto risco');
});

test('inspect detecta múltiplos padrões e retorna lista cumulativa', function () {
    $service = new AiSafetyService();

    $notes = $service->inspect(
        'O paciente tem glaucoma. Tome dorzolamida 2% 1 gota 8 em 8 horas. Você deve fazer o exame imediatamente.',
        AiRiskLevel::Medium,
    );

    // 3 padrões diferentes → ao menos 2 notas distintas (alguns podem se sobrepor).
    expect(count($notes))->toBeGreaterThanOrEqual(2);
});

test('inspect retorna vazio para conteúdo vazio', function () {
    $service = new AiSafetyService();

    expect($service->inspect('', AiRiskLevel::Low))->toBe([]);
    expect($service->inspect('', AiRiskLevel::High))->toBe([]);
});
