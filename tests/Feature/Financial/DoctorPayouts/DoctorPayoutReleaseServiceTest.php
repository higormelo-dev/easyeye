<?php

/*
 * Repasse médico — regime por RECEBIMENTO (DoctorPayoutReleaseService +
 * fechamento): parcela = devido sobre o recebido acumulado até o fim do
 * período − já liberado. Critérios de aceite do sócio médico: convênio sem
 * recebimento não libera; glosa total/parcial sem desconto duplo; recurso
 * pago depois libera só o complemento; estorno desconta; regra congelada;
 * sem sobra de centavos entre parcelas.
 */

use App\DTOs\DoctorPayout\PayoutItemData;
use App\Enums\DoctorPayout\{DoctorPayoutBaseSource, DoctorPayoutSourceType, DoctorPayoutWarning};
use App\Enums\ScheduleSituation;
use App\Models\{BillingClaim, Covenant, DoctorPayout, DoctorPayoutItem, DoctorPayoutRule, Entity, FinancialCashEntry, MedicalRecord, MedicalRecordProcedure, Patient, Procedure, Schedule, VisitType};
use App\Services\Financial\DoctorPayouts\{DoctorPayoutCalculator, DoctorPayoutClosingService};
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor   = createDoctorForEntity($this->entity);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'ans_registry' => '326305']);
    $this->patient  = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $this->covenant->id]);

    $this->rule = DoctorPayoutRule::query()->create([
        'entity_id'   => $this->entity->id, 'service_type' => 'consultation', 'payer_scope' => 'any',
        'calculation' => 'percentage', 'percentage' => '60.00', 'active' => true,
    ]);

    $this->calculator = app(DoctorPayoutCalculator::class);
    $this->closing    = app(DoctorPayoutClosingService::class);
});

/** Horários distintos: a agenda não aceita dois atendimentos do médico no mesmo horário. */
function releaseSchedule($test, array $attrs = []): Schedule
{
    static $slot = 0;
    $slot++;

    return Schedule::create(array_merge([
        'entity_id'   => $test->entity->id, 'doctor_id' => $test->doctor->id, 'patient_id' => $test->patient->id,
        'covenant_id' => $test->covenant->id, 'full_name' => 'Paciente', 'situation' => ScheduleSituation::Attended->value,
        'date_time'   => sprintf('2026-06-10 %02d:%02d:00', 7 + intdiv($slot % 600, 60), $slot % 60), 'active' => true,
    ], $attrs));
}

function releaseDesk($test, Schedule $schedule, float $amount, string $date = '2026-06-10', string $status = 'paid'): FinancialCashEntry
{
    return FinancialCashEntry::query()->create([
        'entity_id' => $test->entity->id, 'entry_date' => $date, 'description' => 'Balcão', 'type' => 'income',
        'status'    => $status, 'amount' => $amount, 'reference_type' => 'schedule', 'reference_id' => $schedule->id,
        'active'    => true,
    ]);
}

function releaseClaim($test, Schedule $schedule, array $attrs = []): BillingClaim
{
    return BillingClaim::create(array_merge([
        'entity_id'       => $test->entity->id, 'schedule_id' => $schedule->id, 'patient_id' => $schedule->patient_id,
        'doctor_id'       => $schedule->doctor_id, 'covenant_id' => $test->covenant->id, 'status' => 'submitted',
        'attendance_date' => '2026-06-10', 'amount' => 200, 'quantity' => 1, 'unit_price' => 200,
    ], $attrs));
}

/** Recebimento da guia como BillingService::payLockedClaim grava (receita paga + guia paga). */
function releaseClaimPaid($test, BillingClaim $claim, float $amount, string $date, array $claimChanges = []): FinancialCashEntry
{
    $claim->update(array_merge(['status' => 'paid', 'paid_amount' => $amount, 'paid_at' => $date], $claimChanges));

    return FinancialCashEntry::query()->create([
        'entity_id'      => $test->entity->id, 'billing_claim_id' => $claim->id, 'entry_date' => $date,
        'description'    => 'Guia', 'type' => 'income', 'status' => 'paid', 'amount' => $amount, 'nature' => 'covenant',
        'reference_type' => 'billing_claim', 'reference_id' => $claim->id, 'active' => true,
    ]);
}

/** @return array{releases: Collection<int, PayoutItemData>, awaiting: Collection<int, PayoutItemData>} */
function releaseCompute($test, string $from = '2026-06-01', string $to = '2026-06-30'): array
{
    return $test->calculator->apuracao($test->entity->id, $test->doctor->id, CarbonImmutable::parse($from), CarbonImmutable::parse($to));
}

