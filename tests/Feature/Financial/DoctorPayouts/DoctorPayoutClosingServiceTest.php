<?php

/*
 * Repasse médico — fechamento, ajustes, pagamento, estorno e reabertura
 * (DoctorPayoutClosingService): retrato dos itens, totais em centavos,
 * bloqueios, fechamento complementar, integração com o Fluxo de Caixa e a
 * serialização por médico (segunda sessão PostgreSQL segurando o lock).
 */

use App\Enums\{CashEntryReferenceType, FinancialEntryStatus, FinancialEntryType, ScheduleSituation};
use App\Enums\DoctorPayout\DoctorPayoutStatus;
use App\Models\{CashClose, Covenant, DoctorPayout, DoctorPayoutItem, DoctorPayoutRule, Entity, ExamType, FinancialCashEntry, FinancialCategory, Patient, PatientExam, Schedule};
use App\Services\Financial\DoctorPayouts\{DoctorPayoutCalculator, DoctorPayoutClosingService};
use Carbon\CarbonImmutable;
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

const PAYOUT_CLOSE_PROBE_CONNECTION = 'pgsql_lock_probe_doctor_payout';

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor   = createDoctorForEntity($this->entity);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true, 'ans_registry' => '326305']);
    $this->patient  = Patient::factory()->create(['entity_id' => $this->entity->id, 'covenant_id' => $this->covenant->id]);

    DoctorPayoutRule::query()->create([
        'entity_id'   => $this->entity->id, 'service_type' => 'consultation', 'payer_scope' => 'any',
        'calculation' => 'percentage', 'percentage' => '60.00', 'active' => true,
    ]);

    $this->closing    = app(DoctorPayoutClosingService::class);
    $this->calculator = app(DoctorPayoutCalculator::class);
});

function payoutCloseSchedule($test, string $dateTime, ?float $cash = null): Schedule
{
    $schedule = Schedule::create([
        'entity_id'   => $test->entity->id, 'doctor_id' => $test->doctor->id, 'patient_id' => $test->patient->id,
        'covenant_id' => $test->covenant->id, 'full_name' => 'Paciente', 'date_time' => $dateTime,
        'situation'   => ScheduleSituation::Attended->value, 'active' => true,
    ]);

    if ($cash !== null) {
        FinancialCashEntry::query()->create([
            'entity_id'    => $test->entity->id, 'entry_date' => substr($dateTime, 0, 10), 'description' => 'Recebimento',
            'type'         => 'income', 'status' => 'paid', 'amount' => $cash, 'reference_type' => 'schedule',
            'reference_id' => $schedule->id, 'active' => true,
        ]);
    }

    return $schedule;
}

/** Fecha como a tela: prévia calculada → fechamento com os números conferidos. */
function payoutCloseDo($test, string $from = '2026-06-01', string $to = '2026-06-30', array $overrides = []): DoctorPayout
{
    $totals = DoctorPayoutCalculator::totals(
        $test->calculator->pending($test->entity->id, $test->doctor->id, CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
    );

    return $test->closing->close($test->entity->id, $test->doctor->id, array_merge([
        'period_start'           => $from,
        'period_end'             => $to,
        'expected_count'         => $totals['count'],
        'expected_charged_cents' => $totals['charged_cents'],
        'expected_payout_cents'  => $totals['payout_cents'],
        'notes'                  => null,
    ], $overrides), null);
}

function payoutClosePending($test, string $from = '2026-06-01', string $to = '2026-06-30')
{
    return $test->calculator->pending($test->entity->id, $test->doctor->id, CarbonImmutable::parse($from), CarbonImmutable::parse($to));
}

describe('fechar', function () {
    it('grava o retrato dos itens e os totais em centavos; a produção pendente esvazia', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 150.00);
        payoutCloseSchedule($this, '2026-06-03 09:00:00', 99.99);

        $payout = payoutCloseDo($this, overrides: ['notes' => 'Junho']);

        expect($payout->code)->toStartWith('REP-')
            ->and($payout->status)->toBe(DoctorPayoutStatus::Closed)
            ->and($payout->items_count)->toBe(2)
            ->and((string) $payout->gross_amount)->toBe('249.99')
            ->and((string) $payout->items_amount)->toBe('149.99') // 90,00 + 59,99 (59,994 → 59,99)
            ->and((string) $payout->total_amount)->toBe('149.99')
            ->and($payout->doctor_name)->not->toBeEmpty()
            ->and($payout->notes)->toBe('Junho');

        $items = DoctorPayoutItem::query()->where('doctor_payout_id', $payout->id)->orderBy('performed_at')->get();
        expect($items)->toHaveCount(2)
            ->and((string) $items[0]->rule_percentage)->toBe('60.00')
            ->and((string) $items[1]->payout_amount)->toBe('59.99')
            ->and($items->every(fn ($i) => $i->voided_at === null))->toBeTrue();

        expect(payoutClosePending($this))->toBeEmpty();
    });

    it('recusa com item sem regra, sem itens ou com prévia divergente', function () {
        expect(fn () => payoutCloseDo($this))->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.nothing_to_close'));

        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00);
        expect(fn () => payoutCloseDo($this, overrides: ['expected_payout_cents' => 1]))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.preview_changed'));

        // Regra fixa: o cobrado pode mudar sem mudar o repasse — ainda assim o
        // demonstrativo precisa refletir o que foi conferido.
        expect(fn () => payoutCloseDo($this, overrides: ['expected_charged_cents' => 1]))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.preview_changed'));

        PatientExam::factory()->create([
            'patient_id' => $this->patient->id, 'exam_id' => ExamType::factory()->create()->id,
            'doctor_id'  => $this->doctor->id, 'exam_performed_at' => '2026-06-05 10:00:00', 'source' => 'integrator',
        ]);
        expect(fn () => payoutCloseDo($this))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.blocked_no_rule', ['count' => 1]));

        expect(DoctorPayout::query()->count())->toBe(0);
    });

    it('fechamento complementar pega o atendimento lançado depois, sem repetir os já fechados', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00);
        $first = payoutCloseDo($this);

        payoutCloseSchedule($this, '2026-06-05 09:00:00', 200.00); // marcado como atendido depois do fechamento

        $late = payoutClosePending($this);
        expect($late)->toHaveCount(1)
            ->and($late->first()->warnings)->not->toBeEmpty();

        $complement = payoutCloseDo($this);

        expect($complement->id)->not->toBe($first->id)
            ->and($complement->items_count)->toBe(1)
            ->and((string) $complement->total_amount)->toBe('120.00');
    });
});

