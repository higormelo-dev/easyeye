<?php

use App\Enums\FeatureKey;
use App\Models\{ExamType, Patient, PatientExam};
use App\Services\FeatureGateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('POST/PATCH patient exams — feature gating ApiMonthlyExamSends', function () {
    beforeEach(function () {
        Storage::fake('s3');

        $this->ctx = setupIntegrator([
            FeatureKey::HasApiIntegrator->value    => '1',
            FeatureKey::ApiMonthlyExamSends->value => '3',
        ]);
        $this->patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $this->examType = ExamType::factory()->create(['entity_id' => null]);

        $schedCtx       = createScheduleForEntity($this->ctx['entity']);
        $this->schedule = $schedCtx['schedule'];
    });

    it('store bloqueia com 403 ao atingir o limite', function () {
        $gate = app(FeatureGateService::class);

        // Consome o limite diretamente (3 de 3)
        $gate->increment($this->ctx['entity']->id, FeatureKey::ApiMonthlyExamSends, 3);

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Bloqueado',
            ],
            $this->ctx['headers']
        )->assertForbidden()->assertJsonStructure(['message', 'feature']);
    });

    it('store incrementa o contador a cada criação bem-sucedida', function () {
        foreach (range(1, 2) as $i) {
            $this->postJson(
                "/api/integrators/v1/patients/{$this->patient->id}/exams",
                [
                    'exam_identifier'     => $this->examType->code,
                    'schedule_identifier' => $this->schedule->code,
                    'archive'             => UploadedFile::fake()->image("e{$i}.jpg"),
                    'name'                => "Exame Store {$i}",
                ],
                $this->ctx['headers']
            )->assertCreated();
        }

        $status = app(FeatureGateService::class)
            ->status($this->ctx['entity']->id, FeatureKey::ApiMonthlyExamSends);

        expect($status->used)->toBe(2);
    });

    it('update bloqueia com 403 ao atingir o limite', function () {
        $exam = PatientExam::factory()->create([
            'patient_id' => $this->patient->id,
            'name'       => 'Exame Para Atualizar',
            'archive'    => 'path/old.jpg',
        ]);

        // Esgota o limite
        app(FeatureGateService::class)
            ->increment($this->ctx['entity']->id, FeatureKey::ApiMonthlyExamSends, 3);

        $this->patchJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('new.jpg'),
                'name'                => 'Exame Para Atualizar',
            ],
            $this->ctx['headers']
        )->assertForbidden();
    });

    it('update incrementa o contador após atualização bem-sucedida', function () {
        $exam = PatientExam::factory()->create([
            'patient_id' => $this->patient->id,
            'name'       => 'Exame Original',
            'archive'    => 'path/old.jpg',
        ]);

        $this->patchJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('updated.jpg'),
                'name'                => 'Exame Atualizado',
            ],
            $this->ctx['headers']
        )->assertOk();

        $status = app(FeatureGateService::class)
            ->status($this->ctx['entity']->id, FeatureKey::ApiMonthlyExamSends);

        expect($status->used)->toBe(1);
    });

    it('store e update compartilham o mesmo contador mensal', function () {
        // Cria 1 via store (usa 1)
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('e1.jpg'),
                'name'                => 'Exame Compartilhado',
            ],
            $this->ctx['headers']
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Compartilhado')->first();

        // Atualiza 1 via update (usa 2)
        $this->patchJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('e1_v2.jpg'),
                'name'                => 'Exame Compartilhado',
            ],
            $this->ctx['headers']
        )->assertOk();

        // 3ª operação usa o último crédito
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('e2.jpg'),
                'name'                => 'Exame 3',
            ],
            $this->ctx['headers']
        )->assertCreated();

        // 4ª operação → limite esgotado
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('e3.jpg'),
                'name'                => 'Exame 4',
            ],
            $this->ctx['headers']
        )->assertForbidden();
    });
});
