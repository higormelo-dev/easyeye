<?php

declare(strict_types=1);

use App\Domains\Recall\Exceptions\RecallActionException;
use App\Domains\Recall\Intravitreal\Enums\{ApplicationStatus, CourseStatus};
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Models\PatientRecall;
use App\Domains\Recall\Services\{RecallActionService, RecallCandidates};
use App\Domains\Recall\Services\{RecallDirtyDrainer, RecallEvaluator};
use App\Enums\{ClientRule, FeatureKey, MedicalRecordProcedureStatus, ScheduleSituation, SubscriptionStatus};
use App\Jobs\NotifyScheduleChangeJob;
use App\Models\{Doctor, DoctorWorkSchedule, Entity, EntityProduct, MedicalRecord, MedicalRecordProcedure, Patient, People, Plan, PlanFeature, Procedure, Schedule, StockLot, Subscription, User};
use App\Notifications\ScheduleNotification;
use App\Services\ScheduleService;
use App\Services\Stock\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Queue};
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * Injeção intravítrea (anti-VEGF) no procedimento estruturado do prontuário (Fase 6, ADR 0002 item 14): a solicitação
 * abre (ou continua) o curso do olho, a execução registra a aplicação (lote, autorização, intervalo decidido) NA
 * MESMA transação do `mark-done`, e a próxima aplicação vira retorno. Fixa "hoje" em 2026-03-10 para não depender do
 * relógio (virada de dia/mês).
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $plan         = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($plan)->create();
    PlanFeature::factory()->enabled(FeatureKey::HasPatientRecall)->for($plan)->create();

    Subscription::factory()->create([
        'entity_id' => $this->entity->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $this->doctorAccount = User::factory()->create();
    $this->doctorEu      = createEntityUser($this->entity, $this->doctorAccount, ClientRule::Doctor->value);
    $this->doctor        = Doctor::query()->create([
        'entity_user_id' => $this->doctorEu->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    $this->secretaryAccount = User::factory()->create();
    $this->secretaryEu      = createEntityUser($this->entity, $this->secretaryAccount, ClientRule::Secretary->value);

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $this->record  = MedicalRecord::query()->create([
        'entity_id'  => $this->entity->id,
        'patient_id' => $this->patient->id,
        'doctor_id'  => $this->doctor->id,
    ]);

    $this->ivt = Procedure::query()->create([
        'entity_id' => $this->entity->id, 'code' => 'IVT-T', 'name' => 'Injeção intravítrea', 'clinical_kind' => Procedure::KIND_INTRAVITREAL, 'active' => true,
    ]);

    $this->plain = Procedure::query()->create([
        'entity_id' => $this->entity->id, 'code' => 'PROC-X', 'name' => 'Curativo', 'active' => true,
    ]);
});

function ivtAsDoctor($test)
{
    return $test->actingAs($test->doctorAccount)->withSession(panelSession($test->doctorEu));
}

function ivtAsSecretary($test)
{
    return $test->actingAs($test->secretaryAccount)->withSession(panelSession($test->secretaryEu));
}

/** Solicita a injeção (cria o procedimento + o curso do olho). */
function ivtRequest($test, array $overrides = []): TestResponse
{
    return ivtAsDoctor($test)->postJson(
        route('panel.patients.medicalrecords.medicalrecord-procedures.store', [$test->patient->id, $test->record->id]),
        array_merge([
            'procedure_id' => $test->ivt->id,
            'eye'          => 'right',
            'intravitreal' => ['drug_key' => 'aflibercept', 'regimen' => 'loading_tae', 'indication' => 'DMRI neovascular'],
        ], $overrides),
    );
}

/** Dados válidos da aplicação (lote dentro da validade, intervalo de 8 semanas). */
function ivtExecution(array $overrides = []): array
{
    return array_merge([
        'lot_number'          => 'LOT-123',
        'lot_expiry'          => '2027-01-31',
        'next_interval_weeks' => 8,
    ], $overrides);
}

function ivtMarkDone($test, string $procedureId, array $intravitreal, array $body = []): TestResponse
{
    return ivtAsDoctor($test)->postJson(
        route('panel.patients.medicalrecord-procedures.mark-done', [$test->patient->id, $procedureId]),
        array_merge(['intravitreal' => $intravitreal], $body),
    );
}

function ivtCourse($test, string $eye = 'right'): ?IntravitrealCourse
{
    return IntravitrealCourse::query()->withoutGlobalScopes()->where('patient_id', $test->patient->id)->where('eye', $eye)->first();
}

/** As linhas da tela de Retornos (visão "a contatar") vistas por quem está logado. */
function ivtRecallRows($test, $as): array
{
    $rows = [];

    $as($test)->get(route('panel.recall.index', ['view' => 'to_contact']))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$rows) {
        $rows = $page->toArray()['props']['rows']['data'];
    });

    return $rows;
}

function ivtEvaluate($test): void
{
    app(RecallEvaluator::class)->evaluatePatient((string) $test->entity->id, (string) $test->patient->id);
}

// ── solicitação ─────────────────────────────────────────────────────────────

it('a solicitação abre o curso do olho e planeja a primeira aplicação ligada ao procedimento', function () {
    $res = ivtRequest($this)->assertCreated();

    $course = ivtCourse($this);

    expect($res->json('data.clinical_kind'))->toBe(Procedure::KIND_INTRAVITREAL)
        ->and($course)->not->toBeNull()
        ->and($course->status)->toBe(CourseStatus::Active)
        ->and($course->drug_key)->toBe('aflibercept')
        ->and($course->drug_label)->toBe('Aflibercepte')
        ->and($course->indication)->toBe('DMRI neovascular');

    $planned = IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->get();

    expect($planned)->toHaveCount(1)
        ->and($planned[0]->sequence_no)->toBe(1)
        ->and($planned[0]->status)->toBe(ApplicationStatus::Planned)
        ->and((string) $planned[0]->medical_record_procedure_id)->toBe($res->json('data.id'));
});

