<?php

/*
 * Repasse médico — recebimento MANUAL (E3): parte de receita avulsa do caixa
 * alocada a atos. Só receita paga, sem vínculo, da clínica; soma ≤ saldo;
 * ato da clínica; uma alocação por receita × ato; estorno com motivo (admin
 * ou financeiro) gera parcela negativa se já liberado; receita alocada fica
 * travada no Fluxo de Caixa.
 */

use App\DTOs\DoctorPayout\PayoutItemData;
use App\Enums\{ClientRule, ScheduleSituation};
use App\Enums\DoctorPayout\{DoctorPayoutSourceType, DoctorPayoutWarning};
use App\Models\{BillingClaim, Covenant, DoctorPayout, DoctorPayoutReceiptAllocation, DoctorPayoutRule, Entity, ExamType, FinancialCashEntry, MedicalRecord, MedicalRecordProcedure, Patient, PatientExam, Procedure, Schedule, User, VisitType};
use App\Services\Financial\CashFlowService;
use App\Services\Financial\DoctorPayouts\{DoctorPayoutCalculator, DoctorPayoutClosingService, DoctorPayoutProductionService, DoctorPayoutReceiptAllocationService};
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->adminEu  = actingAsFinancialEntityUser($this->entity);
    $this->doctor   = createDoctorForEntity($this->entity);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'ans_registry' => '326305']);
    $this->patient  = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $this->covenant->id]);

    foreach (['consultation', 'exam', 'procedure'] as $type) {
        DoctorPayoutRule::query()->create([
            'entity_id'   => $this->entity->id, 'service_type' => $type, 'payer_scope' => 'any',
            'calculation' => 'percentage', 'percentage' => '50.00', 'active' => true,
        ]);
    }

    $this->allocations = app(DoctorPayoutReceiptAllocationService::class);
    $this->calculator  = app(DoctorPayoutCalculator::class);
    $this->closing     = app(DoctorPayoutClosingService::class);
});

/** Receita avulsa (lançada à mão no caixa: sem agendamento/guia). */
function allocIncome($test, float $amount, string $date = '2026-06-20', array $attrs = []): FinancialCashEntry
{
    return FinancialCashEntry::query()->create(array_merge([
        'entity_id' => $test->entity->id, 'entry_date' => $date, 'description' => 'Depósito do convênio',
        'type'      => 'income', 'status' => 'paid', 'amount' => $amount, 'active' => true,
    ], $attrs));
}

function allocSchedule($test, array $attrs = []): Schedule
{
    static $slot = 0;
    $slot++;

    return Schedule::create(array_merge([
        'entity_id'   => $test->entity->id, 'doctor_id' => $test->doctor->id, 'patient_id' => $test->patient->id,
        'covenant_id' => $test->covenant->id, 'full_name' => 'Paciente', 'situation' => ScheduleSituation::Attended->value,
        'date_time'   => sprintf('2026-06-10 %02d:%02d:00', 7 + intdiv($slot % 600, 60), $slot % 60), 'active' => true,
    ], $attrs));
}

function allocExamKey($test): string
{
    $oct = ExamType::factory()->create(['name' => 'OCT']);

    PatientExam::factory()->create([
        'patient_id'        => $test->patient->id, 'exam_id' => $oct->id, 'doctor_id' => $test->doctor->id,
        'exam_performed_at' => '2026-06-10 11:00:00', 'source' => 'integrator', 'active' => true,
    ]);

    return DoctorPayoutSourceType::PatientExam->value . ':' . DoctorPayoutProductionService::examKey($test->patient->id, $oct->id, '2026-06-10');
}

function allocCompute($test, string $from = '2026-06-01', string $to = '2026-06-30'): array
{
    return $test->calculator->apuracao($test->entity->id, $test->doctor->id, CarbonImmutable::parse($from), CarbonImmutable::parse($to));
}

function allocRelease(array $result, string $key): ?PayoutItemData
{
    return $result['releases']->first(fn (PayoutItemData $item) => $item->key() === $key);
}