/** Fecha como a tela: prévia calculada → fechamento com os números conferidos. */
function releaseClose($test, string $from = '2026-06-01', string $to = '2026-06-30'): DoctorPayout
{
    $totals = DoctorPayoutCalculator::totals(releaseCompute($test, $from, $to)['releases']);

    return $test->closing->close($test->entity->id, $test->doctor->id, [
        'period_start'           => $from,
        'period_end'             => $to,
        'expected_count'         => $totals['count'],
        'expected_charged_cents' => $totals['charged_cents'],
        'expected_payout_cents'  => $totals['payout_cents'],
    ], null);
}

function releaseOf(array $result, Schedule|MedicalRecordProcedure $source): ?PayoutItemData
{
    $type = $source instanceof Schedule ? DoctorPayoutSourceType::Schedule : DoctorPayoutSourceType::MedicalRecordProcedure;

    return $result['releases']->first(fn (PayoutItemData $item) => $item->sourceType === $type && $item->sourceId === $source->id);
}

describe('liberação pelo recebido', function () {
    it('convênio sem recebimento: aparece aguardando com a previsão, não libera nem fecha', function () {
        $schedule = releaseSchedule($this);
        releaseClaim($this, $schedule);

        $result = releaseCompute($this);

        expect($result['releases'])->toBeEmpty()
            ->and($result['awaiting'])->toHaveCount(1)
            ->and($result['awaiting']->first()->forecastCents)->toBe(12000) // 60% de 200 (previsão)
            ->and($result['awaiting']->first()->payoutCents)->toBe(0);

        expect(fn () => releaseClose($this))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.nothing_to_close'));
    });

    it('guia paga com receita no caixa: libera % do recebido e o fechamento congela a parcela com o retrato', function () {
        $schedule = releaseSchedule($this);
        $entry    = releaseClaimPaid($this, releaseClaim($this, $schedule), 200.00, '2026-06-20');

        $release = releaseOf(releaseCompute($this), $schedule);

        expect($release->tranche)->toBe(1)
            ->and($release->baseCents)->toBe(20000)
            ->and($release->baseSource)->toBe(DoctorPayoutBaseSource::Received)
            ->and($release->payoutCents)->toBe(12000);

        $payout = releaseClose($this);
        $item   = DoctorPayoutItem::query()->where('doctor_payout_id', $payout->id)->sole();

        expect($payout->basis->value)->toBe('receipt')
            ->and($item->basis->value)->toBe('receipt')
            ->and($item->tranche)->toBe(1)
            ->and((string) $item->received_amount)->toBe('200.00')
            ->and((string) $item->payout_amount)->toBe('120.00')
            ->and($item->receipts_until->toDateString())->toBe('2026-06-30')
            ->and($item->receipts)->toBe([['id' => $entry->id, 'date' => '2026-06-20', 'amount_cents' => 20000, 'kind' => 'claim']]);

        expect(releaseCompute($this)['releases'])->toBeEmpty();
    });

    it('recebimento depois do fim do período não entra; entra no período seguinte', function () {
        $schedule = releaseSchedule($this);
        releaseClaimPaid($this, releaseClaim($this, $schedule), 200.00, '2026-07-05');

        expect(releaseCompute($this)['releases'])->toBeEmpty()
            ->and(releaseOf(releaseCompute($this, '2026-07-01', '2026-07-31'), $schedule)->payoutCents)->toBe(12000);
    });

    it('glosa parcial: libera só o restante pago, sem descontar a glosa de novo', function () {
        $schedule = releaseSchedule($this);
        $claim    = releaseClaim($this, $schedule, ['status' => 'denied', 'glosa_amount' => 50]);

        expect(releaseCompute($this)['releases'])->toBeEmpty();

        releaseClaimPaid($this, $claim, 150.00, '2026-06-25');

        $release = releaseOf(releaseCompute($this), $schedule);

        expect($release->baseCents)->toBe(15000)
            ->and($release->payoutCents)->toBe(9000); // 60% de 150 — a glosa (50) já está fora do recebido
    });

    it('glosa total não libera; recurso aceito e pago depois libera só o complemento', function () {
        $schedule = releaseSchedule($this);
        $claim    = releaseClaim($this, $schedule, ['status' => 'denied', 'glosa_amount' => 200]);

        expect(releaseCompute($this)['releases'])->toBeEmpty();

        // Coparticipação recebida e liberada em junho.
        releaseDesk($this, $schedule, 30.00, '2026-06-10');
        releaseClose($this);

        // Recurso aceito e pago em agosto (payLockedClaim baixa a glosa).
        releaseClaimPaid($this, $claim, 200.00, '2026-08-10', ['glosa_amount' => 0]);

        $release = releaseOf(releaseCompute($this, '2026-08-01', '2026-08-31'), $schedule);

        expect($release->tranche)->toBe(2)
            ->and($release->receivedCents)->toBe(23000)
            ->and($release->baseCents)->toBe(20000)
            ->and($release->releasedBeforeCents)->toBe(1800)
            ->and($release->payoutCents)->toBe(12000); // 60% de 230 = 138 − 18 já liberado
    });

    it('parcelas somam exatamente o devido sobre o acumulado (sem sobra de centavos)', function () {
        $schedule = releaseSchedule($this);
        releaseDesk($this, $schedule, 0.01, '2026-06-10');
        releaseClose($this); // 60% de 0,01 = 0,006 → 0,01

        releaseDesk($this, $schedule, 0.01, '2026-07-10');
        $second = releaseOf(releaseCompute($this, '2026-07-01', '2026-07-31'), $schedule);

        // Acumulado 0,02 → 60% = 0,012 → 0,01: nada a mais (por parcela daria 0,02).
        expect($second->payoutCents)->toBe(0)
            ->and($second->baseCents)->toBe(1);
    });
});