it('sem olho definido (ou em AO) a solicitação é recusada e NADA é gravado: cada olho tem o seu curso', function () {
    foreach ([null, 'both'] as $eye) {
        ivtRequest($this, ['eye' => $eye])->assertStatus(422)->assertJsonPath('errors.eye.0', __('recall_course.errors.eye_required'));
    }

    expect(MedicalRecordProcedure::query()->count())->toBe(0)
        ->and(IntravitrealCourse::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('sem a droga, num olho sem curso aberto, a solicitação é recusada por inteiro (o procedimento não fica órfão)', function () {
    $res = ivtRequest($this, ['intravitreal' => ['regimen' => 'prn']])->assertStatus(422);

    expect($res->json('errors.drug_key'))->not->toBeNull()
        ->and(MedicalRecordProcedure::query()->count())->toBe(0)
        ->and(IntravitrealCourse::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('droga fora da lista e "outra" sem nome são recusadas; "outra" com nome vira o rótulo gravado', function () {
    ivtRequest($this, ['intravitreal' => ['drug_key' => 'inventada']])->assertStatus(422);
    ivtRequest($this, ['intravitreal' => ['drug_key' => 'other']])->assertStatus(422);

    ivtRequest($this, ['intravitreal' => ['drug_key' => 'other', 'drug_label' => 'Conbercepte']])->assertCreated();

    expect(ivtCourse($this)->drug_label)->toBe('Conbercepte');
});

it('o segundo pedido no mesmo olho continua o curso aberto (não abre outro) e dispensa a droga', function () {
    ivtRequest($this)->assertCreated();
    ivtRequest($this, ['intravitreal' => null])->assertCreated();

    expect(IntravitrealCourse::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->count())->toBe(1);
});

it('cada olho tem o seu curso', function () {
    ivtRequest($this, ['eye' => 'right'])->assertCreated();
    ivtRequest($this, ['eye' => 'left'])->assertCreated();

    expect(ivtCourse($this, 'right'))->not->toBeNull()
        ->and(ivtCourse($this, 'left'))->not->toBeNull()
        ->and(IntravitrealCourse::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('procedimento comum não abre curso nem pede dados de injeção', function () {
    ivtRequest($this, ['procedure_id' => $this->plain->id, 'eye' => null, 'intravitreal' => null])->assertCreated();

    expect(IntravitrealCourse::query()->withoutGlobalScopes()->count())->toBe(0);
});

// ── execução ────────────────────────────────────────────────────────────────

it('a execução registra a aplicação (lote, validade, intervalo), planeja a próxima e o retorno nasce com a data do médico', function () {
    $id = ivtRequest($this)->json('data.id');

    $res = ivtMarkDone($this, $id, ivtExecution())->assertOk();

    $course = ivtCourse($this)->refresh();
    $done   = IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->where('sequence_no', 1)->first();
    $next   = IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->where('sequence_no', 2)->first();

    expect($res->json('data.status'))->toBe('done')
        ->and($res->json('intravitreal.next_due_on'))->toBe('2026-05-05')
        ->and($res->json('intravitreal.closed'))->toBeFalse()
        ->and($done->status)->toBe(ApplicationStatus::Performed)
        ->and($done->lot_number)->toBe('LOT-123')
        ->and($done->lot_expiry->toDateString())->toBe('2027-01-31')
        ->and($done->decided_interval_weeks)->toBe(8)
        ->and((string) $done->performed_by_doctor_id)->toBe((string) $this->doctor->id)
        ->and($next->status)->toBe(ApplicationStatus::Planned)
        ->and($next->planned_for->toDateString())->toBe('2026-05-05')
        ->and($course->current_interval_weeks)->toBe(8)
        ->and($course->last_applied_on->toDateString())->toBe('2026-03-10')
        ->and($course->next_due_on->toDateString())->toBe('2026-05-05');

    // A outbox marcou o paciente na mesma transação; reavaliar grava o retorno do curso.
    expect(DB::table('recall_dirty_patients')->where('patient_id', $this->patient->id)->exists())->toBeTrue();

    ivtEvaluate($this);

    $recall = PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('source', 'course')->first();

    expect($recall)->not->toBeNull()
        ->and($recall->task_key)->toBe('course:' . $course->id)
        ->and($recall->kind)->toBe('treatment_course')
        ->and($recall->eye->value)->toBe('right')
        ->and($recall->due_at->toDateString())->toBe('2026-05-05')
        ->and($recall->last_done_at->toDateString())->toBe('2026-03-10')
        // Prazos curtos do curso: avisa 14 dias antes e a tolerância é de 7.
        ->and($recall->invite_from->toDateString())->toBe('2026-04-21')
        ->and($recall->overdue_from->toDateString())->toBe('2026-05-13');
});

it('sem lote a execução é recusada e o procedimento continua solicitado (nunca "injeção executada" sem lote)', function () {
    $id = ivtRequest($this)->json('data.id');

    $res = ivtMarkDone($this, $id, ['next_interval_weeks' => 8])->assertStatus(422);

    expect($res->json('message'))->toBe(__('recall_course.errors.lot_required'))
        ->and(MedicalRecordProcedure::query()->find($id)->status)->toBe(MedicalRecordProcedureStatus::Requested)
        ->and(IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Performed->value)->count())->toBe(0);
});

it('lote vencido não é aplicado, nem com aviso', function () {
    $id = ivtRequest($this)->json('data.id');

    $res = ivtMarkDone($this, $id, ivtExecution(['lot_expiry' => '2026-03-09', 'authorization_override' => true]))->assertStatus(422);

    expect($res->json('message'))->toBe(__('recall_course.errors.lot_expired'))
        ->and(MedicalRecordProcedure::query()->find($id)->status)->toBe(MedicalRecordProcedureStatus::Requested);
});

it('a falha da aplicação desfaz a baixa de estoque feita na mesma execução (atomicidade)', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Seringa', 'unit' => 'un', 'active' => true]);
    app(StockService::class)->manualIn($product, 5, 10.00);

    $id = ivtRequest($this)->json('data.id');

    ivtMarkDone($this, $id, ['next_interval_weeks' => 8], ['items' => [['entity_product_id' => $product->id, 'quantity' => 1]]])->assertStatus(422);

    expect((float) $product->fresh()->qty_on_hand)->toBe(5.0)
        ->and(MedicalRecordProcedure::query()->find($id)->status)->toBe(MedicalRecordProcedureStatus::Requested);
});

it('lote do estoque: usa número e validade do lote cadastrado; lote de outra clínica não existe', function () {
    $product = EntityProduct::create(['entity_id' => $this->entity->id, 'name' => 'Aflibercepte 40 mg/ml', 'unit' => 'un', 'active' => true, 'requires_lot' => true]);
    $lot     = StockLot::query()->create(['entity_id' => $this->entity->id, 'entity_product_id' => $product->id, 'lot_number' => 'ESTQ-9', 'expiry_date' => '2026-12-31']);

    $other      = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherProd  = EntityProduct::create(['entity_id' => $other->id, 'name' => 'Outro', 'unit' => 'un', 'active' => true, 'requires_lot' => true]);
    $foreignLot = StockLot::query()->create(['entity_id' => $other->id, 'entity_product_id' => $otherProd->id, 'lot_number' => 'FORA-1', 'expiry_date' => '2026-12-31']);

    $id = ivtRequest($this)->json('data.id');

    $res = ivtMarkDone($this, $id, ['stock_lot_id' => $foreignLot->id, 'next_interval_weeks' => 8])->assertStatus(422);
    expect($res->json('message'))->toBe(__('recall_course.errors.lot_not_found'));

    ivtMarkDone($this, $id, ['stock_lot_id' => $lot->id, 'next_interval_weeks' => 8])->assertOk();

    $done = IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Performed->value)->first();

    expect($done->lot_number)->toBe('ESTQ-9')
        ->and($done->lot_expiry->toDateString())->toBe('2026-12-31')
        ->and((string) $done->stock_lot_id)->toBe((string) $lot->id);
});

it('autorização do convênio é por aplicação: exigida e não aprovada só passa com aviso e justificativa gravados', function () {
    $id = ivtRequest($this)->json('data.id');

    $pending = ivtExecution(['authorization_required' => true, 'authorization_status' => 'pending']);

    $res = ivtMarkDone($this, $id, $pending)->assertStatus(422);
    expect($res->json('message'))->toBe(__('recall_course.errors.authorization_missing'));

    ivtMarkDone($this, $id, $pending + ['authorization_override' => true, 'authorization_notes' => 'Urgência: edema macular'])->assertOk();

    $done = IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Performed->value)->first();

    expect($done->authorization_required)->toBeTrue()
        ->and($done->authorization_status->value)->toBe('pending')
        ->and($done->authorization_notes)->toBe('Urgência: edema macular');
});

it('autorização aprovada e dentro da validade passa sem aviso, com número e validade gravados', function () {
    $id = ivtRequest($this)->json('data.id');

    ivtMarkDone($this, $id, ivtExecution([
        'authorization_required'    => true,
        'authorization_status'      => 'approved',
        'authorization_number'      => 'AUT-77',
        'authorization_valid_until' => '2026-04-30',
    ]))->assertOk();

    $done = IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Performed->value)->first();

    expect($done->authorization_number)->toBe('AUT-77')
        ->and($done->authorization_valid_until->toDateString())->toBe('2026-04-30');
});

it('autorização aprovada porém VENCIDA conta como não aprovada', function () {
    $id = ivtRequest($this)->json('data.id');

    $res = ivtMarkDone($this, $id, ivtExecution([
        'authorization_required'    => true,
        'authorization_status'      => 'approved',
        'authorization_valid_until' => '2026-03-09',
    ]))->assertStatus(422);

    expect($res->json('message'))->toBe(__('recall_course.errors.authorization_missing'));
});

it('o intervalo é decisão do médico: obrigatório e de 2 a 16 semanas', function () {
    $id = ivtRequest($this)->json('data.id');

    $missing = ivtMarkDone($this, $id, ['lot_number' => 'L1', 'lot_expiry' => '2027-01-31'])->assertStatus(422);
    expect($missing->json('message'))->toBe(__('recall_course.errors.interval_required'));

    foreach ([1, 17] as $weeks) {
        $res = ivtMarkDone($this, $id, ivtExecution(['next_interval_weeks' => $weeks]))->assertStatus(422);
        expect($res->json('message'))->toBe(__('recall_course.errors.interval_range'));
    }

    foreach ([2, 16] as $weeks) {
        $other = ivtRequest($this, ['eye' => $weeks === 2 ? 'left' : 'right'])->json('data.id');
        ivtMarkDone($this, $other, ivtExecution(['next_interval_weeks' => $weeks]))->assertOk();
    }
});

it('encerrar o curso na aplicação pede motivo, não planeja a próxima e o retorno do curso se encerra', function () {
    $id = ivtRequest($this)->json('data.id');

    ivtMarkDone($this, $id, ivtExecution())->assertOk();
    ivtEvaluate($this);

    $second = ivtRequest($this, ['intravitreal' => null])->json('data.id');

    $res = ivtMarkDone($this, $second, ivtExecution(['end_course' => 'completed']))->assertStatus(422);
    expect($res->json('message'))->toBe(__('recall_course.errors.end_reason_required'));

    $ok = ivtMarkDone($this, $second, ivtExecution(['end_course' => 'completed', 'end_reason' => 'Mácula seca por 3 visitas']))->assertOk();

    $course = ivtCourse($this)->refresh();

    expect($ok->json('intravitreal.closed'))->toBeTrue()
        ->and($course->status)->toBe(CourseStatus::Completed)
        ->and($course->next_due_on)->toBeNull()
        ->and($course->end_reason)->toBe('Mácula seca por 3 visitas')
        ->and(IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->where('status', ApplicationStatus::Planned->value)->count())->toBe(0);

    ivtEvaluate($this);

    $recall = PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->first();

    expect($recall->lifecycle->value)->toBe('closed')
        ->and($recall->closed_reason)->toBe('course_ended');
});

it('executar a mesma injeção duas vezes não duplica a aplicação (segunda recusa)', function () {
    $id = ivtRequest($this)->json('data.id');

    ivtMarkDone($this, $id, ivtExecution())->assertOk();
    ivtMarkDone($this, $id, ivtExecution())->assertStatus(422);

    expect(IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Performed->value)->count())->toBe(1);
});

it('cancelar o procedimento solta a aplicação planejada: o curso continua e o próximo pedido a reaproveita', function () {
    $id = ivtRequest($this)->json('data.id');

    ivtAsDoctor($this)->postJson(route('panel.patients.medicalrecord-procedures.cancel', [$this->patient->id, $id]), [])->assertOk();

    $application = IntravitrealApplication::query()->withoutGlobalScopes()->first();

    expect($application->status)->toBe(ApplicationStatus::Planned)
        ->and($application->medical_record_procedure_id)->toBeNull();

    $again = ivtRequest($this, ['intravitreal' => null])->assertCreated()->json('data.id');

    expect(IntravitrealApplication::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and((string) IntravitrealApplication::query()->withoutGlobalScopes()->first()->medical_record_procedure_id)->toBe($again);
});

// ── curso: painel, encerrar, ACL e clínica ─────────────────────────────────

it('o painel do paciente lista o curso com a próxima aplicação, a droga e o último lote', function () {
    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    $res = ivtAsDoctor($this)->getJson(route('panel.patients.intravitreal-courses.index', $this->patient))->assertOk();

    $course = $res->json('data.courses.0');

    expect($course['eye'])->toBe('right')
        ->and($course['drug'])->toBe('Aflibercepte')
        ->and($course['applications_count'])->toBe(1)
        ->and($course['interval_weeks'])->toBe(8)
        ->and($course['next_due_on'])->toBe('2026-05-05')
        ->and($course['days_to_next'])->toBe(56)
        ->and($course['last_applications'][0]['lot_number'])->toBe('LOT-123')
        ->and($res->json('data.weeks'))->toBe(['min' => 2, 'max' => 16])
        ->and($res->json('data.t.panel.title'))->not->toBeEmpty();
});

it('a secretária lê o painel, mas não encerra o curso (ato do médico)', function () {
    ivtRequest($this)->assertCreated();
    $course = ivtCourse($this);

    ivtAsSecretary($this)->getJson(route('panel.patients.intravitreal-courses.index', $this->patient))->assertOk();

    ivtAsSecretary($this)->postJson(route('panel.patients.intravitreal-courses.close', [$this->patient, $course->id]), ['status' => 'discontinued', 'reason' => 'Paciente mudou de cidade'])->assertForbidden();

    expect($course->refresh()->status)->toBe(CourseStatus::Active);
});

it('o médico encerra o curso com motivo e o retorno do curso se encerra na reavaliação', function () {
    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();
    ivtEvaluate($this);

    $course = ivtCourse($this);

    ivtAsDoctor($this)->postJson(route('panel.patients.intravitreal-courses.close', [$this->patient, $course->id]), ['status' => 'discontinued', 'reason' => 'Paciente mudou de cidade'])->assertOk();

    expect($course->refresh()->status)->toBe(CourseStatus::Discontinued)
        ->and($course->end_reason)->toBe('Paciente mudou de cidade');

    ivtEvaluate($this);

    expect(PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->first()->closed_reason)->toBe('course_ended');

    // Já encerrado: não encerra de novo.
    ivtAsDoctor($this)->postJson(route('panel.patients.intravitreal-courses.close', [$this->patient, $course->id]), ['status' => 'completed', 'reason' => 'Outra vez'])->assertStatus(422);
});

it('curso de outro paciente ou de outra clínica não existe (404), nada é encerrado', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    $foreign      = IntravitrealCourse::query()->withoutGlobalScopes()->create([
        'entity_id' => $otherEntity->id, 'patient_id' => $otherPatient->id, 'eye' => 'left', 'drug_key' => 'aflibercept', 'drug_label' => 'Aflibercepte',
        'regimen'   => 'prn', 'status' => 'active', 'started_on' => '2026-01-01', 'next_due_on' => '2026-04-01',
    ]);

    ivtAsDoctor($this)->postJson(route('panel.patients.intravitreal-courses.close', [$this->patient, $foreign->id]), ['status' => 'completed', 'reason' => 'Tentativa'])->assertNotFound();

    expect($foreign->refresh()->status)->toBe(CourseStatus::Active);
});

it('o painel não vaza curso de outra clínica nem de outro paciente', function () {
    $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id]);
    IntravitrealCourse::query()->withoutGlobalScopes()->create([
        'entity_id' => $otherEntity->id, 'patient_id' => $otherPatient->id, 'eye' => 'left', 'drug_key' => 'aflibercept', 'drug_label' => 'ISCA-OUTRA-CLINICA',
        'regimen'   => 'prn', 'status' => 'active', 'started_on' => '2026-01-01', 'next_due_on' => '2026-04-01',
    ]);

    $res = ivtAsDoctor($this)->getJson(route('panel.patients.intravitreal-courses.index', $this->patient))->assertOk();

    expect($res->json('data.courses'))->toBe([])
        ->and($res->getContent())->not->toContain('ISCA-OUTRA-CLINICA');

    ivtAsDoctor($this)->getJson(route('panel.patients.intravitreal-courses.index', $otherPatient))->assertNotFound();
});

// ── o retorno do curso (recall) ────────────────────────────────────────────

it('o retorno do curso aparece na lista de Retornos como "Próxima aplicação", com o estado do prazo curto', function () {
    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution(['next_interval_weeks' => 4]))->assertOk();
    ivtEvaluate($this);

    // 3 semanas depois (a 7 dias da data decidida) o retorno já está "a vencer" (convida 14 dias antes).
    $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00:00'));

    $recall = PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->first();

    expect($recall->due_at->toDateString())->toBe('2026-04-07')
        ->and($recall->state(CarbonImmutable::parse('2026-03-31'))->value)->toBe('due_soon')
        ->and($recall->state(CarbonImmutable::parse('2026-04-14'))->value)->toBe('due')
        ->and($recall->state(CarbonImmutable::parse('2026-04-15'))->value)->toBe('overdue')
        ->and($recall->state(CarbonImmutable::parse('2026-07-07'))->value)->toBe('lost');

    $row = collect(ivtRecallRows($this, 'ivtAsDoctor'))->firstWhere('source', 'course');

    expect($row)->not->toBeNull()
        ->and($row['task_label'])->toBe(__('recall.task.course'))
        ->and($row['eye'])->toBe('right');
});

