<?php

use App\Models\{EntityIntegratorEquipment, ExamType, Patient, PatientExam};
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
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
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('stores laterality when provided', function () {
        $response = $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Lateralidade Direita',
                'laterality'          => 2,
            ],
            $this->ctx['headers'],
        )->assertCreated();

        expect($response->json('data.attributes.laterality'))->toBe(2);
    });

    it('returns 422 when laterality is invalid', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Lateralidade Inválida',
                'laterality'          => 9,
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('creates exam with equipment_identifier and returns it in the response', function () {
        $equipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
        ]);

        $response = $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'      => $this->examType->code,
                'schedule_identifier'  => $this->schedule->code,
                'archive'              => UploadedFile::fake()->image('exam.jpg'),
                'name'                 => 'Exame Com Equipamento',
                'equipment_identifier' => $equipment->id,
            ],
            $this->ctx['headers'],
        )->assertCreated();

        expect($response->json('data.attributes.entity_integrator_equipment_id'))->toBe($equipment->id);
    });

    it('returns 422 when equipment_identifier belongs to another integrator', function () {
        $other          = setupIntegrator();
        $otherEquipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $other['integrator']->id,
        ]);

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'      => $this->examType->code,
                'schedule_identifier'  => $this->schedule->code,
                'archive'              => UploadedFile::fake()->image('exam.jpg'),
                'name'                 => 'Exame Equipamento Errado',
                'equipment_identifier' => $otherEquipment->id,
            ],
            $this->ctx['headers'],
        )->assertUnprocessable()->assertJsonValidationErrors('equipment_identifier');
    });

    it('returns 401 without authentication', function () {
        $this->postJson('/api/integrators/v1/exams', [])->assertUnauthorized();
    });
});

// ---------------------------------------------------------------------------
// POST /api/integrators/v1/exams — resolvendo por patient_identifier
// (fluxo alternativo de PatientExamService::createFromScheduleIdentifier
// quando schedule_identifier está ausente: patient_identifier + agendamento
// mais recente do dia, se houver)
// ---------------------------------------------------------------------------
describe('POST /api/integrators/v1/exams — patient_identifier branch', function () {
    beforeEach(function () {
        Storage::fake('s3');

        $this->ctx      = setupIntegrator();
        $this->examType = ExamType::factory()->create(['entity_id' => null]);
    });

    it('creates exam via patient_identifier, resolving doctor/schedule from a schedule today', function () {
        $patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $schedCtx = createScheduleForEntity($this->ctx['entity'], ['patient_id' => $patient->id]);

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'    => $this->examType->code,
                'patient_identifier' => $patient->code,
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
                'name'               => 'Exame Via Patient Identifier',
            ],
            $this->ctx['headers'],
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Via Patient Identifier')->first();
        expect($exam->patient_id)->toBe($patient->id)
            ->and($exam->doctor_id)->toBe($schedCtx['doctor']->id)
            ->and($exam->schedule_id)->toBe($schedCtx['schedule']->id);
    });

    it('creates exam via patient_identifier with null doctor/schedule when patient has no schedule today', function () {
        $patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'    => $this->examType->code,
                'patient_identifier' => $patient->id,
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
                'name'               => 'Exame Sem Agenda Hoje',
            ],
            $this->ctx['headers'],
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Sem Agenda Hoje')->first();
        expect($exam->patient_id)->toBe($patient->id)
            ->and($exam->doctor_id)->toBeNull()
            ->and($exam->schedule_id)->toBeNull();
    });

    it('ignores a schedule that is not today when resolving via patient_identifier', function () {
        $patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        createScheduleForEntity($this->ctx['entity'], [
            'patient_id' => $patient->id,
            'date_time'  => now()->subDay(),
        ]);

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'    => $this->examType->code,
                'patient_identifier' => $patient->id,
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
                'name'               => 'Exame Agenda Passada',
            ],
            $this->ctx['headers'],
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Agenda Passada')->first();
        expect($exam->doctor_id)->toBeNull()
            ->and($exam->schedule_id)->toBeNull();
    });

    it("picks the most recent of today's schedules when the patient has more than one", function () {
        $patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        createScheduleForEntity($this->ctx['entity'], [
            'patient_id' => $patient->id,
            'date_time'  => now()->subHours(3),
        ]);
        $recent = createScheduleForEntity($this->ctx['entity'], [
            'patient_id' => $patient->id,
            'date_time'  => now()->subHour(),
        ]);

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'    => $this->examType->code,
                'patient_identifier' => $patient->id,
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
                'name'               => 'Exame Agenda Mais Recente',
            ],
            $this->ctx['headers'],
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Agenda Mais Recente')->first();
        expect($exam->schedule_id)->toBe($recent['schedule']->id)
            ->and($exam->doctor_id)->toBe($recent['doctor']->id);
    });

    it('prioritizes schedule_identifier over patient_identifier when both are sent', function () {
        $patientA = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $patientB = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $schedCtx = createScheduleForEntity($this->ctx['entity'], ['patient_id' => $patientB->id]);

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'patient_identifier'  => $patientA->id,
                'schedule_identifier' => $schedCtx['schedule']->id,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Prioridade Schedule',
            ],
            $this->ctx['headers'],
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Prioridade Schedule')->first();
        expect($exam->patient_id)->toBe($patientB->id)
            ->and($exam->patient_id)->not->toBe($patientA->id);
    });

    it('returns 422 when patient_identifier does not exist', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'    => $this->examType->code,
                'patient_identifier' => 'PAC-NAOEXISTE',
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
                'name'               => 'Exame Patient Inexistente',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable()->assertJsonValidationErrors('patient_identifier');
    });

    it('returns 422 when patient_identifier belongs to another entity', function () {
        $other        = setupIntegrator();
        $otherPatient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'    => $this->examType->code,
                'patient_identifier' => $otherPatient->id,
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
                'name'               => 'Exame Patient Outra Entidade',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable()->assertJsonValidationErrors('patient_identifier');
    });

    it('returns 422 when patient_identifier looks like a UUID but is malformed', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'    => $this->examType->code,
                'patient_identifier' => '12345678-1234-ZZZZ-ZZZZ-ZZZZZZZZZZZZ',
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
                'name'               => 'Exame UUID Malformado',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable()->assertJsonValidationErrors('patient_identifier');
    });

    it('returns 422 when neither patient_identifier nor schedule_identifier is provided', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => UploadedFile::fake()->image('exam.jpg'),
                'name'            => 'Exame Sem Identificador',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_identifier', 'schedule_identifier']);
    });
});