describe('regra de valor fixo', function () {
    beforeEach(function () {
        $this->rule->update(['calculation' => 'fixed', 'percentage' => null, 'fixed_amount' => '80.00']);
    });

    it('proporcional ao recebido sobre o líquido esperado; o restante libera quando entrar', function () {
        $schedule = releaseSchedule($this);
        releaseDesk($this, $schedule, 100.00, '2026-06-10');
        $pending = releaseDesk($this, $schedule, 100.00, '2026-06-10', 'pending');

        expect(releaseOf(releaseCompute($this), $schedule)->payoutCents)->toBe(4000); // 50% do fixo
        releaseClose($this);

        $pending->update(['status' => 'paid', 'entry_date' => '2026-07-15']);

        $complement = releaseOf(releaseCompute($this, '2026-07-01', '2026-07-31'), $schedule);

        expect($complement->tranche)->toBe(2)
            ->and($complement->payoutCents)->toBe(4000);
    });

    it('glosa total: nada a liberar', function () {
        $schedule = releaseSchedule($this);
        releaseClaim($this, $schedule, ['status' => 'denied', 'glosa_amount' => 200]);

        expect(releaseCompute($this)['releases'])->toBeEmpty();
    });
});

describe('estorno, regra congelada e ordem dos fechamentos', function () {
    it('recebimento estornado depois do fechamento vira parcela negativa; total negativo não fecha', function () {
        $schedule = releaseSchedule($this);
        $entry    = releaseDesk($this, $schedule, 100.00, '2026-06-10');
        releaseClose($this);

        $entry->delete();

        $reversal = releaseOf(releaseCompute($this, '2026-07-01', '2026-07-31'), $schedule);

        expect($reversal->tranche)->toBe(2)
            ->and($reversal->payoutCents)->toBe(-6000)
            ->and($reversal->baseCents)->toBe(-10000)
            ->and($reversal->hasWarning(DoctorPayoutWarning::NegativeAdjustment))->toBeTrue();

        expect(fn () => releaseClose($this, '2026-07-01', '2026-07-31'))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.release_negative'));

        // Novo recebimento maior que o estorno: o fechamento sai com o líquido.
        releaseDesk($this, releaseSchedule($this), 150.00, '2026-07-20');

        expect((string) releaseClose($this, '2026-07-01', '2026-07-31')->total_amount)->toBe('30.00'); // 90 − 60
    });

    it('complemento usa a regra congelada no 1º fechamento, mesmo com a regra editada', function () {
        $schedule = releaseSchedule($this);
        releaseDesk($this, $schedule, 30.00, '2026-06-10');
        releaseClose($this);

        $this->rule->update(['percentage' => '50.00']);
        releaseDesk($this, $schedule, 70.00, '2026-07-10');

        $complement = releaseOf(releaseCompute($this, '2026-07-01', '2026-07-31'), $schedule);

        expect((float) $complement->rulePercentage)->toBe(60.0)
            ->and($complement->payoutCents)->toBe(4200); // 60% de 100 − 18
    });

    it('fechar período que termina antes do último fechamento é recusado', function () {
        releaseDesk($this, releaseSchedule($this), 100.00, '2026-06-10');
        releaseClose($this);

        releaseDesk($this, releaseSchedule($this), 100.00, '2026-05-10');

        expect(fn () => releaseClose($this, '2026-05-01', '2026-05-31'))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.period_before_last', ['date' => CarbonImmutable::parse('2026-06-30')->isoFormat('L')]));
    });

    it('recebimento com data dentro de período já fechado entra no próximo com alerta de atraso', function () {
        releaseDesk($this, releaseSchedule($this), 100.00, '2026-06-10');
        releaseClose($this);

        $late = releaseSchedule($this);
        releaseDesk($this, $late, 50.00, '2026-06-20'); // baixado depois do fechamento de junho

        $release = releaseOf(releaseCompute($this, '2026-07-01', '2026-07-31'), $late);

        expect($release->payoutCents)->toBe(3000)
            ->and($release->hasWarning(DoctorPayoutWarning::LateReceipt))->toBeTrue();
    });

    it('ato fechado no regime anterior (produção) não gera parcela', function () {
        $schedule = releaseSchedule($this);
        releaseDesk($this, $schedule, 100.00, '2026-06-10');

        $legacy = DoctorPayout::query()->create([
            'entity_id'    => $this->entity->id, 'doctor_id' => $this->doctor->id, 'doctor_name' => 'Dr.',
            'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'closed', 'closed_at' => now(),
        ]);
        DoctorPayoutItem::query()->create([
            'entity_id'   => $this->entity->id, 'doctor_payout_id' => $legacy->id, 'source_type' => 'schedule',
            'source_id'   => $schedule->id, 'service_type' => 'consultation', 'performed_at' => $schedule->date_time,
            'description' => 'CONSULTA', 'base_source' => 'charged', 'base_amount' => '100.00', 'payout_amount' => '60.00',
        ]);

        expect(releaseCompute($this)['releases'])->toBeEmpty();
    });
});