it('a lista de Retornos mostra a droga só para quem vê condição clínica (médico); a recepção vê só "aplicação"', function () {
    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution(['next_interval_weeks' => 2]))->assertOk();
    ivtEvaluate($this);

    $this->travelTo(CarbonImmutable::parse('2026-03-26 10:00:00'));

    $doctor = collect(ivtRecallRows($this, 'ivtAsDoctor'))->firstWhere('source', 'course');
    $desk   = ivtRecallRows($this, 'ivtAsSecretary');

    expect($doctor['condition'])->toBe('Aflibercepte');

    $deskRow = collect($desk)->firstWhere('source', 'course');

    expect($deskRow)->not->toBeNull()
        ->and($deskRow['condition'])->toBeNull()
        ->and(json_encode($desk))->not->toContain('Aflibercepte');
});

it('curso pausado não gera retorno e a linha existente se encerra', function () {
    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();
    ivtEvaluate($this);

    ivtCourse($this)->forceFill(['status' => CourseStatus::Paused->value])->save();
    ivtEvaluate($this);

    expect(PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->first()->closed_reason)->toBe('course_ended');
});

it('a reavaliação é idempotente: avaliar de novo sem mudança não grava nada', function () {
    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    ivtEvaluate($this);
    $stamp = PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->value('updated_at');

    $this->travelTo(CarbonImmutable::parse('2026-03-10 11:00:00'));
    app(RecallEvaluator::class)->evaluatePatient((string) $this->entity->id, (string) $this->patient->id);

    expect(PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->count())->toBe(1)
        ->and(PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->value('updated_at'))->toEqual($stamp);
});

