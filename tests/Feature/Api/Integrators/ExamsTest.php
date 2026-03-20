<?php

use App\Models\{ExamType, Patient, PatientExam};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ---------------------------------------------------------------------------
// POST /api/integrators/v1/exams
// patient_id e doctor_id são derivados do schedule_identifier
// ---------------------------------------------------------------------------
describe('POST /api/integrators/v1/exams', function () {
    beforeEach(function () {
        Storage::fake('s3');

        $this->ctx      = setupIntegrator();
        $this->patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $this->examType = ExamType::factory()->create(['entity_id' => null]);

        // Schedule com patient_id: patient e doctor derivados aqui
        $schedCtx       = createScheduleForEntity($this->ctx['entity'], ['patient_id' => $this->patient->id]);
        $this->schedule = $schedCtx['schedule'];
        $this->doctor   = $schedCtx['doctor'];
    });

    it('creates a new exam and returns 201', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Fundoscopia 01',
            ],
            $this->ctx['headers']
        )->assertCreated()
            ->assertJsonFragment(['name' => 'Exame Fundoscopia 01']);
    });

    it('resolves patient_id from schedule', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Paciente Resolvido',
            ],
            $this->ctx['headers']
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Paciente Resolvido')->first();
        expect($exam->patient_id)->toBe($this->patient->id);
    });

    it('resolves doctor_id from schedule', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Médico Resolvido',
            ],
            $this->ctx['headers']
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Médico Resolvido')->first();
        expect($exam->doctor_id)->toBe($this->doctor->id);
        expect($exam->schedule_id)->toBe($this->schedule->id);
    });

    it('accepts exam_identifier as UUID', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->id,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame UUID Tipo',
            ],
            $this->ctx['headers']
        )->assertCreated()
            ->assertJsonFragment(['name' => 'Exame UUID Tipo']);
    });

    it('accepts schedule_identifier as UUID', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->id,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame UUID Schedule',
            ],
            $this->ctx['headers']
        )->assertCreated()
            ->assertJsonFragment(['name' => 'Exame UUID Schedule']);
    });

    it('uploads archive to S3', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Arquivo S3',
            ],
            $this->ctx['headers']
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Arquivo S3')->first();
        Storage::disk('s3')->assertExists($exam->archive);
    });

    it('returns 422 when name already exists in the entity', function () {
        PatientExam::factory()->create([
            'patient_id' => $this->patient->id,
            'name'       => 'Exame Repetido',
            'archive'    => 'old/path/exam.jpg',
        ]);

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('new.jpg'),
                'name'                => 'Exame Repetido',
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('allows same name in a different entity', function () {
        $other        = setupIntegrator();
        $otherPatient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        PatientExam::factory()->create([
            'patient_id' => $otherPatient->id,
            'name'       => 'Exame Compartilhado',
            'archive'    => 'other/exam.jpg',
        ]);

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Compartilhado',
            ],
            $this->ctx['headers']
        )->assertCreated();
    });

    it('returns 422 when schedule_identifier is missing', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => UploadedFile::fake()->image('exam.jpg'),
                'name'            => 'Exame Sem Schedule',
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 422 when schedule_identifier does not exist', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => 'SDL-NAOEXISTE',
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Schedule Inválido',
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 422 when schedule belongs to another entity', function () {
        $other         = setupIntegrator();
        $otherSchedCtx = createScheduleForEntity($other['entity']);

        // Usa UUID (globalmente único) para evitar colisão de códigos entre entities
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $otherSchedCtx['schedule']->id,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Schedule Outra Entidade',
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 422 when exam_identifier is missing', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Sem Tipo',
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 422 when exam_identifier does not exist', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => 'ETP-NAOEXISTE',
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Tipo Inválido',
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 422 when archive is missing', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'name'                => 'Exame Sem Arquivo',
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 422 when name is missing', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
            ],
            $this->ctx['headers']
        )->assertUnprocessable();
    });

    it('returns 401 without authentication', function () {
        $this->postJson('/api/integrators/v1/exams', [])->assertUnauthorized();
    });
});