describe('procedimentos iguais pareados (OD e OE)', function () {
    it('o segundo executado depois do fechamento corrige o primeiro: a soma liberada nunca passa do recebido', function () {
        DoctorPayoutRule::query()->create([
            'entity_id'   => $this->entity->id, 'service_type' => 'procedure', 'payer_scope' => 'any',
            'calculation' => 'percentage', 'percentage' => '60.00', 'active' => true,
        ]);

        $injection = Procedure::factory()->create(['treatment' => 4, 'name' => 'INJECAO INTRAVITREA']);
        $type      = VisitType::create(['entity_id' => $this->entity->id, 'name' => 'INJECAO', 'procedure_id' => $injection->id, 'active' => true]);
        $schedule  = releaseSchedule($this, ['visit_id' => $type->id]);
        releaseDesk($this, $schedule, 1000.01, '2026-06-10');

        $record = MedicalRecord::create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'schedule_id' => $schedule->id,
        ]);
        $execute = fn (string $eye, string $at) => MedicalRecordProcedure::create([
            'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $record->id,
            'procedure_id' => $injection->id, 'doctor_id' => $this->doctor->id, 'status' => 'done', 'eye' => $eye,
            'executed_at'  => $at, 'executed_by' => $this->doctor->entity_user_id,
        ]);

        $od = $execute('OD', '2026-06-10 10:00:00');
        expect(releaseOf(releaseCompute($this), $od)->baseCents)->toBe(100001);
        releaseClose($this);

        $oe = $execute('OE', '2026-06-12 10:00:00'); // marcado depois do fechamento

        $july = releaseCompute($this, '2026-07-01', '2026-07-31');

        expect(releaseOf($july, $od)->baseCents)->toBe(-50000)          // OD fica com 500,01
            ->and(releaseOf($july, $od)->payoutCents)->toBe(-30000)
            ->and(releaseOf($july, $oe)->baseCents)->toBe(50000)         // OE com 500,00
            ->and(releaseOf($july, $oe)->payoutCents)->toBe(30000)
            ->and((string) releaseClose($this, '2026-07-01', '2026-07-31')->total_amount)->toBe('0.00');

        $released = DoctorPayoutItem::query()->whereNull('voided_at')->where('basis', 'receipt')->sum('base_amount');
        expect((string) $released)->toBe('1000.01');
    });
});