it('nova aplicação (ciclo novo) reabre o retorno e zera o adiamento da anterior', function () {
    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution(['next_interval_weeks' => 4]))->assertOk();
    ivtEvaluate($this);

    $recall = PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->first();
    $recall->forceFill(['lifecycle' => 'snoozed', 'snoozed_until' => '2026-04-01'])->save();

    $this->travelTo(CarbonImmutable::parse('2026-04-07 10:00:00'));

    $second = ivtRequest($this, ['intravitreal' => null])->json('data.id');
    ivtMarkDone($this, $second, ivtExecution(['next_interval_weeks' => 6]))->assertOk();
    ivtEvaluate($this);

    $recall->refresh();

    expect($recall->lifecycle->value)->toBe('open')
        ->and($recall->snoozed_until)->toBeNull()
        ->and($recall->last_done_at->toDateString())->toBe('2026-04-07')
        ->and($recall->due_at->toDateString())->toBe('2026-05-19')
        ->and(PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->count())->toBe(1);
});

// ── bordas das regras da aplicação ─────────────────────────────────────────

it('lote que vence HOJE ainda pode ser aplicado (vencido é só o de ontem)', function () {
    $id = ivtRequest($this)->json('data.id');

    ivtMarkDone($this, $id, ivtExecution(['lot_expiry' => '2026-03-10']))->assertOk();
});

it('o aviso de aplicar sem autorização aprovada exige justificativa de verdade (vazia ou curta não passa)', function () {
    $id = ivtRequest($this)->json('data.id');

    $pending = ivtExecution(['authorization_required' => true, 'authorization_status' => 'denied', 'authorization_override' => true]);

    ivtMarkDone($this, $id, $pending)->assertStatus(422);
    ivtMarkDone($this, $id, $pending + ['authorization_notes' => 'ok'])->assertStatus(422);
    ivtMarkDone($this, $id, $pending + ['authorization_notes' => 'Negada, mas é urgência'])->assertOk();
});

it('procedimento pedido antes do recurso: a execução abre o curso com a droga informada (ou recusa sem ela)', function () {
    $legacy = MedicalRecordProcedure::create([
        'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $this->record->id,
        'procedure_id' => $this->ivt->id, 'doctor_id' => $this->doctor->id, 'eye' => 'left',
    ]);

    $res = ivtMarkDone($this, $legacy->id, ivtExecution())->assertStatus(422);
    expect($res->json('message'))->toBe(__('recall_course.errors.drug_required'))
        ->and(IntravitrealCourse::query()->withoutGlobalScopes()->count())->toBe(0);

    ivtMarkDone($this, $legacy->id, ivtExecution(['drug_key' => 'bevacizumab']))->assertOk();

    $course = ivtCourse($this, 'left');

    expect($course->drug_label)->toBe('Bevacizumabe')
        ->and(IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->where('status', ApplicationStatus::Performed->value)->count())->toBe(1);
});

it('o olho do procedimento tem que ser o do curso da aplicação a que ele está ligado', function () {
    $left = IntravitrealCourse::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'eye' => 'left', 'drug_key' => 'aflibercept', 'drug_label' => 'Aflibercepte',
        'regimen'   => 'prn', 'status' => 'active', 'started_on' => '2026-03-01',
    ]);

    $mr = MedicalRecordProcedure::create([
        'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $this->record->id,
        'procedure_id' => $this->ivt->id, 'doctor_id' => $this->doctor->id, 'eye' => 'right',
    ]);

    IntravitrealApplication::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'course_id' => $left->id, 'sequence_no' => 1, 'phase' => 'loading',
        'status'    => 'planned', 'medical_record_procedure_id' => $mr->id, 'planned_for' => '2026-03-10', 'eye' => 'left', 'drug_label' => 'Aflibercepte',
    ]);

    $res = ivtMarkDone($this, $mr->id, ivtExecution())->assertStatus(422);

    expect($res->json('message'))->toBe(__('recall_course.errors.eye_mismatch'))
        ->and(MedicalRecordProcedure::query()->find($mr->id)->status)->toBe(MedicalRecordProcedureStatus::Requested);
});

it('a conciliação noturna enxerga o paciente com curso ativo mesmo sem nenhum outro motivo', function () {
    ivtRequest($this)->assertCreated();

    $ids = RecallCandidates::patients((string) $this->entity->id)->pluck('p.id')->all();

    expect($ids)->toContain((string) $this->patient->id);

    ivtCourse($this)->forceFill(['status' => CourseStatus::Completed->value, 'ended_on' => '2026-03-10'])->save();

    expect(RecallCandidates::patients((string) $this->entity->id)->pluck('p.id')->all())->not->toContain((string) $this->patient->id);
});

