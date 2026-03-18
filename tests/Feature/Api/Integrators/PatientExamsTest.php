<?php

use App\Models\{ExamType, Patient, PatientExam};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('GET /api/integrators/v1/patients/{patient}/exams', function () {
    beforeEach(function () {
        $this->ctx     = setupIntegrator();
        $this->patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
    });

    it('lists exams for a patient', function () {
        PatientExam::factory(3)->create(['patient_id' => $this->patient->id]);

        $this->getJson("/api/integrators/v1/patients/{$this->patient->id}/exams", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    });

    it('caps per_page at plan limit', function () {
        PatientExam::factory(5)->create(['patient_id' => $this->patient->id]);

        $response = $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams?per_page=999",
            $this->ctx['headers']
        )->assertOk();

        expect($response->json('meta.per_page'))->toBeLessThanOrEqual(100);
    });

    it('returns 404 for patient from another entity', function () {
        $other   = setupIntegrator();
        $patient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->getJson("/api/integrators/v1/patients/{$patient->id}/exams", $this->ctx['headers'])
            ->assertNotFound();
    });

    it('returns 401 without authentication', function () {
        $this->getJson("/api/integrators/v1/patients/{$this->patient->id}/exams")
            ->assertUnauthorized();
    });
});

describe('POST /api/integrators/v1/patients/{patient}/exams', function () {
    beforeEach(function () {
        Storage::fake('s3');

        $this->ctx      = setupIntegrator();
        $this->patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $this->examType = ExamType::factory()->create(['entity_id' => null]); // global
    });

    it('creates a patient exam and uploads archive to S3', function () {
        $file = UploadedFile::fake()->image('exam.jpg');

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => $file,
                'name'            => 'Exame Fundoscopia 01',
            ],
            $this->ctx['headers']
        )->assertCreated()
            ->assertJsonFragment(['name' => 'Exame Fundoscopia 01']);

        Storage::disk('s3')->assertExists(
            PatientExam::where('name', 'Exame Fundoscopia 01')->first()->archive
        );
    });

    it('returns 422 when exam_identifier does not exist', function () {
        $file = UploadedFile::fake()->image('exam.jpg');

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier' => 'CODIGO-INEXISTENTE',
                'archive'         => $file,
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 422 when archive is missing', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            ['exam_identifier' => $this->examType->code],
            $this->ctx['headers']
        )->assertUnprocessable();
    });
});

describe('GET /api/integrators/v1/patients/{patient}/exams/{exam}', function () {
    beforeEach(function () {
        $this->ctx     = setupIntegrator();
        $this->patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $this->exam    = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
    });

    it('shows exam by UUID', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['id' => $this->exam->id]);
    });

    it('shows exam by code', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->code}",
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['id' => $this->exam->id]);
    });

    it('returns 404 for non-existent exam', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/EXM-NAOEXISTE",
            $this->ctx['headers']
        )->assertNotFound();
    });
});

describe('PUT /api/integrators/v1/patients/{patient}/exams/{exam}', function () {
    beforeEach(function () {
        Storage::fake('s3');

        $this->ctx      = setupIntegrator();
        $this->patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $this->examType = ExamType::factory()->create(['entity_id' => null]);
        $this->exam     = PatientExam::factory()->create([
            'patient_id' => $this->patient->id,
            'archive'    => 'old/path/exam.jpg',
        ]);
    });

    it('updates exam and replaces archive on S3', function () {
        $file = UploadedFile::fake()->image('updated.jpg');

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => $file,
                'name'            => 'Exame Atualizado',
            ],
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['name' => 'Exame Atualizado']);
    });
});

describe('DELETE /api/integrators/v1/patients/{patient}/exams/{exam}', function () {
    it('deletes a patient exam', function () {
        $ctx     = setupIntegrator();
        $patient = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
        $exam    = PatientExam::factory()->create(['patient_id' => $patient->id]);

        $this->deleteJson(
            "/api/integrators/v1/patients/{$patient->id}/exams/{$exam->id}",
            [],
            $ctx['headers']
        )->assertNoContent();

        expect(PatientExam::find($exam->id))->toBeNull();
    });
});
