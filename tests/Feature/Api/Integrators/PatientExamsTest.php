<?php

use App\Models\{EntityIntegratorEquipment, ExamType, Patient, PatientExam};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ---------------------------------------------------------------------------
// GET /api/integrators/v1/patients/{patient}/exams
// ---------------------------------------------------------------------------
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

    it('lists exams for a patient using integer number', function () {
        PatientExam::factory(2)->create(['patient_id' => $this->patient->id]);

        $numericPart = (int) substr($this->patient->code, 4); // remove 'PAC-'

        $this->getJson("/api/integrators/v1/patients/{$numericPart}/exams", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    });

    it('does not return exams from other patients in the same entity', function () {
        $otherPatient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        PatientExam::factory(2)->create(['patient_id' => $this->patient->id]);
        PatientExam::factory(3)->create(['patient_id' => $otherPatient->id]);

        $this->getJson("/api/integrators/v1/patients/{$this->patient->id}/exams", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    });

    it('searches exams by patient code', function () {
        PatientExam::factory()->create(['patient_id' => $this->patient->id]);

        $response = $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams?search={$this->patient->code}",
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('searches exams by schedule code', function () {
        $schedCtx = createScheduleForEntity($this->ctx['entity']);
        PatientExam::factory()->create([
            'patient_id'  => $this->patient->id,
            'schedule_id' => $schedCtx['schedule']->id,
        ]);
        PatientExam::factory()->create(['patient_id' => $this->patient->id]);

        $response = $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams?search={$schedCtx['schedule']->code}",
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('searches exams by doctor code', function () {
        $schedCtx = createScheduleForEntity($this->ctx['entity']);
        PatientExam::factory()->create([
            'patient_id' => $this->patient->id,
            'doctor_id'  => $schedCtx['doctor']->id,
        ]);
        PatientExam::factory()->create(['patient_id' => $this->patient->id]);

        $response = $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams?search={$schedCtx['doctor']->code}",
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('returns empty result when search has no matches', function () {
        PatientExam::factory(3)->create(['patient_id' => $this->patient->id]);

        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams?search=XXXXNAOEXISTEXXXX",
            $this->ctx['headers'],
        )->assertOk()
            ->assertJsonPath('meta.total', 0);
    });

    it('caps per_page at 100', function () {
        PatientExam::factory(5)->create(['patient_id' => $this->patient->id]);

        $response = $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams?per_page=999",
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.per_page'))->toBeLessThanOrEqual(100);
    });

    it('returns 404 for patient from another entity', function () {
        $other   = setupIntegrator();
        $patient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->getJson("/api/integrators/v1/patients/{$patient->id}/exams", $this->ctx['headers'])
            ->assertNotFound();
    });

    it('returns 404 when patient code belongs to another entity', function () {
        $other = setupIntegrator();
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

// ---------------------------------------------------------------------------
// POST /api/integrators/v1/patients/{patient}/exams
// ---------------------------------------------------------------------------
describe('POST /api/integrators/v1/patients/{patient}/exams', function () {
    beforeEach(function () {
        Storage::fake('s3');

        $this->ctx      = setupIntegrator();
        $this->patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $this->examType = ExamType::factory()->create(['entity_id' => null]);

        $schedCtx       = createScheduleForEntity($this->ctx['entity']);
        $this->schedule = $schedCtx['schedule'];
        $this->doctor   = $schedCtx['doctor'];
    });

    it('creates a patient exam and returns 201', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Fundoscopia 01',
            ],
            $this->ctx['headers'],
        )->assertCreated()
            ->assertJsonFragment(['name' => 'Exame Fundoscopia 01']);

        $exam = PatientExam::where('name', 'Exame Fundoscopia 01')->first();
        expect($exam)->not->toBeNull();
        expect($exam->patient_id)->toBe($this->patient->id);
        Storage::disk('s3')->assertExists($exam->archive);
    });

    it('resolves doctor_id from schedule', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->id,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Com Médico',
            ],
            $this->ctx['headers'],
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Com Médico')->first();
        expect($exam->doctor_id)->toBe($this->doctor->id);
        expect($exam->schedule_id)->toBe($this->schedule->id);
    });

    it('accepts exam_identifier as UUID', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->id,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Por UUID',
            ],
            $this->ctx['headers'],
        )->assertCreated()
            ->assertJsonFragment(['name' => 'Exame Por UUID']);
    });

    it('returns 404 when patient is identified by code (UUID required)', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Via Código',
            ],
            $this->ctx['headers'],
        )->assertNotFound();
    });

    it('returns 404 for patient UUID from another entity', function () {
        $other   = setupIntegrator();
        $patient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->postJson(
            "/api/integrators/v1/patients/{$patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Outra Entidade',
            ],
            $this->ctx['headers'],
        )->assertNotFound();
    });

    it('returns 422 when name already exists in the entity', function () {
        PatientExam::factory()->create([
            'patient_id' => $this->patient->id,
            'name'       => 'Exame Repetido',
            'archive'    => 'old/path/exam.jpg',
        ]);

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Repetido',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('allows same name in different entities', function () {
        $other        = setupIntegrator();
        $otherPatient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        PatientExam::factory()->create([
            'patient_id' => $otherPatient->id,
            'name'       => 'Exame Único',
            'archive'    => 'other/exam.jpg',
        ]);

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Único',
            ],
            $this->ctx['headers'],
        )->assertCreated();
    });

    it('returns 422 when exam_identifier does not exist', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => 'CODIGO-INEXISTENTE',
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Inválido',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('returns 422 when schedule_identifier does not exist', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => 'SDL-INEXISTENTE',
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Sem Schedule',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('returns 422 when schedule_identifier belongs to another entity', function () {
        $other         = setupIntegrator();
        $otherSchedCtx = createScheduleForEntity($other['entity']);

        // Usa UUID (globalmente único) para evitar colisão de códigos entre entities
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $otherSchedCtx['schedule']->id,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Schedule Errado',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('returns 422 when name is missing', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('returns 422 when archive is missing', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'name'                => 'Exame Sem Arquivo',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('returns 422 when exam_identifier is missing', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Sem Tipo',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('returns 422 when schedule_identifier is missing', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => UploadedFile::fake()->image('exam.jpg'),
                'name'            => 'Exame Sem Schedule',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('creates exam with equipment_id and returns it in response', function () {
        $equipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
        ]);

        $response = $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'      => $this->examType->code,
                'schedule_identifier'  => $this->schedule->code,
                'archive'              => UploadedFile::fake()->image('exam.jpg'),
                'name'                 => 'Exame Com Equipamento',
                'equipment_identifier' => $equipment->id,
            ],
            $this->ctx['headers'],
        )->assertCreated();

        expect($response->json('data.attributes.entity_integrator_equipment_id'))
            ->toBe($equipment->id);
    });

    it('returns 422 when equipment belongs to another integrator', function () {
        $other          = setupIntegrator();
        $otherEquipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $other['integrator']->id,
        ]);

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'      => $this->examType->code,
                'schedule_identifier'  => $this->schedule->code,
                'archive'              => UploadedFile::fake()->image('exam.jpg'),
                'name'                 => 'Exame Equipamento Errado',
                'equipment_identifier' => $otherEquipment->id,
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('creates exam without equipment_id (optional field)', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Sem Equipamento',
            ],
            $this->ctx['headers'],
        )->assertCreated();
    });

    it('stores laterality when provided', function () {
        $response = $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Lateralidade Esquerda',
                'laterality'          => 1,
            ],
            $this->ctx['headers'],
        )->assertCreated();

        expect($response->json('data.attributes.laterality'))->toBe(1);
        expect(PatientExam::where('name', 'Exame Lateralidade Esquerda')->first()->laterality)->toBe(1);
    });

    it('stores null laterality when not provided', function () {
        $response = $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Sem Lateralidade',
            ],
            $this->ctx['headers'],
        )->assertCreated();

        expect($response->json('data.attributes.laterality'))->toBeNull();
    });

    it('returns 422 when laterality is invalid', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Lateralidade Inválida',
                'laterality'          => 3,
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('returns 401 without authentication', function () {
        $this->postJson("/api/integrators/v1/patients/{$this->patient->id}/exams", [])
            ->assertUnauthorized();
    });
});