it('curso de OUTRO paciente da mesma clínica também é 404 (a rota é do paciente da URL)', function () {
    $other = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $mine  = IntravitrealCourse::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $other->id, 'eye' => 'left', 'drug_key' => 'aflibercept', 'drug_label' => 'Aflibercepte',
        'regimen'   => 'prn', 'status' => 'active', 'started_on' => '2026-01-01', 'next_due_on' => '2026-04-01',
    ]);

    ivtAsDoctor($this)->postJson(route('panel.patients.intravitreal-courses.close', [$this->patient, $mine->id]), ['status' => 'completed', 'reason' => 'Tentativa'])->assertNotFound();
    ivtAsDoctor($this)->postJson(route('panel.patients.intravitreal-courses.schedule-next', [$this->patient, $mine->id]), ['date_time' => '2026-04-01 09:00'])->assertNotFound();

    expect($mine->refresh()->status)->toBe(CourseStatus::Active);
});

// ── o que não pode ser confundido: curso fechado, outro paciente, "feito fora" ──

it('curso ENCERRADO do olho não é retomado: a nova solicitação abre um curso novo', function () {
    $first = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $first, ivtExecution(['end_course' => 'completed', 'end_reason' => 'Mácula seca']))->assertOk();

    $old = ivtCourse($this)->refresh();
    expect($old->status)->toBe(CourseStatus::Completed);

    ivtRequest($this, ['intravitreal' => ['drug_key' => 'ranibizumab']])->assertCreated();

    $courses = IntravitrealCourse::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->orderBy('created_at')->get();

    expect($courses)->toHaveCount(2)
        ->and($courses->last()->status)->toBe(CourseStatus::Active)
        ->and($courses->last()->drug_label)->toBe('Ranibizumabe')
        ->and($old->refresh()->status)->toBe(CourseStatus::Completed);
});

it('o curso aberto de OUTRO paciente da mesma clínica no mesmo olho não é o deste paciente', function () {
    $other  = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $theirs = IntravitrealCourse::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $other->id, 'eye' => 'right', 'drug_key' => 'bevacizumab', 'drug_label' => 'Bevacizumabe',
        'regimen'   => 'prn', 'status' => 'active', 'started_on' => '2026-01-01', 'next_due_on' => '2026-04-01',
    ]);

    ivtRequest($this)->assertCreated();

    $mine = ivtCourse($this);

    expect($mine->id)->not->toBe($theirs->id)
        ->and($mine->drug_label)->toBe('Aflibercepte')
        ->and($theirs->refresh()->applications()->count())->toBe(0);
});

it('aplicação ligada a curso já encerrado é recusada na execução (estado inconsistente não vira injeção registrada)', function () {
    $id = ivtRequest($this)->json('data.id');

    ivtCourse($this)->forceFill(['status' => CourseStatus::Discontinued->value, 'ended_on' => '2026-03-09'])->save();

    $res = ivtMarkDone($this, $id, ivtExecution())->assertStatus(422);

    expect($res->json('message'))->toBe(__('recall_course.errors.course_closed'))
        ->and(MedicalRecordProcedure::query()->find($id)->status)->toBe(MedicalRecordProcedureStatus::Requested);
});

it('"feito fora" não vale para a aplicação do curso: o médico decide o novo intervalo', function () {
    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution(['next_interval_weeks' => 4]))->assertOk();
    ivtEvaluate($this);

    $recall = PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->firstOrFail();

    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));

    expect(fn () => app(RecallActionService::class)->doneElsewhere((string) $this->entity->id, (string) $recall->id, CarbonImmutable::parse('2026-04-09')))
        ->toThrow(RecallActionException::class);

    expect($recall->refresh()->external_done_at)->toBeNull();
});

// ── agendar a próxima aplicação na série aberta do curso ───────────────────

/** Médico com expediente (seg–sáb, 08–12, de 15 em 15) e o atendimento de hoje na agenda, ligado ao prontuário. */
function ivtSchedulable($test): Schedule
{
    for ($d = 1; $d <= 6; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $test->doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }
    $test->doctor->update(['schedule_interval' => 15]);

    $attendance = Schedule::create([
        'entity_id' => $test->entity->id, 'doctor_id' => $test->doctor->id, 'patient_id' => $test->patient->id, 'full_name' => 'PACIENTE IVT',
        'date_time' => CarbonImmutable::parse('2026-03-10 09:00:00'), 'situation' => ScheduleSituation::Attended->value, 'active' => true,
    ]);

    $test->record->forceFill(['schedule_id' => $attendance->id])->saveQuietly();

    return $attendance;
}

it('agendar a próxima junto com a execução: a série aberta nasce do atendimento e a sessão entra na data pedida', function () {
    $attendance = ivtSchedulable($this);
    $id         = ivtRequest($this)->json('data.id');

    $res = ivtMarkDone($this, $id, ivtExecution(['schedule_at' => '2026-05-05 09:00']))->assertOk();

    $attendance->refresh();
    $course = ivtCourse($this)->refresh();
    $next   = IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->where('sequence_no', 2)->first();
    $new    = Schedule::query()->where('series_id', $attendance->series_id)->where('session_number', 2)->first();

    expect($res->json('intravitreal.schedule.ok'))->toBeTrue()
        ->and($attendance->series_id)->not->toBeNull()
        ->and($attendance->session_number)->toBe(1)
        ->and($attendance->session_total)->toBeNull()
        ->and($new->date_time->format('Y-m-d H:i'))->toBe('2026-05-05 09:00')
        ->and($new->session_total)->toBeNull()
        ->and((string) $next->schedule_id)->toBe((string) $new->id)
        ->and($next->planned_for->toDateString())->toBe('2026-05-05')
        ->and($course->series_id)->toBe($attendance->series_id);
});

it('a data agendada vira a data do retorno do curso (o que o médico marcou é o que vale)', function () {
    ivtSchedulable($this);
    $id = ivtRequest($this)->json('data.id');

    $res = ivtMarkDone($this, $id, ivtExecution(['schedule_at' => '2026-05-07 09:00']))->assertOk();
    ivtEvaluate($this);

    $recall = PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->first();

    expect($recall->due_at->toDateString())->toBe('2026-05-07')
        // O aviso da execução também fala da data MARCADA (o intervalo de 8 semanas daria 05/05).
        ->and($res->json('intravitreal.next_due_on'))->toBe('2026-05-07')
        ->and($res->json('intravitreal.message'))->toContain('07/05/2026');
});

it('horário que não cabe NÃO desfaz a aplicação: ela fica registrada, a próxima segue planejada sem agendamento e o motivo volta', function () {
    ivtSchedulable($this);
    $id = ivtRequest($this)->json('data.id');

    // Domingo: fora do expediente.
    $res = ivtMarkDone($this, $id, ivtExecution(['schedule_at' => '2026-05-03 09:00']))->assertOk();

    $course = ivtCourse($this)->refresh();
    $next   = IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->where('sequence_no', 2)->first();

    expect($res->json('data.status'))->toBe('done')
        ->and($res->json('intravitreal.schedule.ok'))->toBeFalse()
        ->and($res->json('intravitreal.schedule.message'))->toContain(__('actions.weekdays')[0] ?? 'domingo')
        ->and($next->schedule_id)->toBeNull()
        ->and($course->next_due_on->toDateString())->toBe('2026-05-05')
        ->and(IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Performed->value)->count())->toBe(1);
});

