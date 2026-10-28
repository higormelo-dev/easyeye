<?php

/*
 * Repasse médico — rastreio de recebimento (DoctorPayoutReceiptTracer):
 * cobrado/faturado, glosa, recebido (receita PAGA no caixa) e a receber de
 * cada item, sem dupla contagem de glosa nem de cobrança compartilhada, e
 * sem enxergar lançamentos de outra clínica.
 */

use App\DTOs\DoctorPayout\ReceiptTraceData;
use App\Enums\DoctorPayout\{DoctorPayoutReceiptStatus, DoctorPayoutSourceType};
use App\Enums\ScheduleSituation;
use App\Models\{BillingClaim, Covenant, Entity, FinancialCashEntry, MedicalRecord, MedicalRecordProcedure, Patient, Procedure, Schedule, VisitType};
use App\Services\Financial\DoctorPayouts\{DoctorPayoutProductionService, DoctorPayoutReceiptTracer};
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor   = createDoctorForEntity($this->entity);
    $this->covenant = Covenant::factory()->create([
        'entity_id' => $this->entity->id, 'active' => true, 'name' => 'OPERADORA TESTE', 'ans_registry' => '326305',
    ]);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $this->covenant->id]);
    $this->tracer  = app(DoctorPayoutReceiptTracer::class);
});

/** Horários distintos: a agenda não aceita dois atendimentos do médico no mesmo horário. */
function payoutTraceSchedule($test, array $attrs = []): Schedule
{
    static $slot = 0;
    $slot++;

    return Schedule::create(array_merge([
        'entity_id'   => $test->entity->id,
        'doctor_id'   => $test->doctor->id,
        'patient_id'  => $test->patient->id,
        'covenant_id' => $test->covenant->id,
        'full_name'   => 'Paciente Rastreio',
        'date_time'   => sprintf('2026-06-10 %02d:%02d:00', 7 + intdiv($slot % 600, 60), $slot % 60),
        'situation'   => ScheduleSituation::Attended->value,
        'active'      => true,
    ], $attrs));
}

/** Receita de balcão/coparticipação do agendamento. */
function payoutTraceDesk($test, Schedule $schedule, float $amount, string $status = 'paid', array $attrs = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id'      => $test->entity->id,
        'entry_date'     => '2026-06-10',
        'description'    => 'Recebimento',
        'type'           => 'income',
        'status'         => $status,
        'amount'         => $amount,
        'reference_type' => 'schedule',
        'reference_id'   => $schedule->id,
        'active'         => true,
    ], $attrs));
}

function payoutTraceClaim($test, Schedule $schedule, array $attrs = []): BillingClaim
{
    return BillingClaim::create(array_merge([
        'entity_id'       => $test->entity->id,
        'schedule_id'     => $schedule->id,
        'patient_id'      => $schedule->patient_id,
        'doctor_id'       => $schedule->doctor_id,
        'covenant_id'     => $test->covenant->id,
        'status'          => 'submitted',
        'attendance_date' => '2026-06-10',
        'amount'          => 200,
        'quantity'        => 1,
        'unit_price'      => 200,
    ], $attrs));
}

/** Receita do recebimento da guia (como BillingService::payLockedClaim grava). */
function payoutTraceClaimReceipt($test, BillingClaim $claim, float $amount, array $attrs = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id'        => $test->entity->id,
        'billing_claim_id' => $claim->id,
        'covenant_id'      => $claim->covenant_id,
        'entry_date'       => '2026-07-15',
        'description'      => 'Recebimento guia',
        'type'             => 'income',
        'status'           => 'paid',
        'amount'           => $amount,
        'nature'           => 'covenant',
        'reference_type'   => 'billing_claim',
        'reference_id'     => $claim->id,
        'active'           => true,
    ], $attrs));
}

function payoutTraceKey(Schedule $schedule): string
{
    return DoctorPayoutSourceType::Schedule->value . ':' . $schedule->id;
}

