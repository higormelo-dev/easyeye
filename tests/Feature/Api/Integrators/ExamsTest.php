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

    it('exame capturado nasce habilitado (entra em laudo, IA e repasse)', function () {
        $this->postJson('/api/integrators/v1/exams', [
            'exam_identifier'     => $this->examType->code,
            'schedule_identifier' => $this->schedule->code,
            'archive'             => UploadedFile::fake()->image('exam.jpg'),
            'name'                => 'Exame Ativo 01',
        ], $this->ctx['headers'])->assertCreated();

        expect(PatientExam::query()->where('name', 'Exame Ativo 01')->sole()->active)->toBeTrue();
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

    it('accepts schedule_identifier as import_code (schedule from legacy system)', function () {
        $this->schedule->forceFill(['import_code' => 'LEGACY-SDL-777'])->save();

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => 'LEGACY-SDL-777',
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Schedule Import Code',
            ],
            $this->ctx['headers'],
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Schedule Import Code')->first();
        expect($exam->schedule_id)->toBe($this->schedule->id)
            ->and($exam->patient_id)->toBe($this->patient->id);
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
        // Relógio fixo no meio do dia: entre 00h e 03h, "agora − 3h" caía em ontem.
        $this->travelTo(today()->setTime(15, 0));

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

    it('creates exam via patient_identifier as import_code (patient from legacy system)', function () {
        $patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $patient->forceFill(['import_code' => 'LEGACY-PAC-321'])->save();

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'    => $this->examType->code,
                'patient_identifier' => 'LEGACY-PAC-321',
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
                'name'               => 'Exame Via Patient Import Code',
            ],
            $this->ctx['headers'],
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Via Patient Import Code')->first();
        expect($exam->patient_id)->toBe($patient->id);
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

// ---------------------------------------------------------------------------
// POST /api/integrators/v1/exams — data/hora real do exame e observação
// lidas pelo integrador do arquivo do equipamento (ex.: .EMR do Keratograph:
// "Exam Date"/"Exam Time" e "Display").
// ---------------------------------------------------------------------------
describe('POST /api/integrators/v1/exams — exam_performed_at / observation', function () {
    beforeEach(function () {
        Storage::fake('s3');

        $this->ctx      = setupIntegrator();
        $this->examType = ExamType::factory()->create(['entity_id' => null]);
        $this->patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
    });

    it('stores exam_performed_at and observation sent by the integrator', function () {
        $this->postJson('/api/integrators/v1/exams', [
            'exam_identifier'    => $this->examType->code,
            'patient_identifier' => $this->patient->id,
            'archive'            => UploadedFile::fake()->image('exam.jpg'),
            'name'               => 'Keratograph Topo',
            'exam_performed_at'  => '2026-02-12T09:32:25-03:00',
            'observation'        => 'Display: Topo 4-Maps',
        ], $this->ctx['headers'])
            ->assertCreated()
            ->assertJsonPath('data.attributes.observation', 'Display: Topo 4-Maps');

        $exam = PatientExam::where('name', 'Keratograph Topo')->first();
        expect($exam->exam_performed_at->equalTo(Carbon\Carbon::parse('2026-02-12T12:32:25Z')))->toBeTrue()
            ->and($exam->observation)->toBe('Display: Topo 4-Maps');
    });

    it('accepts a date-only exam_performed_at', function () {
        $this->postJson('/api/integrators/v1/exams', [
            'exam_identifier'    => $this->examType->code,
            'patient_identifier' => $this->patient->id,
            'archive'            => UploadedFile::fake()->image('exam.jpg'),
            'name'               => 'Somente Data',
            'exam_performed_at'  => '2026-03-01',
        ], $this->ctx['headers'])->assertCreated();

        expect(PatientExam::where('name', 'Somente Data')->first()->exam_performed_at->toDateString())
            ->toBe('2026-03-01');
    });

    it('keeps both fields null when the integrator does not send them', function () {
        $this->postJson('/api/integrators/v1/exams', [
            'exam_identifier'    => $this->examType->code,
            'patient_identifier' => $this->patient->id,
            'archive'            => UploadedFile::fake()->image('exam.jpg'),
            'name'               => 'Sem Detalhes',
        ], $this->ctx['headers'])->assertCreated();

        $exam = PatientExam::where('name', 'Sem Detalhes')->first();
        expect($exam->exam_performed_at)->toBeNull()
            ->and($exam->observation)->toBeNull();
    });

    it('links to the schedule of the EXAM day, not of the upload day', function () {
        $examDay   = now()->subDays(3)->setTime(9, 30);
        $pastSched = createScheduleForEntity($this->ctx['entity'], [
            'patient_id' => $this->patient->id,
            'date_time'  => $examDay->copy()->setTime(9, 0),
        ]);
        createScheduleForEntity($this->ctx['entity'], ['patient_id' => $this->patient->id]); // hoje

        $this->postJson('/api/integrators/v1/exams', [
            'exam_identifier'    => $this->examType->code,
            'patient_identifier' => $this->patient->id,
            'archive'            => UploadedFile::fake()->image('exam.jpg'),
            'name'               => 'Backlog Offline',
            'exam_performed_at'  => $examDay->toIso8601String(),
        ], $this->ctx['headers'])->assertCreated();

        $exam = PatientExam::where('name', 'Backlog Offline')->first();
        expect($exam->schedule_id)->toBe($pastSched['schedule']->id)
            ->and($exam->doctor_id)->toBe($pastSched['doctor']->id);
    });

    it('rejects an invalid, far-future or pre-2000 exam_performed_at', function (string $value) {
        $this->postJson('/api/integrators/v1/exams', [
            'exam_identifier'    => $this->examType->code,
            'patient_identifier' => $this->patient->id,
            'archive'            => UploadedFile::fake()->image('exam.jpg'),
            'name'               => 'Data Invalida',
            'exam_performed_at'  => $value,
        ], $this->ctx['headers'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('exam_performed_at');
    })->with([
        'lixo'   => ['nao-e-data'],
        'futuro' => [fn () => now()->addDays(10)->toIso8601String()],
        'antigo' => ['1999-12-31'],
    ]);

    it('rejects an observation longer than 1000 chars', function () {
        $this->postJson('/api/integrators/v1/exams', [
            'exam_identifier'    => $this->examType->code,
            'patient_identifier' => $this->patient->id,
            'archive'            => UploadedFile::fake()->image('exam.jpg'),
            'name'               => 'Obs Longa',
            'observation'        => str_repeat('x', 1001),
        ], $this->ctx['headers'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('observation');
    });

    it('re-upload without the fields keeps the values already captured', function () {
        $payload = [
            'exam_identifier'    => $this->examType->code,
            'patient_identifier' => $this->patient->id,
            'name'               => 'Reenvio',
        ];

        $this->postJson('/api/integrators/v1/exams', $payload + [
            'archive'           => UploadedFile::fake()->image('a.jpg'),
            'exam_performed_at' => '2026-02-12T09:32:25-03:00',
            'observation'       => 'Display: Topo 4-Maps',
        ], $this->ctx['headers'])->assertCreated();

        $this->postJson('/api/integrators/v1/exams', $payload + [
            'archive' => UploadedFile::fake()->image('b.jpg'),
        ], $this->ctx['headers']);

        $exam = PatientExam::where('name', 'Reenvio')->first();
        expect($exam->observation)->toBe('Display: Topo 4-Maps')
            ->and($exam->exam_performed_at)->not->toBeNull();
    });
});