it('sem agendamento de referência (atendimento sem agenda) a execução vale e o agendamento volta o motivo', function () {
    $id = ivtRequest($this)->json('data.id');

    $res = ivtMarkDone($this, $id, ivtExecution(['schedule_at' => '2026-05-05 09:00']))->assertOk();

    expect($res->json('intravitreal.schedule.ok'))->toBeFalse()
        ->and($res->json('intravitreal.schedule.message'))->toContain(__('recall_course.errors.no_reference_schedule'));
});

it('agendar depois, pelo painel: agenda uma vez; a segunda chamada recusa (não duplica sessão)', function () {
    $attendance = ivtSchedulable($this);
    $id         = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    $course = ivtCourse($this);

    $url = route('panel.patients.intravitreal-courses.schedule-next', [$this->patient, $course->id]);

    ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-05 09:15'])->assertOk()->assertJsonStructure(['data' => ['schedule_id']]);
    ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-12 09:15'])->assertStatus(422)->assertJsonPath('message', __('recall_course.errors.already_scheduled'));

    expect(Schedule::query()->where('series_id', $attendance->refresh()->series_id)->count())->toBe(2);
});

it('reserva cancelada, faltada ou excluída deixa de contar: o painel volta a oferecer "agendar" e a próxima é aceita no lugar', function (string $dead) {
    ivtSchedulable($this);
    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    $course  = ivtCourse($this);
    $url     = route('panel.patients.intravitreal-courses.schedule-next', [$this->patient, $course->id]);
    $planned = fn () => ivtAsDoctor($this)->getJson(route('panel.patients.intravitreal-courses.index', $this->patient))->assertOk()->json('data.courses.0.planned');

    $first = ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-05 09:15'])->assertOk()->json('data.schedule_id');

    // Viva: a tela mostra agendada e a segunda tentativa é recusada.
    expect($planned()['schedule_id'])->toBe($first);
    ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-12 09:15'])->assertStatus(422)->assertJsonPath('message', __('recall_course.errors.already_scheduled'));

    // A reserva cai na Agenda (a aplicação planejada não é tocada).
    $booking = Schedule::query()->findOrFail($first);

    match ($dead) {
        'cancelled' => $booking->forceFill(['situation' => ScheduleSituation::Cancelled->value])->saveQuietly(),
        'no_show'   => $booking->forceFill(['situation' => ScheduleSituation::NoShow->value])->saveQuietly(),
        'deleted'   => $booking->delete(),
    };

    expect($planned()['schedule_id'])->toBeNull();

    $second = ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-12 09:15'])->assertOk()->json('data.schedule_id');
    $next   = IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->where('sequence_no', 2)->first();

    expect($second)->not->toBe($first)
        ->and((string) $next->schedule_id)->toBe($second)
        ->and($planned()['schedule_id'])->toBe($second);
})->with(['cancelada' => 'cancelled', 'faltou' => 'no_show', 'excluída' => 'deleted']);

it('agendar a próxima: ato do médico (secretária 403) e curso encerrado recusa', function () {
    ivtSchedulable($this);
    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    $course = ivtCourse($this);
    $url    = route('panel.patients.intravitreal-courses.schedule-next', [$this->patient, $course->id]);

    ivtAsSecretary($this)->postJson($url, ['date_time' => '2026-05-05 09:15'])->assertForbidden();

    ivtAsDoctor($this)->postJson(route('panel.patients.intravitreal-courses.close', [$this->patient, $course->id]), ['status' => 'completed', 'reason' => 'Mácula seca'])->assertOk();

    ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-05 09:15'])->assertStatus(422);
});

// ── o agendamento de referência é do paciente do curso ─────────────────────

it('atendimento de referência passado a OUTRO paciente pela Agenda não serve: nada é criado para o outro e a aplicação segue sem reserva', function () {
    $attendance = ivtSchedulable($this);
    $id         = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    $course = ivtCourse($this);
    $other  = Patient::factory()->create(['entity_id' => $this->entity->id]);

    // A recepção corrige o cadastro do atendimento: ele agora é de outra pessoa.
    $attendance->forceFill(['patient_id' => $other->id, 'full_name' => 'OUTRA PESSOA'])->saveQuietly();

    ivtAsDoctor($this)->postJson(route('panel.patients.intravitreal-courses.schedule-next', [$this->patient, $course->id]), ['date_time' => '2026-05-05 09:15'])
        ->assertStatus(422)
        ->assertJsonPath('message', __('recall_course.errors.no_reference_schedule'));

    $next = IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->where('sequence_no', 2)->firstOrFail();

    expect(Schedule::query()->where('patient_id', $other->id)->count())->toBe(1)
        ->and(Schedule::query()->where('patient_id', $this->patient->id)->count())->toBe(0)
        // A série não nasceu no agendamento da outra pessoa.
        ->and($attendance->refresh()->series_id)->toBeNull()
        ->and($next->schedule_id)->toBeNull();
});

it('com a série aberta, a referência é a última sessão DO PACIENTE: a sessão que a Agenda passou a outro paciente não é copiada', function () {
    $attendance = ivtSchedulable($this);
    $id         = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    $course = ivtCourse($this);
    $url    = route('panel.patients.intravitreal-courses.schedule-next', [$this->patient, $course->id]);

    $first   = ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-05 09:15'])->assertOk()->json('data.schedule_id');
    $booking = Schedule::query()->findOrFail($first);
    $other   = Patient::factory()->create(['entity_id' => $this->entity->id]);

    // A reserva (a ÚLTIMA sessão da série) cai e a recepção a reaproveita para outra pessoa.
    $booking->forceFill([
        'situation'  => ScheduleSituation::Cancelled->value,
        'patient_id' => $other->id,
        'full_name'  => 'OUTRA PESSOA',
        'cellphone'  => '11988887777',
    ])->saveQuietly();

    $second = ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-12 09:15'])->assertOk()->json('data.schedule_id');
    $new    = Schedule::query()->findOrFail($second);

    expect((string) $new->patient_id)->toBe((string) $this->patient->id)
        ->and($new->full_name)->toBe('PACIENTE IVT')
        ->and($new->cellphone)->not->toBe('11988887777')
        ->and((string) $new->series_id)->toBe((string) $attendance->refresh()->series_id)
        // O outro paciente continua com a sessão que já era dele, e só.
        ->and(Schedule::query()->where('patient_id', $other->id)->count())->toBe(1);
});

it('com a série aberta e TODAS as sessões passadas a outro paciente, não há referência', function () {
    $attendance = ivtSchedulable($this);
    $id         = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    $course = ivtCourse($this);
    $url    = route('panel.patients.intravitreal-courses.schedule-next', [$this->patient, $course->id]);

    $first = ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-05 09:15'])->assertOk()->json('data.schedule_id');
    $other = Patient::factory()->create(['entity_id' => $this->entity->id]);

    Schedule::query()->where('series_id', $attendance->refresh()->series_id)->get()->each->forceFill(['patient_id' => $other->id])->each->saveQuietly();
    Schedule::query()->findOrFail($first)->forceFill(['situation' => ScheduleSituation::Cancelled->value])->saveQuietly();

    ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-12 09:15'])
        ->assertStatus(422)
        ->assertJsonPath('message', __('recall_course.errors.no_reference_schedule'));

    expect(Schedule::query()->where('patient_id', $this->patient->id)->count())->toBe(0);
});

// ── ordem de locks do agendamento da próxima aplicação ─────────────────────

/**
 * As travas do agendar-próxima na ordem em que o banco as viu: `quota` (cota do encaixe), `recall` (paciente no
 * recall), `course`/`application` (linhas com FOR UPDATE) e `insert` (a sessão nova).
 *
 * @return list<string>
 */