// ---------------------------------------------------------------------------
// GET /api/integrators/v1/patients/{patient}/exams/{exam}
// ---------------------------------------------------------------------------
describe('GET /api/integrators/v1/patients/{patient}/exams/{exam}', function () {
    beforeEach(function () {
        $this->ctx     = setupIntegrator();
        $this->patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $this->exam    = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
    });

    it('shows exam by patient UUID and exam UUID', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            $this->ctx['headers'],
        )->assertOk()
            ->assertJsonFragment(['id' => $this->exam->id]);
    });

    it('shows exam by patient UUID and exam code', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->code}",
            $this->ctx['headers'],
        )->assertOk()
            ->assertJsonFragment(['id' => $this->exam->id]);
    });

    it('shows exam by patient code and exam UUID', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams/{$this->exam->id}",
            $this->ctx['headers'],
        )->assertOk()
            ->assertJsonFragment(['id' => $this->exam->id]);
    });

    it('shows exam by patient integer number and exam UUID', function () {
        $numericPart = (int) substr($this->patient->code, 4); // remove 'PAC-'

        $this->getJson(
            "/api/integrators/v1/patients/{$numericPart}/exams/{$this->exam->id}",
            $this->ctx['headers'],
        )->assertOk()
            ->assertJsonFragment(['id' => $this->exam->id]);
    });

    it('shows exam by patient code and exam code', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams/{$this->exam->code}",
            $this->ctx['headers'],
        )->assertOk()
            ->assertJsonFragment(['id' => $this->exam->id]);
    });

    it('returns 404 for non-existent exam', function () {
        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/EXM-NAOEXISTE",
            $this->ctx['headers'],
        )->assertNotFound();
    });

    it('returns 404 when exam belongs to another patient', function () {
        $otherPatient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $otherExam    = PatientExam::factory()->create(['patient_id' => $otherPatient->id]);

        $this->getJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$otherExam->id}",
            $this->ctx['headers'],
        )->assertNotFound();
    });

    it('returns 404 when patient code belongs to another entity', function () {
        $other = setupIntegrator();
        Patient::factory()->create(['entity_id' => $other['entity']->id]);
        $foreignPatient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->getJson(
            "/api/integrators/v1/patients/{$foreignPatient->code}/exams/{$this->exam->id}",
            $this->ctx['headers'],
        )->assertNotFound();
    });
});