function payoutTraceOne($test, Schedule $schedule): ReceiptTraceData
{
    return $test->tracer->trace($test->entity->id, [payoutTraceKey($schedule)])[payoutTraceKey($schedule)];
}

describe('balcão (particular / coparticipação)', function () {
    it('paga = recebido; pendente = a receber; cancelada, excluída ou inexistente = sem cobrança', function () {
        $paid = payoutTraceSchedule($this);
        payoutTraceDesk($this, $paid, 200.00);

        $pending = payoutTraceSchedule($this);
        payoutTraceDesk($this, $pending, 150.00, 'pending');

        $voided = payoutTraceSchedule($this);
        payoutTraceDesk($this, $voided, 100.00, 'cancelled');
        payoutTraceDesk($this, $voided, 50.00)->delete();

        $nothing = payoutTraceSchedule($this);

        expect(payoutTraceOne($this, $paid))
            ->status->toBe(DoctorPayoutReceiptStatus::Received)
            ->billedCents->toBe(20000)
            ->receivedCents->toBe(20000)
            ->openCents->toBe(0)
            ->differenceCents->toBe(0);

        expect(payoutTraceOne($this, $pending))
            ->status->toBe(DoctorPayoutReceiptStatus::Awaiting)
            ->receivedCents->toBe(0)
            ->openCents->toBe(15000);

        expect(payoutTraceOne($this, $voided)->status)->toBe(DoctorPayoutReceiptStatus::NoCharge)
            ->and(payoutTraceOne($this, $voided)->billedCents)->toBe(0)
            ->and(payoutTraceOne($this, $nothing)->status)->toBe(DoctorPayoutReceiptStatus::NoCharge);
    });

    it('coparticipação recebida + guia do convênio enviada = recebido em parte', function () {
        $schedule = payoutTraceSchedule($this);
        payoutTraceDesk($this, $schedule, 30.00, 'paid', ['nature' => 'copay']);
        payoutTraceClaim($this, $schedule, ['amount' => 170, 'unit_price' => 170]);

        expect(payoutTraceOne($this, $schedule))
            ->status->toBe(DoctorPayoutReceiptStatus::Partial)
            ->billedCents->toBe(20000)
            ->receivedCents->toBe(3000)
            ->openCents->toBe(17000);
    });
});