function ivtLockTrace(callable $run): array
{
    $trace = [];

    DB::listen(function ($query) use (&$trace): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'pg_advisory_xact_lock') && ($query->bindings[0] ?? null) === 'easyeye.recall.patient') {
            $trace[] = 'recall';
        } elseif (str_contains($sql, 'pg_advisory_xact_lock(?)') && is_int($query->bindings[0] ?? null)) {
            $trace[] = 'advisory:' . $query->bindings[0];
        } elseif (str_contains($sql, 'from "intravitreal_courses"') && str_contains($sql, 'for update')) {
            $trace[] = 'course';
        } elseif (str_contains($sql, 'from "intravitreal_applications"') && str_contains($sql, 'for update')) {
            $trace[] = 'application';
        } elseif (str_starts_with($sql, 'insert into "schedules"')) {
            $trace[] = 'insert';
        }
    });

    $run();

    return $trace;
}

it('agendar a próxima toma a trava do paciente ANTES do curso e da aplicação (a execução trava paciente → aplicação → curso)', function () {
    ivtSchedulable($this);
    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    $course = ivtCourse($this);
    $url    = route('panel.patients.intravitreal-courses.schedule-next', [$this->patient, $course->id]);

    $trace = ivtLockTrace(fn () => ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-05 09:15'])->assertOk());

    $at = fn (string $step): int|false => array_search($step, $trace, true);

    expect($at('recall'))->not->toBeFalse()
        ->and($at('course'))->not->toBeFalse()
        ->and($at('application'))->not->toBeFalse()
        ->and($at('recall'))->toBeLessThan($at('course'))
        ->and($at('course'))->toBeLessThan($at('application'))
        ->and($at('application'))->toBeLessThan($at('insert'));
});

it('agendar a próxima a partir de um ENCAIXE toma a cota antes da trava do paciente (a mesma ordem de agendar um retorno)', function () {
    $attendance = ivtSchedulable($this);
    $attendance->forceFill(['is_fit_in' => true])->saveQuietly();

    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    $course = ivtCourse($this);
    $url    = route('panel.patients.intravitreal-courses.schedule-next', [$this->patient, $course->id]);
    $quota  = 'advisory:' . ScheduleService::fitInLockKey((string) $this->doctor->id, Carbon\Carbon::parse('2026-05-05 09:15'));

    $trace = ivtLockTrace(fn () => ivtAsDoctor($this)->postJson($url, ['date_time' => '2026-05-05 09:15'])->assertOk());

    $at = fn (string $step): int|false => array_search($step, $trace, true);

    expect($at($quota))->not->toBeFalse()
        ->and($at('recall'))->not->toBeFalse()
        ->and($at($quota))->toBeLessThan($at('recall'))
        ->and($at('recall'))->toBeLessThan($at('course'))
        ->and($at('course'))->toBeLessThan($at('application'));
});

// ── a agenda do médico, o paciente e a data da reserva, o aviso ao paciente ─

/** Um segundo médico da clínica (outra conta), com expediente seg–sáb, 08–12. */
function ivtOtherDoctor($test): Doctor
{
    $account = User::factory()->create();
    $eu      = createEntityUser($test->entity, $account, ClientRule::Doctor->value);
    $doctor  = Doctor::query()->create(['entity_user_id' => $eu->id, 'person_id' => People::factory()->create()->id, 'active' => true]);

    $doctor->update(['schedule_interval' => 15]);

    for ($d = 1; $d <= 6; $d++) {
        DoctorWorkSchedule::create(['doctor_id' => $doctor->id, 'day_of_week' => $d, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
    }

    $test->otherAccount = $account;
    $test->otherEu      = $eu;

    return $doctor;
}

function ivtAsOtherDoctor($test)
{
    return $test->actingAs($test->otherAccount)->withSession(panelSession($test->otherEu));
}

function ivtScheduleNextUrl($test): string
{
    return route('panel.patients.intravitreal-courses.schedule-next', [$test->patient, ivtCourse($test)->id]);
}

/** O curso do paciente como o painel o devolve (o 1º, o do olho direito). */
function ivtPanelCourse($test): array
{
    return ivtAsDoctor($test)->getJson(route('panel.patients.intravitreal-courses.index', $test->patient))->assertOk()->json('data.courses.0');
}

/** Pede a injeção, executa (próxima em 8 semanas = 2026-05-05) e agenda a próxima pelo painel; devolve o agendamento. */
function ivtBooked($test, string $dateTime = '2026-05-05 09:15'): Schedule
{
    $id = ivtRequest($test)->json('data.id');
    ivtMarkDone($test, $id, ivtExecution())->assertOk();

    $scheduleId = ivtAsDoctor($test)->postJson(ivtScheduleNextUrl($test), ['date_time' => $dateTime])->assertOk()->json('data.schedule_id');

    return Schedule::query()->findOrFail($scheduleId);
}

function ivtDueAt(): ?string
{
    return PatientRecall::query()->withoutGlobalScopes()->where('source', 'course')->first()?->due_at?->toDateString();
}

it('perfil médico não agenda a próxima a partir da agenda de OUTRO médico: nada é criado nem tocado e o paciente não é avisado', function () {
    Queue::fake();

    $attendance = ivtSchedulable($this);
    $other      = ivtOtherDoctor($this);
    $id         = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    // O atendimento de referência é da agenda do colega: a sessão copiaria o médico dele (e a série dele seria tocada).
    $attendance->forceFill(['doctor_id' => $other->id])->saveQuietly();

    $course = ivtCourse($this);

    ivtAsDoctor($this)->postJson(ivtScheduleNextUrl($this), ['date_time' => '2026-05-05 09:15'])
        ->assertStatus(422)
        ->assertJsonPath('message', __('recall_course.errors.other_doctor_calendar'));

    $next = IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->where('sequence_no', 2)->firstOrFail();

    expect(Schedule::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($attendance->refresh()->series_id)->toBeNull()
        ->and($attendance->session_number)->toBeNull()
        ->and($next->schedule_id)->toBeNull()
        ->and($course->refresh()->series_id)->toBeNull()
        ->and($course->next_due_on->toDateString())->toBe('2026-05-05');

    Queue::assertNothingPushed();
});

it('agendar junto com a execução a partir da agenda de OUTRO médico: a injeção vale, o agendamento volta o motivo e nada é criado', function () {
    Queue::fake();

    $attendance = ivtSchedulable($this);
    $other      = ivtOtherDoctor($this);
    $attendance->forceFill(['doctor_id' => $other->id])->saveQuietly();

    $id  = ivtRequest($this)->json('data.id');
    $res = ivtMarkDone($this, $id, ivtExecution(['schedule_at' => '2026-05-05 09:00']))->assertOk();

    expect($res->json('data.status'))->toBe('done')
        ->and($res->json('intravitreal.schedule.ok'))->toBeFalse()
        ->and($res->json('intravitreal.schedule.message'))->toContain(__('recall_course.errors.other_doctor_calendar'))
        ->and(Schedule::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($attendance->refresh()->series_id)->toBeNull()
        ->and(IntravitrealApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::Performed->value)->count())->toBe(1);

    Queue::assertNothingPushed();
});

it('o médico DONO da agenda de referência agenda a próxima do curso de outro colega: a sessão entra na agenda dele', function () {
    Queue::fake();

    $attendance = ivtSchedulable($this);
    $other      = ivtOtherDoctor($this);
    $id         = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    $attendance->forceFill(['doctor_id' => $other->id])->saveQuietly();

    $session = ivtAsOtherDoctor($this)->postJson(ivtScheduleNextUrl($this), ['date_time' => '2026-05-05 09:15'])->assertOk()->json('data.schedule_id');

    expect((string) Schedule::query()->findOrFail($session)->doctor_id)->toBe((string) $other->id)
        ->and((string) Schedule::query()->findOrFail($session)->series_id)->toBe((string) $attendance->refresh()->series_id);
});

it('agendar a próxima avisa o paciente da criação do agendamento (como o agendamento comum), só depois do commit', function () {
    Queue::fake();

    ivtSchedulable($this);
    $booking = ivtBooked($this);

    Queue::assertPushed(NotifyScheduleChangeJob::class, 1);
    Queue::assertPushed(NotifyScheduleChangeJob::class, fn (NotifyScheduleChangeJob $job): bool => $job->scheduleId === (string) $booking->id
        && $job->event === ScheduleNotification::EVENT_CREATED
        // Despachado de dentro da transação: sem isto o worker poderia rodar antes do commit e não achar a sessão.
        && $job->afterCommit === true);
});

it('agendar junto com a execução também avisa o paciente; horário que não coube, não', function () {
    Queue::fake();

    ivtSchedulable($this);
    $first = ivtRequest($this)->json('data.id');

    // Domingo: fora do expediente. A aplicação vale, o agendamento não sai e ninguém é avisado de nada.
    ivtMarkDone($this, $first, ivtExecution(['schedule_at' => '2026-05-03 09:00']))->assertOk()->assertJsonPath('intravitreal.schedule.ok', false);
    Queue::assertNothingPushed();

    // A próxima aplicação (a 2ª) agendada na execução, em horário que cabe.
    $second = ivtRequest($this)->json('data.id');
    $res    = ivtMarkDone($this, $second, ivtExecution(['schedule_at' => '2026-07-07 09:00']))->assertOk();

    expect($res->json('intravitreal.schedule.ok'))->toBeTrue();

    Queue::assertPushed(NotifyScheduleChangeJob::class, 1);
    Queue::assertPushed(NotifyScheduleChangeJob::class, fn (NotifyScheduleChangeJob $job): bool => $job->scheduleId === $res->json('intravitreal.schedule.schedule_id')
        && $job->event === ScheduleNotification::EVENT_CREATED);
});

it('a segunda tentativa ("já agendada") e o curso sem referência não avisam ninguém', function () {
    Queue::fake();

    ivtSchedulable($this);
    ivtBooked($this);

    ivtAsDoctor($this)->postJson(ivtScheduleNextUrl($this), ['date_time' => '2026-05-12 09:15'])->assertStatus(422)->assertJsonPath('message', __('recall_course.errors.already_scheduled'));

    // Só o primeiro agendamento avisou.
    Queue::assertPushed(NotifyScheduleChangeJob::class, 1);
});

it('reserva passada a OUTRO paciente pela Agenda (sem cancelar) não é a reserva deste paciente: o painel volta a oferecer "agendar" e a próxima é aceita', function () {
    ivtSchedulable($this);
    $first = ivtBooked($this);

    expect(ivtPanelCourse($this)['planned']['schedule_id'])->toBe((string) $first->id);

    $other = Patient::factory()->create(['entity_id' => $this->entity->id]);

    // A recepção corrige o cadastro: a sessão agora é de outra pessoa e CONTINUA marcada (não foi cancelada).
    $first->forceFill(['patient_id' => $other->id, 'full_name' => 'OUTRA PESSOA'])->saveQuietly();

    expect(ivtPanelCourse($this)['planned']['schedule_id'])->toBeNull();

    $second = ivtAsDoctor($this)->postJson(ivtScheduleNextUrl($this), ['date_time' => '2026-05-12 09:15'])->assertOk()->json('data.schedule_id');
    $new    = Schedule::query()->findOrFail($second);
    $next   = IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', ivtCourse($this)->id)->where('sequence_no', 2)->firstOrFail();

    expect($second)->not->toBe((string) $first->id)
        ->and((string) $new->patient_id)->toBe((string) $this->patient->id)
        ->and((string) $next->schedule_id)->toBe($second)
        ->and(ivtPanelCourse($this)['planned']['schedule_id'])->toBe($second)
        // A sessão que a Agenda passou à outra pessoa continua sendo só dela.
        ->and(Schedule::query()->where('patient_id', $other->id)->count())->toBe(1);
});

it('remarcar a reserva na Agenda move a data da próxima aplicação: no painel e no prazo do retorno, depois do dreno da outbox', function () {
    ivtSchedulable($this);
    $booking = ivtBooked($this);

    ivtEvaluate($this);

    expect(ivtDueAt())->toBe('2026-05-05')
        ->and(ivtPanelCourse($this)['next_due_on'])->toBe('2026-05-05');

    DB::table('recall_dirty_patients')->delete();

    // A recepção remarca na Agenda: só o agendamento muda (a aplicação e o curso guardam o dia em que foi reservada).
    $booking->forceFill(['date_time' => CarbonImmutable::parse('2026-06-02 09:15')])->save();

    // A marcação do paciente nasceu junto com a remarcação; o dreno reavalia como em produção (recall:evaluate-dirty).
    expect(DB::table('recall_dirty_patients')->where('patient_id', $this->patient->id)->exists())->toBeTrue();

    app(RecallDirtyDrainer::class)->drain(100);

    $panel = ivtPanelCourse($this);

    expect(ivtDueAt())->toBe('2026-06-02')
        ->and($panel['next_due_on'])->toBe('2026-06-02')
        ->and($panel['days_to_next'])->toBe(84)
        ->and($panel['planned']['planned_for'])->toBe('2026-06-02')
        ->and($panel['planned']['schedule_id'])->toBe((string) $booking->id);
});

it('reserva cancelada, faltada, excluída ou passada a outro paciente não decide o prazo: volta o que o curso tem gravado', function (string $dead) {
    ivtSchedulable($this);
    $booking = ivtBooked($this);

    // Remarcada na Agenda e depois "morta": o dia remarcado deixa de valer junto com a reserva.
    $booking->forceFill(['date_time' => CarbonImmutable::parse('2026-06-02 09:15')])->save();

    match ($dead) {
        'cancelled'     => $booking->forceFill(['situation' => ScheduleSituation::Cancelled->value])->saveQuietly(),
        'no_show'       => $booking->forceFill(['situation' => ScheduleSituation::NoShow->value])->saveQuietly(),
        'deleted'       => $booking->delete(),
        'other_patient' => $booking->forceFill(['patient_id' => Patient::factory()->create(['entity_id' => $this->entity->id])->id])->saveQuietly(),
    };

    ivtEvaluate($this);

    $panel = ivtPanelCourse($this);

    expect(ivtDueAt())->toBe('2026-05-05')
        ->and($panel['next_due_on'])->toBe('2026-05-05')
        ->and($panel['planned']['planned_for'])->toBe('2026-05-05')
        ->and($panel['planned']['schedule_id'])->toBeNull();
})->with(['cancelada' => 'cancelled', 'faltou' => 'no_show', 'excluída' => 'deleted', 'de outro paciente' => 'other_patient']);

it('encerrar o curso e agendar a próxima valem com o prontuário de origem ASSINADO: o curso não é do prontuário', function () {
    ivtSchedulable($this);

    $id = ivtRequest($this)->json('data.id');
    ivtMarkDone($this, $id, ivtExecution())->assertOk();

    // O atendimento é assinado depois da aplicação (imutável); o tratamento continua em andamento.
    $this->record->forceFill(['signed_at' => now(), 'signature_hash' => '1:' . str_repeat('a', 64), 'is_locked' => true])->saveQuietly();

    ivtAsDoctor($this)->postJson(ivtScheduleNextUrl($this), ['date_time' => '2026-05-05 09:15'])->assertOk();

    ivtAsDoctor($this)->postJson(route('panel.patients.intravitreal-courses.close', [$this->patient, ivtCourse($this)->id]), ['status' => 'completed', 'reason' => 'Mácula seca'])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');
});
