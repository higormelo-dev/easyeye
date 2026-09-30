<?php

/*
 * Repasse médico — atos de produção (DoctorPayoutProductionService::acts):
 * quais atos entram, de qual médico, com qual valor base (previsão), e as
 * regras "um ato = um item" entre agenda, prontuário e exames de equipamento.
 * A liberação pelo recebido fica em DoctorPayoutReleaseServiceTest.
 */

use App\DTOs\DoctorPayout\PayoutItemData;
use App\Enums\DoctorPayout\{DoctorPayoutBaseSource, DoctorPayoutServiceType, DoctorPayoutSourceType, DoctorPayoutWarning};
use App\Enums\ScheduleSituation;
use App\Models\{BillingClaim, Covenant, Doctor, DoctorPayout, DoctorPayoutItem, DoctorPayoutRule, Entity, ExamType, FinancialCashEntry, MedicalRecord, MedicalRecordProcedure, Patient, PatientExam, Procedure, ProcedurePrice, Schedule, VisitType};
use App\Services\Financial\DoctorPayouts\{DoctorPayoutProductionService, DoctorPayoutRuleResolver};
use Carbon\CarbonImmutable;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->entity      = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor      = createDoctorForEntity($this->entity);
    $this->otherDoctor = createDoctorForEntity($this->entity);

    $this->ansCovenant = Covenant::factory()->create([
        'entity_id' => $this->entity->id, 'active' => true, 'name' => 'OPERADORA TESTE', 'ans_registry' => '326305',
    ]);
    $this->privateCovenant = Covenant::factory()->create([
        'entity_id' => $this->entity->id, 'active' => true, 'name' => 'CARTAO DESCONTO', 'ans_registry' => null,
    ]);
    $this->patient = Patient::factory()->create([
        'entity_id' => $this->entity->id, 'covenant_id' => $this->ansCovenant->id,
    ]);

    $this->service = app(DoctorPayoutProductionService::class);
});

function payoutProdRun($test, ?Doctor $doctor = null, string $from = '2026-06-01', string $to = '2026-06-30'): Collection
{
    return $test->service->acts(
        $test->entity->id,
        ($doctor ?? $test->doctor)->id,
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to),
    );
}

function payoutProdSchedule($test, array $attrs = []): Schedule
{
    return Schedule::create(array_merge([
        'entity_id'   => $test->entity->id,
        'doctor_id'   => $test->doctor->id,
        'patient_id'  => $test->patient->id,
        'covenant_id' => $test->ansCovenant->id,
        'full_name'   => 'Paciente Repasse',
        'date_time'   => '2026-06-10 09:00:00',
        'situation'   => ScheduleSituation::Attended->value,
        'active'      => true,
    ], $attrs));
}

function payoutProdCash($test, Schedule $schedule, float $amount, string $status = 'paid'): FinancialCashEntry
{
    return FinancialCashEntry::query()->create([
        'entity_id'      => $test->entity->id,
        'entry_date'     => $schedule->date_time->toDateString(),
        'description'    => 'Recebimento',
        'type'           => 'income',
        'status'         => $status,
        'amount'         => $amount,
        'reference_type' => 'schedule',
        'reference_id'   => $schedule->id,
        'doctor_id'      => $schedule->doctor_id,
        'active'         => true,
    ]);
}

function payoutProdClaim($test, Schedule $schedule, array $attrs = []): BillingClaim
{
    return BillingClaim::create(array_merge([
        'entity_id'       => $test->entity->id,
        'schedule_id'     => $schedule->id,
        'patient_id'      => $schedule->patient_id,
        'doctor_id'       => $schedule->doctor_id,
        'covenant_id'     => $schedule->covenant_id ?? $test->ansCovenant->id,
        'status'          => 'submitted',
        'attendance_date' => $schedule->date_time->toDateString(),
        'amount'          => 200,
        'quantity'        => 1,
        'unit_price'      => 200,
    ], $attrs));
}

function payoutProdProcedure(int $treatment, string $name): Procedure
{
    return Procedure::factory()->create(['treatment' => $treatment, 'name' => $name]);
}

function payoutProdVisitType($test, ?Procedure $procedure, string $name): VisitType
{
    return VisitType::create([
        'entity_id' => $test->entity->id, 'name' => $name, 'procedure_id' => $procedure?->id, 'active' => true,
    ]);
}