// ---------------------------------------------------------------------------
// POST /api/integrators/v1/patients/{patient}/exams/{exam}  (update)
// ---------------------------------------------------------------------------
describe('POST /api/integrators/v1/patients/{patient}/exams/{exam} (update)', function () {
    beforeEach(function () {
        Storage::fake('s3');

        $this->ctx      = setupIntegrator();
        $this->patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $this->examType = ExamType::factory()->create(['entity_id' => null]);
        $this->exam     = PatientExam::factory()->create([
            'patient_id' => $this->patient->id,
            'name'       => 'Exame Original',
            'archive'    => 'old/path/exam.jpg',
        ]);

        $schedCtx       = createScheduleForEntity($this->ctx['entity']);
        $this->schedule = $schedCtx['schedule'];
        $this->doctor   = $schedCtx['doctor'];
    });

    it('updates exam using patient UUID and exam UUID', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('updated.jpg'),
                'name'                => 'Exame Atualizado',
            ],
            $this->ctx['headers'],
        )->assertOk()
            ->assertJsonFragment(['name' => 'Exame Atualizado']);
    });

    it('updates exam using patient UUID and exam code', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->code}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('updated.jpg'),
                'name'                => 'Atualizado UUID+Código',
            ],
            $this->ctx['headers'],
        )->assertOk()
            ->assertJsonFragment(['name' => 'Atualizado UUID+Código']);
    });

    it('resolves doctor_id from schedule on update', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->id,
                'archive'             => UploadedFile::fake()->image('updated.jpg'),
                'name'                => 'Exame Médico Atualizado',
            ],
            $this->ctx['headers'],
        )->assertOk();

        expect(PatientExam::find($this->exam->id)->doctor_id)->toBe($this->doctor->id);
    });

    it('replaces old archive on S3 when updating', function () {
        Storage::disk('s3')->put('old/path/exam.jpg', 'conteudo antigo');

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('new.jpg'),
                'name'                => 'Exame Original',
            ],
            $this->ctx['headers'],
        )->assertOk();

        Storage::disk('s3')->assertMissing('old/path/exam.jpg');
        Storage::disk('s3')->assertExists(PatientExam::find($this->exam->id)->archive);
    });

    it('returns 404 when patient is identified by code (UUID required)', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams/{$this->exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Via Código',
            ],
            $this->ctx['headers'],
        )->assertNotFound();
    });

    it('returns 422 when name conflicts with another exam in the entity', function () {
        PatientExam::factory()->create([
            'patient_id' => $this->patient->id,
            'name'       => 'Nome Já Existente',
            'archive'    => 'other/exam.jpg',
        ]);

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Nome Já Existente',
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('allows updating name to the same value (no self-conflict)', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Original',
            ],
            $this->ctx['headers'],
        )->assertOk();
    });

    it('updates laterality on update', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Original',
                'laterality'          => 2,
            ],
            $this->ctx['headers'],
        )->assertOk();

        expect(PatientExam::find($this->exam->id)->laterality)->toBe(2);
    });

    it('returns 422 when laterality is invalid on update', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Original',
                'laterality'          => 9,
            ],
            $this->ctx['headers'],
        )->assertUnprocessable();
    });

    it('returns 404 when patient from another entity', function () {
        $other = setupIntegrator();
        Patient::factory()->create(['entity_id' => $other['entity']->id]);
        $foreignPatient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->postJson(
            "/api/integrators/v1/patients/{$foreignPatient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Outra Entidade',
            ],
            $this->ctx['headers'],
        )->assertNotFound();
    });

    // BUGFIX (revisão de segurança): buildUpdateData() espalhava patient_id do
    // body direto no update() sem re-derivar, ao contrário de exam_id/doctor_id/
    // schedule_id. Um integrador com token de escrita da própria clínica podia
    // reatribuir o exame de um paciente para outro (mesmo tenant) só enviando
    // patient_id no body. Fix: patient_id removido de FILLABLE_FIELDS + campo
    // marcado 'prohibited' no FormRequest (defesa em profundidade).
    it('rejects patient_id in the update body and does not reassign the exam', function () {
        $otherPatient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);

        $this->postJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->schedule->code,
                'archive'             => UploadedFile::fake()->image('updated.jpg'),
                'name'                => 'Exame Original',
                'patient_id'          => $otherPatient->id,
            ],
            $this->ctx['headers'],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');

        expect(PatientExam::find($this->exam->id)->patient_id)->toBe($this->patient->id);
    });
});