describe('ato que deixa de valer depois de liberado', function () {
    function releaseProcedureAct($test): array
    {
        DoctorPayoutRule::query()->create([
            'entity_id'   => $test->entity->id, 'service_type' => 'procedure', 'payer_scope' => 'any',
            'calculation' => 'percentage', 'percentage' => '60.00', 'active' => true,
        ]);

        $yag      = Procedure::factory()->create(['treatment' => 4, 'name' => 'CAPSULOTOMIA YAG']);
        $type     = VisitType::create(['entity_id' => $test->entity->id, 'name' => 'YAG', 'procedure_id' => $yag->id, 'active' => true]);
        $schedule = releaseSchedule($test, ['visit_id' => $type->id]);
        releaseDesk($test, $schedule, 100.00, '2026-06-10');

        $record = MedicalRecord::create([
            'entity_id' => $test->entity->id, 'patient_id' => $test->patient->id, 'doctor_id' => $test->doctor->id, 'schedule_id' => $schedule->id,
        ]);
        $procedure = MedicalRecordProcedure::create([
            'entity_id'    => $test->entity->id, 'patient_id' => $test->patient->id, 'medical_record_id' => $record->id,
            'procedure_id' => $yag->id, 'doctor_id' => $test->doctor->id, 'status' => 'done',
            'executed_at'  => '2026-06-10 10:00:00', 'executed_by' => $test->doctor->entity_user_id,
        ]);

        return [$schedule, $record, $procedure];
    }

    it('prontuário excluído depois de liberar o procedimento: estorna o procedimento e libera o agendamento — saldo zero, sem pagar duas vezes', function () {
        [$schedule, $record, $procedure] = releaseProcedureAct($this);

        expect(releaseOf(releaseCompute($this), $procedure)->payoutCents)->toBe(6000);
        releaseClose($this);

        $record->delete(); // prontuário não assinado pode ser excluído

        $july      = releaseCompute($this, '2026-07-01', '2026-07-31');
        $reversal  = releaseOf($july, $procedure);
        $scheduled = releaseOf($july, $schedule);

        expect($reversal->payoutCents)->toBe(-6000)
            ->and($reversal->baseCents)->toBe(-10000)
            ->and($reversal->tranche)->toBe(2)
            ->and($reversal->hasWarning(DoctorPayoutWarning::ActRemoved))->toBeTrue()
            ->and($scheduled->payoutCents)->toBe(6000);

        expect((string) releaseClose($this, '2026-07-01', '2026-07-31')->total_amount)->toBe('0.00');

        $paidOut = DoctorPayoutItem::query()->whereNull('voided_at')->where('basis', 'receipt')->sum('payout_amount');
        expect((string) $paidOut)->toBe('60.00'); // 60% de R$ 100 recebidos, uma vez só
    });

    it('médico do agendamento corrigido depois de liberado: estorna do anterior e libera ao novo', function () {
        $schedule = releaseSchedule($this);
        releaseDesk($this, $schedule, 100.00, '2026-06-10');
        releaseClose($this);

        $other = createDoctorForEntity($this->entity);
        $schedule->update(['doctor_id' => $other->id]);

        $previous = releaseOf(releaseCompute($this, '2026-07-01', '2026-07-31'), $schedule);

        expect($previous->payoutCents)->toBe(-6000)
            ->and($previous->hasWarning(DoctorPayoutWarning::ActRemoved))->toBeTrue();

        $new = $this->calculator->apuracao($this->entity->id, $other->id, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'));

        // Parcelas são numeradas por ato + beneficiário: a do novo médico é a 1ª dele.
        expect(releaseOf($new, $schedule)->payoutCents)->toBe(6000)
            ->and(releaseOf($new, $schedule)->tranche)->toBe(1);
    });
});

describe('integridade', function () {
    it('recebimentos de outra clínica não liberam repasse', function () {
        $schedule = releaseSchedule($this);
        $other    = Entity::factory()->create(['is_client' => true, 'active' => true]);

        FinancialCashEntry::query()->create([
            'entity_id' => $other->id, 'entry_date' => '2026-06-10', 'description' => 'Outra', 'type' => 'income',
            'status'    => 'paid', 'amount' => 500, 'reference_type' => 'schedule', 'reference_id' => $schedule->id, 'active' => true,
        ]);

        expect(releaseCompute($this)['releases'])->toBeEmpty();
    });

    it('o índice único impede duas parcelas válidas com o mesmo número para o mesmo ato', function () {
        $schedule = releaseSchedule($this);
        releaseDesk($this, $schedule, 100.00, '2026-06-10');
        $payout = releaseClose($this);
        $item   = DoctorPayoutItem::query()->where('doctor_payout_id', $payout->id)->sole();

        expect(fn () => DB::table('doctor_payout_items')->insert([
            ...collect($item->getAttributes())->except(['id', 'created_at', 'updated_at'])->all(),
            'id' => (string) Str::uuid7(), 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});