function payoutProdPrice($test, Procedure $procedure, Covenant $covenant, float $price, bool $global = false): ProcedurePrice
{
    return ProcedurePrice::factory()->create([
        'entity_id'    => $global ? null : $test->entity->id,
        'covenant_id'  => $covenant->id,
        'procedure_id' => $procedure->id,
        'price'        => $price,
        'active'       => true,
    ]);
}

function payoutProdRecord($test, ?Schedule $schedule, ?Doctor $doctor = null): MedicalRecord
{
    return MedicalRecord::create([
        'entity_id'   => $test->entity->id,
        'patient_id'  => $test->patient->id,
        'doctor_id'   => ($doctor ?? $test->doctor)->id,
        'schedule_id' => $schedule?->id,
    ]);
}

function payoutProdExecuted($test, MedicalRecord $record, Procedure $procedure, array $attrs = []): MedicalRecordProcedure
{
    return MedicalRecordProcedure::create(array_merge([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $record->patient_id,
        'medical_record_id' => $record->id,
        'procedure_id'      => $procedure->id,
        'doctor_id'         => $record->doctor_id,
        'status'            => 'done',
        'executed_at'       => '2026-06-10 10:00:00',
        'executed_by'       => $test->doctor->entity_user_id,
    ], $attrs));
}

function payoutProdExam($test, ExamType $type, array $attrs = []): PatientExam
{
    return PatientExam::factory()->create(array_merge([
        'patient_id'        => $test->patient->id,
        'exam_id'           => $type->id,
        'doctor_id'         => $test->doctor->id,
        'exam_performed_at' => '2026-06-10 11:00:00',
        'source'            => 'integrator',
        'active'            => true,
    ], $attrs));
}

/** @param Collection<int, PayoutItemData> $items */
function payoutProdBySource(Collection $items, DoctorPayoutSourceType $type, string $id): ?PayoutItemData
{
    return $items->first(fn (PayoutItemData $i) => $i->sourceType === $type && $i->sourceId === $id);
}

