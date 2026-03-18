<?php

use App\Enums\FeatureKey;
use App\Models\{ExamType, Patient, PlanFeature};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('POST /api/integrators/v1/exams — feature gating ApiMonthlyExamSends', function () {
    beforeEach(function () {
        Storage::fake('s3');
    });

    it('permite envio quando limite não está configurado no plano (ilimitado)', function () {
        // HasApiIntegrator ativo, sem ApiMonthlyExamSends definido → ilimitado
        $ctx      = setupIntegrator([FeatureKey::HasApiIntegrator->value => '1']);
        $patient  = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
        $examType = ExamType::factory()->create(['entity_id' => null]);

        $this->postJson('/api/integrators/v1/exams', [
            'patient_identifier' => $patient->id,
            'exam_identifier'    => $examType->code,
            'archive'            => UploadedFile::fake()->image('exam.jpg'),
        ], $ctx['headers'])->assertOk();
    });

    it('permite envio quando limite está em 0 (ilimitado)', function () {
        $ctx = setupIntegrator([
            FeatureKey::HasApiIntegrator->value    => '1',
            FeatureKey::ApiMonthlyExamSends->value => '0',
        ]);
        $patient  = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
        $examType = ExamType::factory()->create(['entity_id' => null]);

        $this->postJson('/api/integrators/v1/exams', [
            'patient_identifier' => $patient->id,
            'exam_identifier'    => $examType->code,
            'archive'            => UploadedFile::fake()->image('exam.jpg'),
        ], $ctx['headers'])->assertOk();
    });

    it('bloqueia com 403 ao atingir o limite mensal de envios', function () {
        $ctx = setupIntegrator([
            FeatureKey::HasApiIntegrator->value    => '1',
            FeatureKey::ApiMonthlyExamSends->value => '2',
        ]);
        $patient  = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
        $examType = ExamType::factory()->create(['entity_id' => null]);

        $payload = fn (string $name) => [
            'patient_identifier' => $patient->id,
            'exam_identifier'    => $examType->code,
            'archive'            => UploadedFile::fake()->image("{$name}.jpg"),
            'name'               => $name,
        ];

        // 1º envio → OK
        $this->postJson('/api/integrators/v1/exams', $payload('Exame 1'), $ctx['headers'])->assertOk();

        // 2º envio → OK (dentro do limite)
        $this->postJson('/api/integrators/v1/exams', $payload('Exame 2'), $ctx['headers'])->assertOk();

        // 3º envio → 403 (limite esgotado)
        $this->postJson('/api/integrators/v1/exams', $payload('Exame 3'), $ctx['headers'])
            ->assertForbidden()
            ->assertJsonStructure(['message', 'feature']);
    });

    it('incrementa o contador a cada envio bem-sucedido', function () {
        $ctx = setupIntegrator([
            FeatureKey::HasApiIntegrator->value    => '1',
            FeatureKey::ApiMonthlyExamSends->value => '5',
        ]);
        $patient  = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
        $examType = ExamType::factory()->create(['entity_id' => null]);

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/integrators/v1/exams', [
                'patient_identifier' => $patient->id,
                'exam_identifier'    => $examType->code,
                'archive'            => UploadedFile::fake()->image("exam{$i}.jpg"),
                'name'               => "Exame {$i}",
            ], $ctx['headers'])->assertOk();
        }

        $gate   = app(\App\Services\FeatureGateService::class);
        $status = $gate->status($ctx['entity']->id, FeatureKey::ApiMonthlyExamSends);

        expect($status->used)->toBe(3);
        expect($status->remaining)->toBe(2);
    });

    it('retorna 403 quando HasApiIntegrator está desativado no plano', function () {
        $ctx = setupIntegrator([FeatureKey::HasApiIntegrator->value => '0']);

        $this->postJson('/api/integrators/v1/exams', [], $ctx['headers'])
            ->assertForbidden();
    });
});
