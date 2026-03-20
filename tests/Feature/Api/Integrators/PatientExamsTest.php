<?php

use App\Models\{ExamType, Patient, PatientExam};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('GET /api/integrators/v1/patients/{patient}/exams', function () {
    beforeEach(function () {
        $this->ctx     = setupIntegrator();
        $this->patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
    });

    it('lists exams for a patient using UUID', function () {
        PatientExam::factory(3)->create(['patient_id' => $this->patient->id]);

        $this->getJson("/api/integrators/v1/patients/{$this->patient->id}/exams", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    });

    it('lists exams for a patient using code', function () {
        PatientExam::factory(2)->create(['patient_id' => $this->patient->id]);

        $this->getJson("/api/integrators/v1/patients/{$this->patient->code}/exams", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    });

    it('returns empty result when search has no matches', function () {
        PatientExam::factory(3)->create(['patient_id' => $this->patient->id]);

        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams?search=XXXXNAOEXISTEXXXX",
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonPath('meta.total', 0);
    });

    it('caps per_page at 10', function () {
        PatientExam::factory(5)->create(['patient_id' => $this->patient->id]);

        $response = $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams?per_page=999",
            $this->ctx['headers']
        )->assertOk();

        expect($response->json('meta.per_page'))->toBeLessThanOrEqual(10);
    });

    it('returns 404 for patient from another entity', function () {
        $other   = setupIntegrator();
        $patient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->getJson("/api/integrators/v1/patients/{$patient->id}/exams", $this->ctx['headers'])
            ->assertNotFound();
    });

    it('returns 404 when patient code belongs to another entity', function () {
        $other = setupIntegrator();
        // Cria 2 pacientes no outro contexto; o segundo terá PAC-0000000002,
        // código que não existe no contexto atual (que só tem PAC-0000000001).
        Patient::factory()->create(['entity_id' => $other['entity']->id]);
        $foreignPatient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->getJson("/api/integrators/v1/patients/{$foreignPatient->code}/exams", $this->ctx['headers'])
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

    it('creates a patient exam using patient UUID', function () {
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

    it('creates a patient exam using patient code', function () {
        $file = UploadedFile::fake()->image('exam.jpg');

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams",
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => $file,
                'name'            => 'Exame Via Código',
            ],
            $this->ctx['headers']
        )->assertCreated()
            ->assertJsonFragment(['name' => 'Exame Via Código']);

        $exam = PatientExam::where('name', 'Exame Via Código')->first();
        expect($exam->patient_id)->toBe($this->patient->id);
        Storage::disk('s3')->assertExists($exam->archive);
    });

    it('creates exam using exam_identifier as UUID', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier' => $this->examType->id,
                'archive'         => UploadedFile::fake()->image('exam.jpg'),
                'name'            => 'Exame Por UUID',
            ],
            $this->ctx['headers']
        )->assertCreated()
            ->assertJsonFragment(['name' => 'Exame Por UUID']);
    });

    it('returns 404 when patient code belongs to another entity', function () {
        $other = setupIntegrator();
        Patient::factory()->create(['entity_id' => $other['entity']->id]);
        $foreignPatient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->postJson(
            "/api/integrators/v1/patients/{$foreignPatient->code}/exams",
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => UploadedFile::fake()->image('exam.jpg'),
            ],
            $this->ctx['headers']
        )->assertNotFound();
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

    it('returns 422 when exam_identifier is missing', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            ['archive' => UploadedFile::fake()->image('exam.jpg')],
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

    it('shows exam by patient UUID and exam UUID', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['id' => $this->exam->id]);
    });

    it('shows exam by patient UUID and exam code', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->code}",
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['id' => $this->exam->id]);
    });

    it('shows exam by patient code and exam UUID', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams/{$this->exam->id}",
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['id' => $this->exam->id]);
    });

    it('shows exam by patient code and exam code', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams/{$this->exam->code}",
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

    it('returns 404 when patient code belongs to another entity', function () {
        $other = setupIntegrator();
        Patient::factory()->create(['entity_id' => $other['entity']->id]);
        $foreignPatient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->getJson(
            "/api/integrators/v1/patients/{$foreignPatient->code}/exams/{$this->exam->id}",
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

    it('updates exam using patient UUID and exam UUID', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => UploadedFile::fake()->image('updated.jpg'),
                'name'            => 'Exame Atualizado',
            ],
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['name' => 'Exame Atualizado']);
    });

    it('updates exam using patient code and exam UUID', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams/{$this->exam->id}",
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => UploadedFile::fake()->image('updated.jpg'),
                'name'            => 'Atualizado Via Código',
            ],
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['name' => 'Atualizado Via Código']);
    });

    it('updates exam using patient code and exam code', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams/{$this->exam->code}",
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => UploadedFile::fake()->image('updated.jpg'),
                'name'            => 'Atualizado Código+Código',
            ],
            $this->ctx['headers']
        )->assertOk()
            ->assertJsonFragment(['name' => 'Atualizado Código+Código']);
    });

    it('replaces old archive on S3 when updating', function () {
        Storage::disk('s3')->put('old/path/exam.jpg', 'conteudo antigo');

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => UploadedFile::fake()->image('new.jpg'),
            ],
            $this->ctx['headers']
        )->assertOk();

        Storage::disk('s3')->assertMissing('old/path/exam.jpg');
        Storage::disk('s3')->assertExists(
            PatientExam::find($this->exam->id)->archive
        );
    });

    it('returns 404 when patient code belongs to another entity', function () {
        $other = setupIntegrator();
        Patient::factory()->create(['entity_id' => $other['entity']->id]);
        $foreignPatient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->postJson(
            "/api/integrators/v1/patients/{$foreignPatient->code}/exams/{$this->exam->id}",
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => UploadedFile::fake()->image('exam.jpg'),
            ],
            $this->ctx['headers']
        )->assertNotFound();
    });
});

describe('DELETE /api/integrators/v1/patients/{patient}/exams/{exam}', function () {
    beforeEach(function () {
        $this->ctx     = setupIntegrator();
        $this->patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $this->exam    = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
    });

    it('deletes a patient exam using patient UUID and exam UUID', function () {
        $this->deleteJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [],
            $this->ctx['headers']
        )->assertNoContent();

        expect(PatientExam::find($this->exam->id))->toBeNull();
    });

    it('deletes a patient exam using patient code and exam UUID', function () {
        $this->deleteJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams/{$this->exam->id}",
            [],
            $this->ctx['headers']
        )->assertNoContent();

        expect(PatientExam::find($this->exam->id))->toBeNull();
    });

    it('deletes a patient exam using patient UUID and exam code', function () {
        $this->deleteJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->code}",
            [],
            $this->ctx['headers']
        )->assertNoContent();

        expect(PatientExam::find($this->exam->id))->toBeNull();
    });

    it('deletes a patient exam using patient code and exam code', function () {
        $this->deleteJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams/{$this->exam->code}",
            [],
            $this->ctx['headers']
        )->assertNoContent();

        expect(PatientExam::find($this->exam->id))->toBeNull();
    });

    it('returns 404 when patient code belongs to another entity', function () {
        $other = setupIntegrator();
        Patient::factory()->create(['entity_id' => $other['entity']->id]);
        $foreignPatient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->deleteJson(
            "/api/integrators/v1/patients/{$foreignPatient->code}/exams/{$this->exam->id}",
            [],
            $this->ctx['headers']
        )->assertNotFound();
    });

    it('returns 404 when exam does not exist', function () {
        $this->deleteJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/EXM-NAOEXISTE",
            [],
            $this->ctx['headers']
        )->assertNotFound();
    });
});