describe('consultas (agenda)', function () {
    it('inclui só atendidos do médico, da clínica e do período (limites inclusivos)', function () {
        $inside = payoutProdSchedule($this);
        $first  = payoutProdSchedule($this, ['date_time' => '2026-06-01 00:00:00']);
        $last   = payoutProdSchedule($this, ['date_time' => '2026-06-30 23:59:59']);
        payoutProdSchedule($this, ['date_time' => '2026-07-01 00:00:00']);
        payoutProdSchedule($this, ['date_time' => '2026-06-11 09:00:00', 'situation' => ScheduleSituation::Scheduled->value]);
        payoutProdSchedule($this, ['date_time' => '2026-06-12 09:00:00', 'situation' => ScheduleSituation::InProgress->value]);
        payoutProdSchedule($this, ['date_time' => '2026-06-13 09:00:00'])->delete();
        payoutProdSchedule($this, ['date_time' => '2026-06-14 09:00:00', 'doctor_id' => $this->otherDoctor->id]);

        $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
        Schedule::create([
            'entity_id' => $otherEntity->id, 'doctor_id' => $this->doctor->id, 'full_name' => 'Outra clínica',
            'date_time' => '2026-06-15 09:00:00', 'situation' => ScheduleSituation::Attended->value, 'active' => true,
        ]);

        $ids = payoutProdRun($this)->pluck('sourceId')->all();

        expect($ids)->toBe([$first->id, $inside->id, $last->id]);
    });

    it('base = caixa não cancelado + guia ativa (paga → recebido; demais → valor − glosa)', function () {
        $cash = payoutProdSchedule($this, ['date_time' => '2026-06-02 09:00:00']);
        payoutProdCash($this, $cash, 150.00);
        payoutProdCash($this, $cash, 50.00, 'cancelled');

        $submitted = payoutProdSchedule($this, ['date_time' => '2026-06-03 09:00:00']);
        payoutProdClaim($this, $submitted, ['amount' => 200, 'glosa_amount' => 30]);

        $paid = payoutProdSchedule($this, ['date_time' => '2026-06-04 09:00:00']);
        payoutProdClaim($this, $paid, ['status' => 'paid', 'amount' => 200, 'paid_amount' => 180, 'paid_at' => '2026-06-20 10:00:00']);

        $copay = payoutProdSchedule($this, ['date_time' => '2026-06-05 09:00:00']);
        payoutProdCash($this, $copay, 40.00);
        payoutProdClaim($this, $copay, ['amount' => 160]);

        $items = payoutProdRun($this);

        foreach ([[$cash, 15000], [$submitted, 17000], [$paid, 18000], [$copay, 20000]] as [$schedule, $cents]) {
            $item = payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $schedule->id);

            expect($item->baseCents)->toBe($cents)
                ->and($item->baseSource)->toBe(DoctorPayoutBaseSource::Charged);
        }
    });

    it('cobrança toda cancelada/negada → base 0 com alerta; sem cobrança → tabela; sem preço → nenhum valor', function () {
        $consult = payoutProdProcedure(2, 'CONSULTA TESTE');
        $type    = payoutProdVisitType($this, $consult, 'CONSULTA');
        payoutProdPrice($this, $consult, $this->ansCovenant, 250.00);

        $cancelled = payoutProdSchedule($this, ['date_time' => '2026-06-02 09:00:00', 'visit_id' => $type->id]);
        payoutProdCash($this, $cancelled, 250.00, 'cancelled');

        $denied = payoutProdSchedule($this, ['date_time' => '2026-06-03 09:00:00', 'visit_id' => $type->id]);
        payoutProdClaim($this, $denied, ['status' => 'denied', 'glosa_amount' => 200]);

        $table   = payoutProdSchedule($this, ['date_time' => '2026-06-04 09:00:00', 'visit_id' => $type->id]);
        $noPrice = payoutProdSchedule($this, ['date_time' => '2026-06-05 09:00:00']);

        $items = payoutProdRun($this);

        foreach ([$cancelled, $denied] as $schedule) {
            $item = payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $schedule->id);
            expect($item->baseCents)->toBe(0)
                ->and($item->baseSource)->toBe(DoctorPayoutBaseSource::None)
                ->and($item->hasWarning(DoctorPayoutWarning::ChargeCancelled))->toBeTrue();
        }

        expect(payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $table->id))
            ->baseCents->toBe(25000)
            ->baseSource->toBe(DoctorPayoutBaseSource::Table);

        expect(payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $noPrice->id))
            ->baseCents->toBe(0)
            ->baseSource->toBe(DoctorPayoutBaseSource::None);
    });

    it('preço da clínica vence o global; sem convênio usa a tabela do PARTICULAR global', function () {
        $consult    = payoutProdProcedure(2, 'CONSULTA TESTE');
        $type       = payoutProdVisitType($this, $consult, 'CONSULTA');
        $particular = Covenant::query()->whereNull('entity_id')->whereRaw('upper(name) = ?', ['PARTICULAR'])->firstOrFail();

        payoutProdPrice($this, $consult, $this->ansCovenant, 100.00, global: true);
        payoutProdPrice($this, $consult, $this->ansCovenant, 120.00);
        payoutProdPrice($this, $consult, $particular, 300.00, global: true);

        $withCovenant = payoutProdSchedule($this, ['date_time' => '2026-06-02 09:00:00', 'visit_id' => $type->id]);
        $noCovenant   = payoutProdSchedule($this, ['date_time' => '2026-06-03 09:00:00', 'visit_id' => $type->id, 'covenant_id' => null]);

        $items = payoutProdRun($this);

        expect(payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $withCovenant->id)->baseCents)->toBe(12000);

        $particularItem = payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $noCovenant->id);
        expect($particularItem->baseCents)->toBe(30000)
            ->and($particularItem->isParticular)->toBeTrue()
            ->and($particularItem->covenantName)->toBeNull();
    });

    it('particular = convênio sem registro ANS; convênio com ANS não é particular', function () {
        $private = payoutProdSchedule($this, ['date_time' => '2026-06-02 09:00:00', 'covenant_id' => $this->privateCovenant->id]);
        $ans     = payoutProdSchedule($this, ['date_time' => '2026-06-03 09:00:00']);

        $items = payoutProdRun($this);

        expect(payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $private->id))
            ->isParticular->toBeTrue()
            ->covenantName->toBe('CARTAO DESCONTO');
        expect(payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $ans->id))
            ->isParticular->toBeFalse()
            ->covenantName->toBe('OPERADORA TESTE');
    });

    it('classifica pelo procedimento do tipo de atendimento (3 exame, 4 procedimento, resto consulta)', function () {
        $examType  = payoutProdVisitType($this, payoutProdProcedure(3, 'MAPEAMENTO DE RETINA'), 'MAPEAMENTO');
        $procType  = payoutProdVisitType($this, payoutProdProcedure(4, 'CAPSULOTOMIA YAG'), 'YAG');
        $plainType = payoutProdVisitType($this, null, 'RETORNO');

        $exam  = payoutProdSchedule($this, ['date_time' => '2026-06-02 09:00:00', 'visit_id' => $examType->id]);
        $proc  = payoutProdSchedule($this, ['date_time' => '2026-06-03 09:00:00', 'visit_id' => $procType->id]);
        $plain = payoutProdSchedule($this, ['date_time' => '2026-06-04 09:00:00', 'visit_id' => $plainType->id]);

        $items = payoutProdRun($this);

        expect(payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $exam->id))
            ->serviceType->toBe(DoctorPayoutServiceType::Exam)
            ->description->toBe('MAPEAMENTO DE RETINA');
        expect(payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $proc->id)->serviceType)
            ->toBe(DoctorPayoutServiceType::Procedure);
        expect(payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $plain->id))
            ->serviceType->toBe(DoctorPayoutServiceType::Consultation)
            ->description->toBe('RETORNO');
    });

    it('alerta quando o prontuário do atendimento foi registrado por outro médico', function () {
        $schedule = payoutProdSchedule($this);
        payoutProdRecord($this, $schedule, $this->otherDoctor);

        $item = payoutProdBySource(payoutProdRun($this), DoctorPayoutSourceType::Schedule, $schedule->id);

        expect($item->hasWarning(DoctorPayoutWarning::DoctorMismatch))->toBeTrue()
            ->and(payoutProdRun($this, $this->otherDoctor))->toBeEmpty();
    });
});