describe('reabrir', function () {
    it('cancela, anula os itens e devolve à produção pendente; fechamento pago não reabre', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00);
        $payout = payoutCloseDo($this);

        $reopened = $this->closing->reopen($payout, 'Regra errada no fechamento', null);

        expect($reopened->status)->toBe(DoctorPayoutStatus::Cancelled)
            ->and($reopened->cancel_reason)->toBe('Regra errada no fechamento')
            ->and(DoctorPayoutItem::query()->where('doctor_payout_id', $payout->id)->whereNull('voided_at')->count())->toBe(0)
            ->and(payoutClosePending($this))->toHaveCount(1);

        $again = payoutCloseDo($this);
        $this->closing->pay($again, ['paid_at' => '2026-07-05', 'payment_method' => 'transfer'], null);

        expect(fn () => $this->closing->reopen($again->fresh(), 'Tentativa indevida', null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.invalid_status'));
    });
});

describe('ajustes', function () {
    it('acréscimo e desconto alteram o total; negativo é recusado; pago não aceita ajuste', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00); // repasse 60,00
        $payout = payoutCloseDo($this);

        $this->closing->addAdjustment($payout, 'Bônus', 1000);
        $this->closing->addAdjustment($payout, 'Adiantamento', -2500);

        $payout->refresh();
        expect((string) $payout->adjustments_amount)->toBe('-15.00')
            ->and((string) $payout->total_amount)->toBe('45.00');

        expect(fn () => $this->closing->addAdjustment($payout, 'Desconto grande', -5000))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.net_negative'));

        $bonus = $payout->adjustments()->where('description', 'Bônus')->firstOrFail();
        $this->closing->removeAdjustment($payout, $bonus);
        expect((string) $payout->fresh()->total_amount)->toBe('35.00');

        $this->closing->pay($payout->fresh(), ['paid_at' => '2026-07-05', 'payment_method' => 'transfer'], null);

        expect(fn () => $this->closing->addAdjustment($payout->fresh(), 'Depois de pago', 100))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.invalid_status'));
    });
});

