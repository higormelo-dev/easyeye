<?php

use App\Models\{ExamType, Patient, PatientExam};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('POST /api/integrators/v1/exams', function () {
    beforeEach(function () {
        Storage::fake('s3');

        $this->ctx      = setupIntegrator();
        $this->patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $this->examType = ExamType::factory()->create(['entity_id' => null]);
    });

    it('creates a new exam using patient UUID and exam code', function () {
        $file = UploadedFile::fake()->image('exam.jpg');

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'patient_identifier' => $this->patient->id,
                'exam_identifier'    => $this->examType->code,
                'archive'            => $file,
            ],
            $this->ctx['headers']
        )->assertOk();

        expect(PatientExam::where('patient_id', $this->patient->id)->count())->toBe(1);
    });

    it('creates a new exam using patient code', function () {
        $file = UploadedFile::fake()->image('exam.jpg');

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'patient_identifier' => $this->patient->code,
                'exam_identifier'    => $this->examType->code,
                'archive'            => $file,
            ],
            $this->ctx['headers']
        )->assertOk();

        expect(PatientExam::where('patient_id', $this->patient->id)->count())->toBe(1);
    });

    it('creates a new exam using exam type UUID', function () {
        $file = UploadedFile::fake()->image('exam.jpg');

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'patient_identifier' => $this->patient->id,
                'exam_identifier'    => $this->examType->id,
                'archive'            => $file,
            ],
            $this->ctx['headers']
        )->assertOk();

        expect(PatientExam::where('patient_id', $this->patient->id)->count())->toBe(1);
    });

    it('uploads archive to S3', function () {
        $file = UploadedFile::fake()->image('exam.jpg');

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'patient_identifier' => $this->patient->id,
                'exam_identifier'    => $this->examType->code,
                'archive'            => $file,
                'name'               => 'Exame Fundoscopia 01',
            ],
            $this->ctx['headers']
        )->assertOk();

        $exam = PatientExam::where('name', 'Exame Fundoscopia 01')->first();
        Storage::disk('s3')->assertExists($exam->archive);
    });

    it('returns 422 when same name combination already exists', function () {
        // Create an existing exam with the exact same name+patient+exam combo
        PatientExam::factory()->create([
            'patient_id' => $this->patient->id,
            'exam_id'    => $this->examType->id,
            'name'       => 'Exame Repetido',
            'archive'    => 'old/path/exam.jpg',
        ]);

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'patient_identifier' => $this->patient->id,
                'exam_identifier'    => $this->examType->code,
                'archive'            => UploadedFile::fake()->image('new.jpg'),
                'name'               => 'Exame Repetido',
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('upserts exam when same name exists but with different exam type', function () {
        $otherExamType = ExamType::factory()->create(['entity_id' => null]);

        // Create an existing exam with a different exam type but same name
        PatientExam::factory()->create([
            'patient_id' => $this->patient->id,
            'exam_id'    => $otherExamType->id,
            'name'       => 'Exame Upsert',
            'archive'    => 'old/path/exam.jpg',
        ]);

        $file = UploadedFile::fake()->image('new.jpg');

        // POST with same name but different exam_identifier → upsert updates existing
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'patient_identifier' => $this->patient->id,
                'exam_identifier'    => $this->examType->code,
                'archive'            => $file,
                'name'               => 'Exame Upsert',
            ],
            $this->ctx['headers']
        )->assertOk();

        expect(PatientExam::where('name', 'Exame Upsert')->count())->toBe(1);
    });

    it('returns 422 when patient_identifier is missing', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => UploadedFile::fake()->image('exam.jpg'),
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 422 when patient_identifier does not exist', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'patient_identifier' => 'PAC-NAOEXISTE',
                'exam_identifier'    => $this->examType->code,
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 422 when exam_identifier does not exist', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'patient_identifier' => $this->patient->id,
                'exam_identifier'    => 'ETP-NAOEXISTE',
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 422 when archive is missing', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'patient_identifier' => $this->patient->id,
                'exam_identifier'    => $this->examType->code,
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 422 for patient from another entity', function () {
        $other   = setupIntegrator();
        $patient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'patient_identifier' => $patient->id,
                'exam_identifier'    => $this->examType->code,
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 401 without authentication', function () {
        $this->postJson('/api/integrators/v1/exams', [])
            ->assertUnauthorized();
    });
});