describe('convênio (guias)', function () {
    it('rascunho = a faturar; enviada = a receber pelo líquido da glosa; paga com receita no caixa = recebido', function () {
        $draft = payoutTraceSchedule($this);
        payoutTraceClaim($this, $draft, ['status' => 'draft']);

        $submitted = payoutTraceSchedule($this);
        payoutTraceClaim($this, $submitted, ['glosa_amount' => 30]);

        $paid  = payoutTraceSchedule($this);
        $claim = payoutTraceClaim($this, $paid, ['status' => 'paid', 'paid_amount' => 200, 'paid_at' => '2026-07-15']);
        payoutTraceClaimReceipt($this, $claim, 200.00);

        expect(payoutTraceOne($this, $draft))
            ->status->toBe(DoctorPayoutReceiptStatus::ToBill)
            ->openCents->toBe(20000);

        expect(payoutTraceOne($this, $submitted))
            ->status->toBe(DoctorPayoutReceiptStatus::Awaiting)
            ->billedCents->toBe(20000)
            ->glosaCents->toBe(3000)
            ->openCents->toBe(17000)
            ->differenceCents->toBe(0);

        expect(payoutTraceOne($this, $paid))
            ->status->toBe(DoctorPayoutReceiptStatus::Received)
            ->receivedCents->toBe(20000)
            ->openCents->toBe(0);
    });

    it('glosa parcial: o restante continua a receber e, quando pago, a glosa não é descontada de novo', function () {
        $schedule = payoutTraceSchedule($this);
        $claim    = payoutTraceClaim($this, $schedule, ['status' => 'denied', 'glosa_amount' => 50]);

        expect(payoutTraceOne($this, $schedule))
            ->status->toBe(DoctorPayoutReceiptStatus::Awaiting)
            ->glosaCents->toBe(5000)
            ->openCents->toBe(15000);

        // Convênio paga o restante (negada → paga): R$ 150 de receita.
        $claim->update(['status' => 'paid', 'paid_amount' => 150, 'paid_at' => '2026-07-20']);
        payoutTraceClaimReceipt($this, $claim, 150.00);

        expect(payoutTraceOne($this, $schedule))
            ->status->toBe(DoctorPayoutReceiptStatus::Received)
            ->billedCents->toBe(20000)
            ->glosaCents->toBe(5000)
            ->receivedCents->toBe(15000)
            ->openCents->toBe(0)
            ->differenceCents->toBe(0);
    });

    it('glosa total sem recebimento = glosado; recurso aceito e pago (glosa revertida) = recebido', function () {
        $denied = payoutTraceSchedule($this);
        payoutTraceClaim($this, $denied, ['status' => 'denied', 'glosa_amount' => 200]);

        expect(payoutTraceOne($this, $denied))
            ->status->toBe(DoctorPayoutReceiptStatus::Denied)
            ->glosaCents->toBe(20000)
            ->openCents->toBe(0);

        // payLockedClaim: receber acima do restante baixa a glosa (recurso aceito).
        $recovered = payoutTraceSchedule($this);
        $claim     = payoutTraceClaim($this, $recovered, ['status' => 'paid', 'glosa_amount' => 0, 'paid_amount' => 200, 'paid_at' => '2026-08-01']);
        payoutTraceClaimReceipt($this, $claim, 200.00);

        expect(payoutTraceOne($this, $recovered))
            ->status->toBe(DoctorPayoutReceiptStatus::Received)
            ->glosaCents->toBe(0)
            ->receivedCents->toBe(20000);
    });

    it('guia paga a menor sem glosa mostra a diferença; paga sem receita no caixa não prova recebimento', function () {
        $short = payoutTraceSchedule($this);
        $claim = payoutTraceClaim($this, $short, ['status' => 'paid', 'paid_amount' => 180, 'paid_at' => '2026-07-15']);
        payoutTraceClaimReceipt($this, $claim, 180.00);

        $legacy = payoutTraceSchedule($this);
        payoutTraceClaim($this, $legacy, ['status' => 'paid', 'paid_amount' => 200, 'paid_at' => '2026-07-15']);

        expect(payoutTraceOne($this, $short))
            ->status->toBe(DoctorPayoutReceiptStatus::Received)
            ->receivedCents->toBe(18000)
            ->differenceCents->toBe(2000);

        expect(payoutTraceOne($this, $legacy))
            ->status->toBe(DoctorPayoutReceiptStatus::Unconfirmed)
            ->receivedCents->toBe(0)
            ->differenceCents->toBe(20000);
    });

    it('receita da guia cancelada ou excluída e guia cancelada não contam', function () {
        $schedule = payoutTraceSchedule($this);
        $claim    = payoutTraceClaim($this, $schedule, ['status' => 'paid', 'paid_amount' => 200, 'paid_at' => '2026-07-15']);
        payoutTraceClaimReceipt($this, $claim, 200.00)->delete();

        $cancelled = payoutTraceSchedule($this);
        payoutTraceClaim($this, $cancelled, ['status' => 'cancelled']);

        expect(payoutTraceOne($this, $schedule)->status)->toBe(DoctorPayoutReceiptStatus::Unconfirmed)
            ->and(payoutTraceOne($this, $cancelled)->status)->toBe(DoctorPayoutReceiptStatus::NoCharge);
    });
});