describe('pagar e estornar', function () {
    it('pagar lança a despesa paga no caixa com categoria e referência do repasse; pagar de novo é recusado', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 250.00); // repasse 150,00
        $payout = payoutCloseDo($this);

        $paid = $this->closing->pay($payout, ['paid_at' => '2026-07-05', 'payment_method' => 'transfer', 'payment_notes' => 'PIX'], null);

        $entry    = FinancialCashEntry::query()->findOrFail($paid->cash_entry_id);
        $category = FinancialCategory::query()->findOrFail($entry->category_id);

        expect($paid->status)->toBe(DoctorPayoutStatus::Paid)
            ->and($paid->paid_at->toDateString())->toBe('2026-07-05')
            ->and((string) $paid->paid_amount)->toBe('150.00')
            ->and($entry->type)->toBe(FinancialEntryType::Expense)
            ->and($entry->status)->toBe(FinancialEntryStatus::Paid)
            ->and((string) $entry->amount)->toBe('150.00')
            ->and($entry->entry_date->toDateString())->toBe('2026-07-05')
            ->and($entry->reference_type)->toBe(CashEntryReferenceType::DoctorPayout->value)
            ->and($entry->reference_id)->toBe($payout->id)
            ->and($entry->doctor_id)->toBe($this->doctor->id)
            ->and($entry->entity_id)->toBe($this->entity->id)
            ->and($category->name)->toBe('REPASSE MÉDICO');

        expect(fn () => $this->closing->pay($paid, ['paid_at' => '2026-07-06', 'payment_method' => 'cash'], null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.invalid_status'));

        expect(FinancialCashEntry::query()->where('reference_id', $payout->id)->count())->toBe(1);
    });

    it('total zero marca pago sem lançamento no caixa', function () {
        DoctorPayoutRule::query()->update(['percentage' => '0.00']);
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00);

        $paid = $this->closing->pay(payoutCloseDo($this), ['paid_at' => '2026-07-05', 'payment_method' => 'cash'], null);

        expect($paid->status)->toBe(DoctorPayoutStatus::Paid)
            ->and($paid->cash_entry_id)->toBeNull()
            ->and(FinancialCashEntry::query()->where('reference_type', 'doctor_payout')->count())->toBe(0);
    });

    it('pagamento com data em caixa fechado é recusado e nada muda', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00);
        $payout = payoutCloseDo($this);

        CashClose::query()->create([
            'entity_id' => $this->entity->id, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'closed_at' => now(), 'total_income' => 0, 'total_expense' => 0, 'balance' => 0,
        ]);

        expect(fn () => $this->closing->pay($payout, ['paid_at' => '2026-07-05', 'payment_method' => 'transfer'], null))
            ->toThrow(ValidationException::class);

        expect($payout->fresh()->status)->toBe(DoctorPayoutStatus::Closed)
            ->and(FinancialCashEntry::query()->where('reference_type', 'doctor_payout')->count())->toBe(0);
    });

    it('estorno remove a despesa e volta para Fechado; com o caixa do pagamento fechado é recusado', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00);
        $paid    = $this->closing->pay(payoutCloseDo($this), ['paid_at' => '2026-07-05', 'payment_method' => 'transfer'], null);
        $entryId = $paid->cash_entry_id;

        $reversed = $this->closing->reversePayment($paid, 'Pago na conta errada', null);

        expect($reversed->status)->toBe(DoctorPayoutStatus::Closed)
            ->and($reversed->cash_entry_id)->toBeNull()
            ->and($reversed->paid_at)->toBeNull()
            ->and($reversed->payment_reversal_reason)->toBe('Pago na conta errada')
            ->and(FinancialCashEntry::query()->whereKey($entryId)->exists())->toBeFalse()
            ->and(FinancialCashEntry::withTrashed()->whereKey($entryId)->exists())->toBeTrue();

        $paidAgain = $this->closing->pay($reversed, ['paid_at' => '2026-07-06', 'payment_method' => 'transfer'], null);
        CashClose::query()->create([
            'entity_id' => $this->entity->id, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'closed_at' => now(), 'total_income' => 0, 'total_expense' => 0, 'balance' => 0,
        ]);

        expect(fn () => $this->closing->reversePayment($paidAgain, 'Outro motivo qualquer', null))
            ->toThrow(ValidationException::class);
        expect($paidAgain->fresh()->status)->toBe(DoctorPayoutStatus::Paid);
    });
});

describe('concorrência', function () {
    function payoutCloseProbe(): Connection
    {
        config(['database.connections.' . PAYOUT_CLOSE_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

        $probe = DB::connection(PAYOUT_CLOSE_PROBE_CONNECTION);
        $probe->beginTransaction();

        return $probe;
    }

    it('fechar espera o lock do médico: dois fechamentos do mesmo médico nunca se cruzam', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00);

        $probe = payoutCloseProbe();

        try {
            // "Outra requisição" fechando o mesmo médico segura o lock da transação.
            $probe->statement("set local lock_timeout = '2s'");
            $probe->select('select pg_advisory_xact_lock(?)', [
                (int) hexdec(substr(sha1("doctor_payout|{$this->entity->id}|{$this->doctor->id}"), 0, 15)),
            ]);

            DB::statement("set local lock_timeout = '300ms'");

            expect(fn () => payoutCloseDo($this))
                ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));
        } finally {
            if ($probe->transactionLevel() > 0) {
                $probe->rollBack();
            }

            DB::purge(PAYOUT_CLOSE_PROBE_CONNECTION);
        }

        expect(DoctorPayout::query()->count())->toBe(0);
    });
});