function allocClose($test, string $from = '2026-06-01', string $to = '2026-06-30'): DoctorPayout
{
    $totals = DoctorPayoutCalculator::totals(allocCompute($test, $from, $to)['releases']);

    return $test->closing->close($test->entity->id, $test->doctor->id, [
        'period_start'           => $from, 'period_end' => $to,
        'expected_count'         => $totals['count'],
        'expected_charged_cents' => $totals['charged_cents'],
        'expected_payout_cents'  => $totals['payout_cents'],
    ], null);
}

describe('alocar', function () {
    it('exame de equipamento (sem cobrança própria) libera repasse pelo recebimento manual, na data da receita', function () {
        $examKey = allocExamKey($this);

        expect(allocCompute($this)['releases'])->toBeEmpty();

        $income = allocIncome($this, 300.00, '2026-06-20');
        $this->allocations->allocate($this->entity->id, $income->id, [['key' => $examKey, 'amount_cents' => 12000]], 'Lote de exames');

        $release = allocRelease(allocCompute($this), $examKey);

        expect($release->baseCents)->toBe(12000)
            ->and($release->payoutCents)->toBe(6000)
            ->and($release->receipts[0]['kind'])->toBe('manual');

        // Receita com data depois do fim do período não entra.
        expect(allocCompute($this, '2026-06-01', '2026-06-15')['releases'])->toBeEmpty();
    });

    it('receita agregada dividida entre itens; a soma nunca passa do saldo da receita', function () {
        $income = allocIncome($this, 100.00);
        $a      = allocSchedule($this);
        $b      = allocSchedule($this);

        $this->allocations->allocate($this->entity->id, $income->id, [
            ['key' => 'schedule:' . $a->id, 'amount_cents' => 6000],
            ['key' => 'schedule:' . $b->id, 'amount_cents' => 4000],
        ], null);

        expect(fn () => $this->allocations->allocate($this->entity->id, $income->id, [['key' => 'schedule:' . allocSchedule($this)->id, 'amount_cents' => 1]], null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.allocation_exceeds', ['available' => Number::currency(0, 'BRL', app()->getLocale())]));

        expect(DoctorPayoutReceiptAllocation::query()->whereNull('reversed_at')->sum('amount'))->toEqual('100.00');
    });

    it('o mesmo item repetido na mesma alocação é recusado como duplicado (mesmo se a soma passar do saldo)', function () {
        $key = 'schedule:' . allocSchedule($this)->id;

        expect(fn () => $this->allocations->allocate($this->entity->id, allocIncome($this, 10.00)->id, [
            ['key' => $key, 'amount_cents' => 800],
            ['key' => $key, 'amount_cents' => 800],
        ], null))->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.allocation_duplicate'));

        expect(DoctorPayoutReceiptAllocation::query()->count())->toBe(0);
    });

    it('exame de agendamento de exame atendido: o recebido vai para o agendamento (o ato que a apuração considera)', function () {
        $type     = VisitType::create(['entity_id' => $this->entity->id, 'name' => 'EXAMES', 'procedure_id' => Procedure::factory()->create(['treatment' => 3])->id, 'active' => true]);
        $schedule = allocSchedule($this, ['visit_id' => $type->id]);
        $oct      = ExamType::factory()->create(['name' => 'OCT']);
        PatientExam::factory()->create([
            'patient_id'        => $this->patient->id, 'exam_id' => $oct->id, 'doctor_id' => $this->doctor->id, 'schedule_id' => $schedule->id,
            'exam_performed_at' => '2026-06-10 11:00:00', 'source' => 'integrator', 'active' => true,
        ]);
        $examKey = DoctorPayoutSourceType::PatientExam->value . ':' . DoctorPayoutProductionService::examKey($this->patient->id, $oct->id, '2026-06-10');

        $this->allocations->allocate($this->entity->id, allocIncome($this, 100.00)->id, [['key' => $examKey, 'amount_cents' => 10000]], null);

        $allocation = DoctorPayoutReceiptAllocation::query()->sole();

        expect($allocation->source_type)->toBe(DoctorPayoutSourceType::Schedule)
            ->and((string) $allocation->source_id)->toBe((string) $schedule->id)
            ->and(allocRelease(allocCompute($this), 'schedule:' . $schedule->id)?->payoutCents)->toBe(5000);
    });

    it('exame só de importação externa não é ato: alocar nele é recusado', function () {
        $oct = ExamType::factory()->create(['name' => 'OCT']);
        PatientExam::factory()->create([
            'patient_id'        => $this->patient->id, 'exam_id' => $oct->id, 'doctor_id' => $this->doctor->id,
            'exam_performed_at' => '2026-06-10 11:00:00', 'source' => 'external_import', 'active' => true,
        ]);
        $examKey = DoctorPayoutSourceType::PatientExam->value . ':' . DoctorPayoutProductionService::examKey($this->patient->id, $oct->id, '2026-06-10');

        expect(fn () => $this->allocations->allocate($this->entity->id, allocIncome($this, 100.00)->id, [['key' => $examKey, 'amount_cents' => 100]], null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.allocation_target_invalid'));
        expect(DoctorPayoutReceiptAllocation::query()->count())->toBe(0);
    });

    it('só receita paga, de receita, sem vínculo e da clínica', function (array $attrs) {
        $income = allocIncome($this, 100.00, attrs: $attrs);

        expect(fn () => $this->allocations->allocate($this->entity->id, $income->id, [['key' => 'schedule:' . allocSchedule($this)->id, 'amount_cents' => 100]], null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.allocation_entry_invalid'));
    })->with([
        'pendente'                 => [['status' => 'pending']],
        'despesa'                  => [['type' => 'expense']],
        'vinculada ao agendamento' => [['reference_type' => 'schedule']],
    ]);

    it('receita de outra clínica, receita excluída e ato de outra clínica são recusados', function () {
        $other        = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $foreignEntry = FinancialCashEntry::query()->create([
            'entity_id' => $other->id, 'entry_date' => '2026-06-20', 'description' => 'Outra', 'type' => 'income',
            'status'    => 'paid', 'amount' => 100, 'active' => true,
        ]);
        $schedule = allocSchedule($this);

        expect(fn () => $this->allocations->allocate($this->entity->id, $foreignEntry->id, [['key' => 'schedule:' . $schedule->id, 'amount_cents' => 100]], null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.allocation_entry_invalid'));

        $deleted = allocIncome($this, 100.00);
        $deleted->delete();
        expect(fn () => $this->allocations->allocate($this->entity->id, $deleted->id, [['key' => 'schedule:' . $schedule->id, 'amount_cents' => 100]], null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.allocation_entry_invalid'));

        $foreignSchedule = Schedule::create([
            'entity_id' => $other->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
            'full_name' => 'X', 'date_time' => '2026-06-11 09:00:00', 'situation' => ScheduleSituation::Attended->value, 'active' => true,
        ]);
        expect(fn () => $this->allocations->allocate($this->entity->id, allocIncome($this, 100.00)->id, [['key' => 'schedule:' . $foreignSchedule->id, 'amount_cents' => 100]], null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.allocation_target_invalid'));
    });

    it('a mesma receita não é alocada duas vezes ao mesmo item; procedimento pareado vira o atendimento', function () {
        $yag      = Procedure::factory()->create(['treatment' => 4, 'name' => 'YAG']);
        $type     = VisitType::create(['entity_id' => $this->entity->id, 'name' => 'YAG', 'procedure_id' => $yag->id, 'active' => true]);
        $schedule = allocSchedule($this, ['visit_id' => $type->id]);
        $record   = MedicalRecord::create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'schedule_id' => $schedule->id,
        ]);
        $procedure = MedicalRecordProcedure::create([
            'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'medical_record_id' => $record->id,
            'procedure_id' => $yag->id, 'doctor_id' => $this->doctor->id, 'status' => 'done', 'executed_at' => '2026-06-10 10:00:00',
            'executed_by'  => $this->doctor->entity_user_id,
        ]);
        $income = allocIncome($this, 100.00);

        $created = $this->allocations->allocate($this->entity->id, $income->id, [['key' => 'medical_record_procedure:' . $procedure->id, 'amount_cents' => 3000]], null);

        expect($created->first()->source_type)->toBe(DoctorPayoutSourceType::Schedule)
            ->and($created->first()->source_id)->toBe($schedule->id);

        expect(fn () => $this->allocations->allocate($this->entity->id, $income->id, [['key' => 'schedule:' . $schedule->id, 'amount_cents' => 1000]], null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.allocation_duplicate'));

        // O procedimento (ato do atendimento) libera sobre o recebido manual do atendimento.
        expect(allocRelease(allocCompute($this), 'medical_record_procedure:' . $procedure->id)->baseCents)->toBe(3000);
    });

    it('complemento de guia paga a menor: o depósito avulso alocado completa o recebido do atendimento', function () {
        $schedule = allocSchedule($this);
        $claim    = BillingClaim::create([
            'entity_id' => $this->entity->id, 'schedule_id' => $schedule->id, 'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id, 'covenant_id' => $this->covenant->id, 'status' => 'paid', 'paid_amount' => 180,
            'paid_at'   => '2026-06-15', 'attendance_date' => '2026-06-10', 'amount' => 200, 'quantity' => 1, 'unit_price' => 200,
        ]);
        FinancialCashEntry::query()->create([
            'entity_id'    => $this->entity->id, 'billing_claim_id' => $claim->id, 'entry_date' => '2026-06-15', 'description' => 'Guia',
            'type'         => 'income', 'status' => 'paid', 'amount' => 180, 'nature' => 'covenant', 'reference_type' => 'billing_claim',
            'reference_id' => $claim->id, 'active' => true,
        ]);
        allocClose($this); // libera 50% de 180 = 90

        $this->allocations->allocate($this->entity->id, allocIncome($this, 20.00, '2026-07-05')->id, [['key' => 'schedule:' . $schedule->id, 'amount_cents' => 2000]], 'Diferença paga à parte');

        $complement = allocRelease(allocCompute($this, '2026-07-01', '2026-07-31'), 'schedule:' . $schedule->id);

        expect($complement->tranche)->toBe(2)
            ->and($complement->receivedCents)->toBe(20000)
            ->and($complement->payoutCents)->toBe(1000);
    });
});

describe('estorno e trava no caixa', function () {
    it('estornar alocação já liberada gera parcela negativa; estornar de novo é recusado', function () {
        $examKey    = allocExamKey($this);
        $allocation = $this->allocations->allocate($this->entity->id, allocIncome($this, 100.00)->id, [['key' => $examKey, 'amount_cents' => 10000]], null)->first();
        allocClose($this);

        $this->allocations->reverse($allocation, 'Receita alocada no item errado', null);

        $reversal = allocRelease(allocCompute($this, '2026-07-01', '2026-07-31'), $examKey);

        expect($reversal->payoutCents)->toBe(-5000)
            ->and($reversal->hasWarning(DoctorPayoutWarning::NegativeAdjustment))->toBeTrue();

        expect(fn () => $this->allocations->reverse($allocation->fresh(), 'Tentativa repetida aqui', null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.allocation_already_reversed'));
    });

    it('receita com alocação válida não pode ser alterada nem excluída no caixa; depois do estorno pode', function () {
        $income     = allocIncome($this, 100.00);
        $allocation = $this->allocations->allocate($this->entity->id, $income->id, [['key' => 'schedule:' . allocSchedule($this)->id, 'amount_cents' => 5000]], null)->first();
        $cashFlow   = app(CashFlowService::class);

        expect(fn () => $cashFlow->update($income, ['amount' => 50]))
            ->toThrow(ValidationException::class, __('financial_cash_flow.locked_by_doctor_payout_allocation'));
        expect(fn () => $cashFlow->delete($income))
            ->toThrow(ValidationException::class, __('financial_cash_flow.locked_by_doctor_payout_allocation'));

        $this->allocations->reverse($allocation, 'Alocação feita por engano', null);

        expect($cashFlow->update($income->fresh(), ['description' => 'Depósito corrigido'])->description)->toBe('Depósito corrigido');
    });
});

describe('HTTP', function () {
    it('financeiro aloca e estorna com motivo; secretária não; alocação de outra clínica = 404', function () {
        $schedule = allocSchedule($this);
        $income   = allocIncome($this, 100.00);

        $financial = User::factory()->create();
        $this->actingAs($financial)->withSession(panelSession(createEntityUser($this->entity, $financial, ClientRule::Financial->value)));

        $this->from(route('panel.financial.doctor-payouts.index'))
            ->post(route('panel.financial.doctor-payouts.allocations.store'), [
                'cash_entry_id' => $income->id,
                'items'         => [['key' => 'schedule:' . $schedule->id, 'amount' => '40,00']],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $allocation = DoctorPayoutReceiptAllocation::query()->sole();
        expect((string) $allocation->amount)->toBe('40.00')
            ->and($allocation->created_by)->toBe($financial->id);

        $this->delete(route('panel.financial.doctor-payouts.allocations.destroy', $allocation->id), ['reason' => 'curto'])
            ->assertSessionHasErrors('reason');

        $this->delete(route('panel.financial.doctor-payouts.allocations.destroy', $allocation->id), ['reason' => 'Valor lançado no atendimento errado'])
            ->assertRedirect();
        expect($allocation->fresh()->reversed_by)->toBe($financial->id);

        $secretary = User::factory()->create();
        $this->actingAs($secretary)->withSession(panelSession(createEntityUser($this->entity, $secretary, ClientRule::Secretary->value)));
        $this->post(route('panel.financial.doctor-payouts.allocations.store'), [])->assertForbidden();

        // Alocação de outra clínica não resolve na rota.
        $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $foreign = DoctorPayoutReceiptAllocation::query()->create([
            'entity_id' => $other->id, 'cash_entry_id' => FinancialCashEntry::query()->create([
                'entity_id' => $other->id, 'entry_date' => '2026-06-20', 'description' => 'Outra', 'type' => 'income',
                'status'    => 'paid', 'amount' => 10, 'active' => true,
            ])->id,
            'source_type' => 'schedule', 'source_id' => (string) $schedule->id, 'amount' => '5.00',
        ]);
        $this->actingAs($financial)->withSession(panelSession(createEntityUser($this->entity, User::factory()->create(), ClientRule::Financial->value)));
        $this->delete(route('panel.financial.doctor-payouts.allocations.destroy', $foreign->id), ['reason' => 'Tentativa de outra clínica'])
            ->assertNotFound();
    });

    it('apuração traz os recebimentos manuais do item e as receitas elegíveis só quando pedidas', function () {
        $schedule = allocSchedule($this);
        $income   = allocIncome($this, 100.00, '2026-06-20');
        $this->allocations->allocate($this->entity->id, $income->id, [['key' => 'schedule:' . $schedule->id, 'amount_cents' => 4000]], null);

        $query = ['doctor' => $this->doctor->id, 'from' => '2026-06-01', 'to' => '2026-06-30'];

        $this->get(route('panel.financial.doctor-payouts.index', $query))
            ->assertInertia(fn ($page) => $page
                ->missing('manual_receipts')
                ->where('items.data.0.manual_allocations.0.amount', 40)
                ->where('items.data.0.status', 'pending')
                ->where('reason_limits.min', 10));

        $partial = $this->withHeaders(array_merge(inertiaHeaders(), [
            'X-Inertia-Partial-Component' => 'Panel/Financial/DoctorPayouts/Index',
            'X-Inertia-Partial-Data'      => 'manual_receipts',
        ]))->get(route('panel.financial.doctor-payouts.index', $query))->assertOk()->json('props');

        expect($partial['manual_receipts'][0]['id'])->toBe($income->id)
            ->and($partial['manual_receipts'][0]['remaining'])->toEqual(60);
    });
});