describe('itens sem agendamento próprio', function () {
    it('procedimentos pareados mostram o atendimento inteiro; não pareado e exame de equipamento ficam sem cobrança própria', function () {
        $injection = Procedure::factory()->create(['treatment' => 4, 'name' => 'INJECAO INTRAVITREA']);
        $type      = VisitType::create(['entity_id' => $this->entity->id, 'name' => 'INJECAO', 'procedure_id' => $injection->id, 'active' => true]);
        $schedule  = payoutTraceSchedule($this, ['visit_id' => $type->id]);
        payoutTraceDesk($this, $schedule, 1000.01);

        $record = MedicalRecord::create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'schedule_id' => $schedule->id,
        ]);
        $procedure = fn (Procedure $p, string $at) => MedicalRecordProcedure::create([
            'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $record->id,
            'procedure_id' => $p->id, 'doctor_id' => $this->doctor->id, 'status' => 'done', 'executed_at' => $at,
            'executed_by'  => $this->doctor->entity_user_id,
        ]);

        $od       = $procedure($injection, '2026-06-10 10:00:00');
        $oe       = $procedure($injection, '2026-06-10 10:05:00');
        $unpaired = $procedure(Procedure::factory()->create(['treatment' => 4, 'name' => 'YAG']), '2026-06-10 10:10:00');

        $keys = [
            'od'       => DoctorPayoutSourceType::MedicalRecordProcedure->value . ':' . $od->id,
            'oe'       => DoctorPayoutSourceType::MedicalRecordProcedure->value . ':' . $oe->id,
            'unpaired' => DoctorPayoutSourceType::MedicalRecordProcedure->value . ':' . $unpaired->id,
            'exam'     => DoctorPayoutSourceType::PatientExam->value . ':' . DoctorPayoutProductionService::examKey($this->patient->id, (string) str()->uuid(), '2026-06-10'),
        ];

        $traces = $this->tracer->trace($this->entity->id, array_values($keys));

        // Pareados mostram o atendimento INTEIRO (sem fração inventada) e dizem
        // quantos o dividem; quem soma conta o atendimento (unitId) uma vez.
        foreach (['od', 'oe'] as $eye) {
            expect($traces[$keys[$eye]])
                ->status->toBe(DoctorPayoutReceiptStatus::Received)
                ->receivedCents->toBe(100001)
                ->sharedBy->toBe(2)
                ->unitId->toBe($schedule->id);
        }

        expect($traces[$keys['unpaired']]->status)->toBe(DoctorPayoutReceiptStatus::NotLinked)
            ->and($traces[$keys['exam']]->status)->toBe(DoctorPayoutReceiptStatus::NotLinked)
            ->and($traces[$keys['exam']]->toArray()['received'])->toBeNull();
    });
});

it('não enxerga lançamentos nem guias de outra clínica', function () {
    $schedule = payoutTraceSchedule($this);
    $other    = Entity::factory()->create(['is_client' => true, 'active' => true]);

    // Linhas de outra clínica apontando para o mesmo agendamento (dado corrompido/ataque).
    payoutTraceDesk($this, $schedule, 999.00, 'paid', ['entity_id' => $other->id]);
    payoutTraceClaim($this, $schedule, ['entity_id' => $other->id, 'status' => 'draft']);

    expect(payoutTraceOne($this, $schedule))
        ->status->toBe(DoctorPayoutReceiptStatus::NoCharge)
        ->billedCents->toBe(0)
        ->receivedCents->toBe(0);
});

it('número de consultas ao banco não cresce com o número de itens', function () {
    $keys = [];

    foreach (range(1, 12) as $day) {
        $schedule = payoutTraceSchedule($this, ['date_time' => sprintf('2026-06-%02d 09:00:00', $day)]);
        payoutTraceDesk($this, $schedule, 100.00);
        payoutTraceClaim($this, $schedule, ['amount' => 50, 'unit_price' => 50]);
        $keys[] = payoutTraceKey($schedule);
    }

    DB::enableQueryLog();
    $traces  = $this->tracer->trace($this->entity->id, $keys);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($traces)->toHaveCount(12)
        ->and($queries)->toBeLessThanOrEqual(3);
});