// ---------------------------------------------------------------------------
// DELETE /api/integrators/v1/patients/{patient}/exams/{exam}
// ---------------------------------------------------------------------------
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
            $this->ctx['headers'],
        )->assertNoContent();

        expect(PatientExam::find($this->exam->id))->toBeNull();
    });

    it('deletes a patient exam using patient code and exam UUID', function () {
        $this->deleteJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams/{$this->exam->id}",
            [],
            $this->ctx['headers'],
        )->assertNoContent();

        expect(PatientExam::find($this->exam->id))->toBeNull();
    });

    it('deletes a patient exam using patient integer number and exam UUID', function () {
        $numericPart = (int) substr($this->patient->code, 4); // remove 'PAC-'

        $this->deleteJson(
            "/api/integrators/v1/patients/{$numericPart}/exams/{$this->exam->id}",
            [],
            $this->ctx['headers'],
        )->assertNoContent();

        expect(PatientExam::find($this->exam->id))->toBeNull();
    });

    it('deletes a patient exam using patient UUID and exam code', function () {
        $this->deleteJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->code}",
            [],
            $this->ctx['headers'],
        )->assertNoContent();

        expect(PatientExam::find($this->exam->id))->toBeNull();
    });

    it('deletes a patient exam using patient code and exam code', function () {
        $this->deleteJson(
            "/api/integrators/v1/patients/{$this->patient->code}/exams/{$this->exam->code}",
            [],
            $this->ctx['headers'],
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
            $this->ctx['headers'],
        )->assertNotFound();
    });

    it('returns 404 when exam does not exist', function () {
        $this->deleteJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/EXM-NAOEXISTE",
            [],
            $this->ctx['headers'],
        )->assertNotFound();
    });

    it('returns 401 without authentication', function () {
        $this->deleteJson(
            "/api/integrators/v1/patients/{$this->patient->id}/exams/{$this->exam->id}",
        )->assertUnauthorized();
    });
});