describe('procedimentos (prontuário)', function () {
    it('agendamento de cirurgia atendido com o procedimento só CANCELADO no prontuário continua contando, com alerta', function () {
        $facectomia = payoutProdProcedure(4, 'FACECTOMIA');
        $type       = payoutProdVisitType($this, $facectomia, 'CIRURGIA CATARATA');
        $schedule   = payoutProdSchedule($this, ['visit_id' => $type->id]);
        $record     = payoutProdRecord($this, $schedule);
        payoutProdExecuted($this, $record, $facectomia, ['status' => 'cancelled', 'executed_at' => null]);

        $item = payoutProdBySource(payoutProdRun($this), DoctorPayoutSourceType::Schedule, $schedule->id);

        expect($item)->not->toBeNull()
            ->and($item->serviceType)->toBe(DoctorPayoutServiceType::Procedure)
            ->and($item->warnings)->toContain(DoctorPayoutWarning::ProcedureCancelled);

        // Um olho executado e o outro cancelado (agendamento mantido por parcela já
        // liberada): não é "nenhum executado" — sem o alerta.
        payoutProdExecuted($this, $record, $facectomia, ['eye' => 'OD']);
        DB::table('doctor_payout_items')->insert([
            'id' => (string) Str::uuid(), 'entity_id' => $this->entity->id, 'doctor_payout_id' => DoctorPayout::query()->create([
                'entity_id'  => $this->entity->id, 'doctor_id' => $this->doctor->id, 'doctor_name' => 'Dr.', 'period_start' => '2026-06-01',
                'period_end' => '2026-06-30', 'status' => 'closed', 'closed_at' => now(), 'basis' => 'receipt',
            ])->id,
            'source_type' => 'schedule', 'source_id' => $schedule->id, 'service_type' => 'procedure', 'performed_at' => '2026-06-10 09:00:00',
            'description' => 'FACECTOMIA', 'base_source' => 'received', 'basis' => 'receipt', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mixed = payoutProdBySource(payoutProdRun($this), DoctorPayoutSourceType::Schedule, $schedule->id);
        expect($mixed?->warnings ?? [])->not->toContain(DoctorPayoutWarning::ProcedureCancelled);
        MedicalRecordProcedure::query()->where('status', 'done')->delete();
        DB::table('doctor_payout_items')->delete();

        // Só "solicitado" (quem não registra a execução): fallback da agenda, sem alerta.
        MedicalRecordProcedure::query()->update(['status' => 'requested']);
        $again = payoutProdBySource(payoutProdRun($this), DoctorPayoutSourceType::Schedule, $schedule->id);

        expect($again->warnings)->not->toContain(DoctorPayoutWarning::ProcedureCancelled);
    });

    it('inclui executados com tratamento 4 para quem executou; ignora solicitados, cancelados, tratamentos 2/3 e prontuário excluído', function () {
        $yag      = payoutProdProcedure(4, 'CAPSULOTOMIA YAG');
        $record   = payoutProdRecord($this, null, $this->otherDoctor);
        $executed = payoutProdExecuted($this, $record, $yag);

        payoutProdExecuted($this, $record, $yag, ['status' => 'requested', 'executed_at' => null, 'executed_by' => null]);
        payoutProdExecuted($this, $record, $yag, ['status' => 'cancelled']);
        payoutProdExecuted($this, $record, payoutProdProcedure(3, 'TONOMETRIA'));
        payoutProdExecuted($this, $record, payoutProdProcedure(2, 'PARECER MEDICO'));

        $deletedRecord = payoutProdRecord($this, null);
        payoutProdExecuted($this, $deletedRecord, $yag);
        $deletedRecord->delete();

        $items = payoutProdRun($this);

        expect($items->pluck('sourceId')->all())->toBe([$executed->id])
            ->and($items->first()->serviceType)->toBe(DoctorPayoutServiceType::Procedure)
            ->and(payoutProdRun($this, $this->otherDoctor))->toBeEmpty();
    });

    it('agendamento de procedimento + execução do mesmo procedimento = um item só, com a base do agendamento', function () {
        $yag      = payoutProdProcedure(4, 'CAPSULOTOMIA YAG');
        $type     = payoutProdVisitType($this, $yag, 'YAG');
        $schedule = payoutProdSchedule($this, ['visit_id' => $type->id]);
        payoutProdCash($this, $schedule, 800.00);
        payoutProdPrice($this, $yag, $this->ansCovenant, 300.00);

        $executed = payoutProdExecuted($this, payoutProdRecord($this, $schedule), $yag);

        $items = payoutProdRun($this);

        expect($items)->toHaveCount(1)
            ->and($items->first()->sourceType)->toBe(DoctorPayoutSourceType::MedicalRecordProcedure)
            ->and($items->first()->sourceId)->toBe($executed->id)
            ->and($items->first()->baseCents)->toBe(80000)
            ->and($items->first()->baseSource)->toBe(DoctorPayoutBaseSource::Charged);
    });

    it('procedimento não pareado usa a tabela; o valor da agenda fica na consulta, com alerta de cobrança compartilhada', function () {
        $yag      = payoutProdProcedure(4, 'CAPSULOTOMIA YAG');
        $schedule = payoutProdSchedule($this);
        payoutProdCash($this, $schedule, 500.00);
        payoutProdPrice($this, $yag, $this->ansCovenant, 300.00);

        $executed = payoutProdExecuted($this, payoutProdRecord($this, $schedule), $yag);

        $items = payoutProdRun($this);

        $consult = payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $schedule->id);
        expect($consult->baseCents)->toBe(50000)
            ->and($consult->hasWarning(DoctorPayoutWarning::SharedCharge))->toBeTrue();

        expect(payoutProdBySource($items, DoctorPayoutSourceType::MedicalRecordProcedure, $executed->id))
            ->baseCents->toBe(30000)
            ->baseSource->toBe(DoctorPayoutBaseSource::Table);
    });

    it('procedimentos iguais pareados com o mesmo agendamento (OD e OE) dividem o valor cobrado — a soma fecha com a cobrança', function () {
        $injection = payoutProdProcedure(4, 'INJECAO INTRAVITREA');
        $type      = payoutProdVisitType($this, $injection, 'INJECAO');
        $schedule  = payoutProdSchedule($this, ['visit_id' => $type->id]);
        payoutProdCash($this, $schedule, 1000.01);
        payoutProdPrice($this, $injection, $this->ansCovenant, 700.00);

        $record = payoutProdRecord($this, $schedule);
        $od     = payoutProdExecuted($this, $record, $injection, ['eye' => 'OD', 'executed_at' => '2026-06-10 10:00:00']);
        $oe     = payoutProdExecuted($this, $record, $injection, ['eye' => 'OE', 'executed_at' => '2026-06-10 10:05:00']);

        $items  = payoutProdRun($this);
        $first  = payoutProdBySource($items, DoctorPayoutSourceType::MedicalRecordProcedure, $od->id);
        $second = payoutProdBySource($items, DoctorPayoutSourceType::MedicalRecordProcedure, $oe->id);

        // Antes: cada um levava R$ 1.000,01 (base dobrada). Agora o centavo de
        // resto vai para o executado primeiro.
        expect($items)->toHaveCount(2)
            ->and($first->baseCents)->toBe(50001)
            ->and($second->baseCents)->toBe(50000)
            ->and($first->hasWarning(DoctorPayoutWarning::SplitCharge))->toBeTrue()
            ->and($second->hasWarning(DoctorPayoutWarning::SplitCharge))->toBeTrue();
    });

    it('pareados sem cobrança usam cada um o preço de tabela (sem dividir, sem alerta)', function () {
        $injection = payoutProdProcedure(4, 'INJECAO INTRAVITREA');
        $schedule  = payoutProdSchedule($this, ['visit_id' => payoutProdVisitType($this, $injection, 'INJECAO')->id]);
        payoutProdPrice($this, $injection, $this->ansCovenant, 700.00);

        $record = payoutProdRecord($this, $schedule);
        payoutProdExecuted($this, $record, $injection, ['eye' => 'OD']);
        payoutProdExecuted($this, $record, $injection, ['eye' => 'OE', 'executed_at' => '2026-06-10 10:05:00']);

        $items = payoutProdRun($this);

        expect($items->pluck('baseCents')->all())->toBe([70000, 70000])
            ->and($items->every(fn (PayoutItemData $item) => $item->baseSource === DoctorPayoutBaseSource::Table && $item->warnings === []))->toBeTrue();
    });
});

describe('exames de equipamento', function () {
    it('agrupa imagens por paciente + tipo + dia e ignora importação externa, inativos e outra clínica', function () {
        $oct = ExamType::factory()->create(['name' => 'OCT']);

        payoutProdExam($this, $oct, ['exam_performed_at' => '2026-06-10 11:00:00']);
        payoutProdExam($this, $oct, ['exam_performed_at' => '2026-06-10 11:05:00']);
        payoutProdExam($this, $oct, ['exam_performed_at' => '2026-06-10 11:10:00']);
        payoutProdExam($this, $oct, ['exam_performed_at' => '2026-06-11 09:00:00']);
        payoutProdExam($this, $oct, ['exam_performed_at' => '2026-06-12 09:00:00', 'source' => 'external_import']);
        payoutProdExam($this, $oct, ['exam_performed_at' => '2026-06-13 09:00:00', 'active' => false]);

        $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id, 'covenant_id' => $this->ansCovenant->id]);
        payoutProdExam($this, $oct, ['patient_id' => $otherPatient->id, 'exam_performed_at' => '2026-06-14 09:00:00']);

        $items = payoutProdRun($this);

        expect($items)->toHaveCount(2)
            ->and($items->pluck('sourceId')->all())->toBe([
                DoctorPayoutProductionService::examKey($this->patient->id, $oct->id, '2026-06-10'),
                DoctorPayoutProductionService::examKey($this->patient->id, $oct->id, '2026-06-11'),
            ])
            ->and($items->first()->serviceType)->toBe(DoctorPayoutServiceType::Exam)
            ->and($items->first()->examTypeId)->toBe($oct->id)
            ->and($items->first()->baseSource)->toBe(DoctorPayoutBaseSource::None);
    });

    it('exame vinculado a agendamento de exame não conta de novo', function () {
        $oct      = ExamType::factory()->create(['name' => 'OCT']);
        $type     = payoutProdVisitType($this, payoutProdProcedure(3, 'RETINOGRAFIA'), 'EXAME');
        $schedule = payoutProdSchedule($this, ['visit_id' => $type->id]);
        payoutProdExam($this, $oct, ['schedule_id' => $schedule->id]);

        $items = payoutProdRun($this);

        expect($items)->toHaveCount(1)
            ->and($items->first()->sourceType)->toBe(DoctorPayoutSourceType::Schedule);
    });

    it('agendamento de exame com UM tipo capturado leva o tipo e o nome do exame: a regra "OCT → R$ X" vale e a por tipo de atendimento continua vencendo', function () {
        $oct      = ExamType::factory()->create(['name' => 'OCT de retina']);
        $type     = payoutProdVisitType($this, payoutProdProcedure(3, 'RETINOGRAFIA'), 'EXAMES');
        $schedule = payoutProdSchedule($this, ['visit_id' => $type->id]);
        payoutProdExam($this, $oct, ['schedule_id' => $schedule->id]);
        payoutProdExam($this, $oct, ['schedule_id' => $schedule->id, 'exam_performed_at' => '2026-06-10 11:05:00']); // 2ª imagem, mesmo tipo
        // Importado de fora e desabilitado não contam como tipo do agendamento.
        payoutProdExam($this, ExamType::factory()->create(['name' => 'CAMPIMETRIA']), ['schedule_id' => $schedule->id, 'source' => 'external_import']);
        payoutProdExam($this, ExamType::factory()->create(['name' => 'TOPOGRAFIA']), ['schedule_id' => $schedule->id, 'active' => false]);

        $item = payoutProdRun($this)->sole();

        expect($item->sourceType)->toBe(DoctorPayoutSourceType::Schedule)
            ->and($item->serviceType)->toBe(DoctorPayoutServiceType::Exam)
            ->and($item->examTypeId)->toBe($oct->id)
            ->and($item->description)->toBe($oct->name)
            ->and($item->warnings)->not->toContain(DoctorPayoutWarning::MultipleExamTypes);

        $rule    = fn (array $attrs) => tap(new DoctorPayoutRule(['service_type' => 'exam', 'payer_scope' => 'any', 'calculation' => 'fixed', 'active' => true] + $attrs), fn ($r) => $r->id = (string) Str::uuid());
        $general = $rule(['fixed_amount' => '20.00']);
        $byOct   = $rule(['exam_type_id' => $oct->id, 'fixed_amount' => '40.00']);
        $byVisit = $rule(['visit_type_id' => $type->id, 'fixed_amount' => '55.00']);

        $resolver = new DoctorPayoutRuleResolver();

        expect($resolver->resolveItem(collect([$general, $byOct]), $item)->ruleFixedCents)->toBe(4000)
            ->and($resolver->resolveItem(collect([$general, $byOct, $byVisit]), $item)->ruleFixedCents)->toBe(5500);
    });

    it('agendamento de exame com mais de um tipo capturado: um atendimento só, sem tipo, com alerta', function () {
        $type     = payoutProdVisitType($this, payoutProdProcedure(3, 'RETINOGRAFIA'), 'EXAMES');
        $schedule = payoutProdSchedule($this, ['visit_id' => $type->id]);
        payoutProdExam($this, ExamType::factory()->create(['name' => 'OCT']), ['schedule_id' => $schedule->id]);
        payoutProdExam($this, ExamType::factory()->create(['name' => 'CAMPIMETRIA']), ['schedule_id' => $schedule->id]);

        $item = payoutProdRun($this)->sole();

        expect($item->examTypeId)->toBeNull()
            ->and($item->description)->toBe('RETINOGRAFIA')
            ->and($item->warnings)->toContain(DoctorPayoutWarning::MultipleExamTypes);
    });

    it('exames sem médico no período são contados (avisados na apuração), só os da clínica, ativos e do integrador', function () {
        $oct = ExamType::factory()->create(['name' => 'OCT']);
        payoutProdExam($this, $oct, ['doctor_id' => null, 'exam_performed_at' => '2026-06-10 11:00:00']);
        payoutProdExam($this, $oct, ['doctor_id' => null, 'exam_performed_at' => '2026-06-10 11:05:00']); // mesmo ato
        payoutProdExam($this, $oct, ['doctor_id' => null, 'exam_performed_at' => '2026-06-11 09:00:00']);
        payoutProdExam($this, $oct, ['doctor_id' => null, 'exam_performed_at' => '2026-06-12 09:00:00', 'active' => false]);
        payoutProdExam($this, $oct, ['doctor_id' => null, 'exam_performed_at' => '2026-06-12 10:00:00', 'source' => 'external_import']);
        payoutProdExam($this, $oct, ['doctor_id' => null, 'exam_performed_at' => '2026-07-01 09:00:00']); // fora do período

        $otherEntity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $otherPatient = Patient::factory()->create(['entity_id' => $otherEntity->id, 'covenant_id' => $this->ansCovenant->id]);
        payoutProdExam($this, $oct, ['patient_id' => $otherPatient->id, 'doctor_id' => null]);

        expect($this->service->unassignedExamCount($this->entity->id, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30')))->toBe(2);
    });
});

describe('fechamentos existentes', function () {
    function payoutProdCloseSource($test, DoctorPayoutSourceType $type, string $sourceId, string $status = 'closed'): DoctorPayoutItem
    {
        $payout = DoctorPayout::query()->create([
            'entity_id'    => $test->entity->id,
            'doctor_id'    => $test->doctor->id,
            'doctor_name'  => 'Dr. Teste',
            'period_start' => '2026-06-01',
            'period_end'   => '2026-06-15',
            'status'       => $status,
            'closed_at'    => now(),
        ]);

        return DoctorPayoutItem::query()->create([
            'entity_id'        => $test->entity->id,
            'doctor_payout_id' => $payout->id,
            'source_type'      => $type->value,
            'source_id'        => $sourceId,
            'service_type'     => 'consultation',
            'performed_at'     => '2026-06-10 09:00:00',
            'description'      => 'CONSULTA',
            'base_source'      => 'charged',
        ]);
    }

    it('ato fechado no regime anterior (produção) sai da lista; anulado volta; parcela por recebimento continua listada', function () {
        $schedule = payoutProdSchedule($this);
        $item     = payoutProdCloseSource($this, DoctorPayoutSourceType::Schedule, $schedule->id);

        expect(payoutProdRun($this))->toBeEmpty();

        $item->update(['voided_at' => now()]);

        expect(payoutProdRun($this)->pluck('sourceId')->all())->toBe([$schedule->id]);

        // Regime por recebimento: o ato continua na lista (complemento/estorno).
        payoutProdCloseSource($this, DoctorPayoutSourceType::Schedule, $schedule->id)->update(['basis' => 'receipt']);

        expect(payoutProdRun($this)->pluck('sourceId')->all())->toBe([$schedule->id]);
    });

    it('exame fechado no regime anterior (pela chave) não volta', function () {
        $oct = ExamType::factory()->create(['name' => 'OCT']);
        payoutProdExam($this, $oct);
        payoutProdCloseSource($this, DoctorPayoutSourceType::PatientExam, DoctorPayoutProductionService::examKey($this->patient->id, $oct->id, '2026-06-10'));

        $schedule = payoutProdSchedule($this, ['date_time' => '2026-06-12 09:00:00']);

        expect(payoutProdRun($this)->pluck('sourceId')->all())->toBe([$schedule->id]);
    });

    it('fechamento cancelado não conta como período fechado nem prende itens', function () {
        $schedule = payoutProdSchedule($this);
        payoutProdCloseSource($this, DoctorPayoutSourceType::Schedule, $schedule->id, 'cancelled')
            ->update(['voided_at' => now()]);

        $items = payoutProdRun($this);

        expect($items->pluck('sourceId')->all())->toBe([$schedule->id])
            ->and($items->first()->warnings)->toBe([]);
    });
});

it('vários médicos numa busca: cada ato com o próprio executor (agenda, prontuário e exame)', function () {
    $schedule = payoutProdSchedule($this);
    $yag      = payoutProdProcedure(4, 'CAPSULOTOMIA YAG');
    $record   = payoutProdRecord($this, null, $this->otherDoctor);
    $executed = payoutProdExecuted($this, $record, $yag, ['executed_by' => $this->otherDoctor->entity_user_id]);
    $oct      = ExamType::factory()->create(['name' => 'OCT']);
    payoutProdExam($this, $oct, ['doctor_id' => $this->otherDoctor->id]);

    $items = $this->service->actsOf(
        $this->entity->id,
        [$this->doctor->id, $this->otherDoctor->id],
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    $exam = DoctorPayoutProductionService::examKey($this->patient->id, $oct->id, '2026-06-10');

    expect($items)->toHaveCount(3)
        ->and(payoutProdBySource($items, DoctorPayoutSourceType::Schedule, $schedule->id)->doctorId)->toBe($this->doctor->id)
        ->and(payoutProdBySource($items, DoctorPayoutSourceType::MedicalRecordProcedure, $executed->id)->doctorId)->toBe($this->otherDoctor->id)
        ->and(payoutProdBySource($items, DoctorPayoutSourceType::PatientExam, $exam)->doctorId)->toBe($this->otherDoctor->id)
        // Um médico só continua vendo só os próprios atos.
        ->and(payoutProdRun($this)->pluck('sourceId')->all())->toBe([$schedule->id])
        ->and(payoutProdRun($this, $this->otherDoctor)->pluck('sourceId')->sort()->values()->all())->toBe(collect([$executed->id, $exam])->sort()->values()->all());
});

it('número de consultas ao banco não cresce com o número de atendimentos', function () {
    foreach (range(1, 8) as $day) {
        $schedule = payoutProdSchedule($this, ['date_time' => sprintf('2026-06-%02d 09:00:00', $day)]);
        payoutProdCash($this, $schedule, 100.00);
    }

    DB::enableQueryLog();
    $items   = payoutProdRun($this);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($items)->toHaveCount(8)
        ->and($queries)->toBeLessThanOrEqual(8);
});
