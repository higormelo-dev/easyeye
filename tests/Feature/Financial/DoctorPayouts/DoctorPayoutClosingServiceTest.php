<?php

/*
 * Repasse médico — fechamento, ajustes, pagamento, estorno e reabertura
 * (DoctorPayoutClosingService): retrato dos itens, totais em centavos,
 * bloqueios, fechamento complementar, integração com o Fluxo de Caixa e a
 * serialização por médico (segunda sessão PostgreSQL segurando o lock).
 */

use App\Enums\{CashEntryReferenceType, FinancialEntryStatus, FinancialEntryType, ScheduleSituation};
use App\Enums\DoctorPayout\DoctorPayoutStatus;
use App\Models\{CashClose, Covenant, DoctorPayout, DoctorPayoutItem, DoctorPayoutPayment, DoctorPayoutRule, Entity, FinancialCashEntry, FinancialCategory, Patient, Schedule};
use App\Services\Financial\DoctorPayouts\{DoctorPayoutCalculator, DoctorPayoutClosingService, DoctorPayoutDeductionService};
use Carbon\CarbonImmutable;
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        // A base recebida também precisa bater com a conferida (regra fixa pode
        // não mudar o repasse quando o recebido muda).
        expect(fn () => payoutCloseDo($this, overrides: ['expected_charged_cents' => 1]))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.preview_changed'));

        // Recebido sem regra vigente bloqueia (cadastre a regra, pode ser R$ 0).
        DoctorPayoutRule::query()->update(['active' => false]);
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
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.reopen_has_payments'));
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
    it('pagar lança a despesa paga no caixa com categoria e referência do pagamento; quitado não aceita outro', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 250.00); // repasse 150,00
        $payout = payoutCloseDo($this);

        $paid = $this->closing->pay($payout, [
            'paid_at' => '2026-07-05', 'payment_method' => 'transfer', 'payment_notes' => 'PIX', 'amount' => '150.00', 'expected_paid_cents' => 0,
        ], null);

        $payment  = DoctorPayoutPayment::query()->where('doctor_payout_id', $payout->id)->sole();
        $entry    = FinancialCashEntry::query()->findOrFail($payment->cash_entry_id);
        $category = FinancialCategory::query()->findOrFail($entry->category_id);

        expect($paid->status)->toBe(DoctorPayoutStatus::Paid)
            ->and($paid->paid_at->toDateString())->toBe('2026-07-05')
            ->and((string) $paid->paid_amount)->toBe('150.00')
            ->and((string) $payment->amount)->toBe('150.00')
            ->and($payment->notes)->toBe('PIX')
            ->and($entry->type)->toBe(FinancialEntryType::Expense)
            ->and($entry->status)->toBe(FinancialEntryStatus::Paid)
            ->and((string) $entry->amount)->toBe('150.00')
            ->and($entry->entry_date->toDateString())->toBe('2026-07-05')
            ->and($entry->reference_type)->toBe(CashEntryReferenceType::DoctorPayoutPayment->value)
            ->and($entry->reference_id)->toBe($payment->id)
            ->and($entry->doctor_id)->toBe($this->doctor->id)
            ->and($entry->entity_id)->toBe($this->entity->id)
            ->and($category->name)->toBe('REPASSE MÉDICO');

        expect(fn () => $this->closing->pay($paid, ['paid_at' => '2026-07-06', 'payment_method' => 'cash'], null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.invalid_status'));

        expect(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->where('type', 'expense')->count())->toBe(1);
    });

    it('total zero marca pago sem lançamento no caixa', function () {
        DoctorPayoutRule::query()->update(['percentage' => '0.00']);
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00);

        $paid = $this->closing->pay(payoutCloseDo($this), ['paid_at' => '2026-07-05', 'payment_method' => 'cash'], null);

        $payment = DoctorPayoutPayment::query()->where('doctor_payout_id', $paid->id)->sole();

        expect($paid->status)->toBe(DoctorPayoutStatus::Paid)
            ->and((string) $payment->amount)->toBe('0.00')
            ->and($payment->cash_entry_id)->toBeNull()
            ->and(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->where('type', 'expense')->count())->toBe(0);
    });

    it('total zero só aceita o pagamento de valor zero', function () {
        DoctorPayoutRule::query()->update(['percentage' => '0.00']);
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00);
        $payout = payoutCloseDo($this);

        expect(fn () => $this->closing->pay($payout, ['paid_at' => '2026-07-05', 'payment_method' => 'cash', 'amount' => '10.00'], null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.payment_zero_only'));

        expect($payout->fresh()->status)->toBe(DoctorPayoutStatus::Closed);
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
        $payment = DoctorPayoutPayment::query()->where('doctor_payout_id', $paid->id)->sole();
        $entryId = $payment->cash_entry_id;

        $reversed = $this->closing->reversePayment($paid, $payment, 'Pago na conta errada', null);

        expect($reversed->status)->toBe(DoctorPayoutStatus::Closed)
            ->and($reversed->paid_amount)->toBeNull()
            ->and($reversed->paid_at)->toBeNull()
            ->and($payment->fresh()->reversal_reason)->toBe('Pago na conta errada')
            ->and($payment->fresh()->reversed_at)->not->toBeNull()
            ->and(FinancialCashEntry::query()->whereKey($entryId)->exists())->toBeFalse()
            ->and(FinancialCashEntry::withTrashed()->whereKey($entryId)->exists())->toBeTrue();

        expect(fn () => $this->closing->reversePayment($reversed, $payment, 'Estorno repetido aqui', null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.payment_already_reversed'));

        $paidAgain = $this->closing->pay($reversed, ['paid_at' => '2026-07-06', 'payment_method' => 'transfer'], null);
        $second    = DoctorPayoutPayment::query()->where('doctor_payout_id', $paidAgain->id)->whereNull('reversed_at')->sole();
        CashClose::query()->create([
            'entity_id' => $this->entity->id, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'closed_at' => now(), 'total_income' => 0, 'total_expense' => 0, 'balance' => 0,
        ]);

        expect(fn () => $this->closing->reversePayment($paidAgain, $second, 'Outro motivo qualquer', null))
            ->toThrow(ValidationException::class);
        expect($paidAgain->fresh()->status)->toBe(DoctorPayoutStatus::Paid);
    });
});

describe('pagamentos parciais', function () {
    function payoutClosePay($test, DoctorPayout $payout, string $amount, int $expectedPaidCents, string $date = '2026-07-05'): DoctorPayout
    {
        return $test->closing->pay($payout, [
            'paid_at' => $date, 'payment_method' => 'transfer', 'amount' => $amount, 'expected_paid_cents' => $expectedPaidCents,
        ], null);
    }

    it('paga em parcelas: parcial → pago em parte; o saldo → pago; uma despesa por pagamento', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 250.00); // repasse 150,00
        $payout = payoutCloseDo($this);

        $partial = payoutClosePay($this, $payout, '50.00', 0, '2026-07-05');

        expect($partial->status)->toBe(DoctorPayoutStatus::PartiallyPaid)
            ->and((string) $partial->paid_amount)->toBe('50.00')
            ->and($partial->paid_at->toDateString())->toBe('2026-07-05');

        $paid = payoutClosePay($this, $partial, '100.00', 5000, '2026-07-10');

        $payments = DoctorPayoutPayment::query()->where('doctor_payout_id', $payout->id)->orderBy('paid_at')->get();
        $entries  = FinancialCashEntry::query()
            ->where('reference_type', CashEntryReferenceType::DoctorPayoutPayment->value)
            ->orderBy('entry_date')
            ->get();

        expect($paid->status)->toBe(DoctorPayoutStatus::Paid)
            ->and((string) $paid->paid_amount)->toBe('150.00')
            ->and($paid->paid_at->toDateString())->toBe('2026-07-10')
            ->and($entries->map(fn (FinancialCashEntry $entry) => (string) $entry->amount)->all())->toBe(['50.00', '100.00'])
            ->and($entries->pluck('reference_id')->all())->toBe($payments->pluck('id')->all())
            ->and($payments->pluck('cash_entry_id')->all())->toBe($entries->pluck('id')->all());
    });

    it('valor acima do saldo, zero ou negativo é recusado e nada é lançado', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 250.00);
        $payout = payoutCloseDo($this);
        payoutClosePay($this, $payout, '100.00', 0);

        $message = __('financial_doctor_payouts.errors.payment_exceeds', ['remaining' => Number::currency(50, 'BRL', app()->getLocale())]);

        foreach (['50.01', '0', '-5'] as $amount) {
            expect(fn () => payoutClosePay($this, $payout, $amount, 10000))->toThrow(ValidationException::class, $message);
        }

        expect(DoctorPayoutPayment::query()->where('doctor_payout_id', $payout->id)->count())->toBe(1)
            ->and($payout->fresh()->status)->toBe(DoctorPayoutStatus::PartiallyPaid);
    });

    it('duplo envio (o "já pago" mudou) é recusado: um pagamento só', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 250.00);
        $payout = payoutCloseDo($this);

        payoutClosePay($this, $payout, '50.00', 0);

        expect(fn () => payoutClosePay($this, $payout, '50.00', 0))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.payment_changed'));

        expect(DoctorPayoutPayment::query()->where('doctor_payout_id', $payout->id)->count())->toBe(1)
            ->and((string) $payout->fresh()->paid_amount)->toBe('50.00');
    });

    it('estorna um pagamento de cada vez: pago → pago em parte → fechado; reabrir e ajustar só sem pagamentos', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 250.00);
        $payout = payoutCloseDo($this);
        payoutClosePay($this, $payout, '50.00', 0);
        $paid = payoutClosePay($this, $payout, '100.00', 5000);

        [$first, $second] = DoctorPayoutPayment::query()->where('doctor_payout_id', $payout->id)->orderBy('amount')->get()->all();

        $afterFirst = $this->closing->reversePayment($paid, $first, 'Parcela lançada errada', null);

        expect($afterFirst->status)->toBe(DoctorPayoutStatus::PartiallyPaid)
            ->and((string) $afterFirst->paid_amount)->toBe('100.00')
            ->and(FinancialCashEntry::query()->whereKey($first->cash_entry_id)->exists())->toBeFalse()
            ->and(FinancialCashEntry::query()->whereKey($second->cash_entry_id)->exists())->toBeTrue();

        expect(fn () => $this->closing->reopen($afterFirst, 'Tentativa com pagamento', null))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.reopen_has_payments'));
        expect(fn () => $this->closing->addAdjustment($afterFirst, 'Ajuste tardio', 1000))
            ->toThrow(ValidationException::class, __('financial_doctor_payouts.errors.invalid_status'));

        $closed = $this->closing->reversePayment($afterFirst, $second, 'Pagamento em duplicidade', null);

        expect($closed->status)->toBe(DoctorPayoutStatus::Closed)
            ->and($closed->paid_amount)->toBeNull()
            ->and($this->closing->reopen($closed, 'Refazer com a regra certa', null)->status)->toBe(DoctorPayoutStatus::Cancelled);
    });

    it('pagamento de outro fechamento não é estornado por este (404)', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 250.00);
        $june = payoutCloseDo($this);
        payoutClosePay($this, $june, '50.00', 0);
        $payment = DoctorPayoutPayment::query()->where('doctor_payout_id', $june->id)->sole();

        payoutCloseSchedule($this, '2026-07-02 09:00:00', 100.00);
        $july = payoutCloseDo($this, '2026-07-01', '2026-07-31');

        expect(fn () => $this->closing->reversePayment($july, $payment, 'Pagamento de outro mês', null))
            ->toThrow(NotFoundHttpException::class);

        expect($payment->fresh()->reversed_at)->toBeNull();
    });

    it('reverter a migration com pagamento parcial registrado é recusado (nada é apagado)', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 250.00);
        $payout = payoutCloseDo($this);
        payoutClosePay($this, $payout, '50.00', 0);

        $migration = require database_path('migrations/2026_09_29_130000_create_doctor_payout_payments_table.php');

        expect(fn () => $migration->down())->toThrow(RuntimeException::class);
        expect(DoctorPayoutPayment::query()->where('doctor_payout_id', $payout->id)->count())->toBe(1);
    });

    it('backfill: pago sem data nem valor (anomalia antiga) vira pagamento do total na data da última alteração', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 250.00);
        $payout = payoutCloseDo($this);

        DB::table('doctor_payouts')->where('id', $payout->id)->update([
            'status' => 'paid', 'paid_at' => null, 'paid_amount' => null, 'updated_at' => '2026-07-08 10:00:00',
        ]);

        (require database_path('migrations/2026_09_29_130000_create_doctor_payout_payments_table.php'))->backfill();

        $payment = DoctorPayoutPayment::query()->where('doctor_payout_id', $payout->id)->sole();

        expect((string) $payment->amount)->toBe('150.00')
            ->and($payment->paid_at->toDateString())->toBe('2026-07-08')
            ->and($payment->cash_entry_id)->toBeNull();

        // Deixa de ficar preso: dá para estornar (não há despesa) e reabrir.
        $reversed = $this->closing->reversePayment($payout->fresh(), $payment, 'Pagamento sem registro', null);
        expect($reversed->status)->toBe(DoctorPayoutStatus::Closed);
    });

    it('backfill: fechamento pago antes desta etapa vira um pagamento (idempotente) e estorna pelo fluxo novo', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 250.00);
        $payout = payoutCloseDo($this);

        // Como o regime anterior gravava: fechamento pago + despesa `doctor_payout`.
        $entry = FinancialCashEntry::query()->create([
            'entity_id'      => $this->entity->id, 'doctor_id' => $this->doctor->id, 'entry_date' => '2026-07-05',
            'description'    => 'Repasse', 'type' => 'expense', 'status' => 'paid', 'amount' => 150, 'payment_method' => 'transfer',
            'reference_type' => CashEntryReferenceType::DoctorPayout->value, 'reference_id' => $payout->id, 'active' => true,
        ]);
        $payout->forceFill([
            'status'        => 'paid', 'paid_at' => '2026-07-05', 'paid_amount' => 150, 'payment_method' => 'transfer',
            'payment_notes' => 'TED', 'cash_entry_id' => $entry->id,
        ])->save();

        $migration = require database_path('migrations/2026_09_29_130000_create_doctor_payout_payments_table.php');
        $migration->backfill();
        $migration->backfill();

        $payment = DoctorPayoutPayment::query()->where('doctor_payout_id', $payout->id)->sole();

        expect((string) $payment->amount)->toBe('150.00')
            ->and($payment->paid_at->toDateString())->toBe('2026-07-05')
            ->and($payment->notes)->toBe('TED')
            ->and($payment->cash_entry_id)->toBe($entry->id);

        $reversed = $this->closing->reversePayment($payout->fresh(), $payment, 'Estorno do pagamento antigo', null);

        expect($reversed->status)->toBe(DoctorPayoutStatus::Closed)
            ->and($reversed->cash_entry_id)->toBeNull()
            ->and(FinancialCashEntry::query()->whereKey($entry->id)->exists())->toBeFalse();
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

    function payoutCloseConfigKey(string $entityId): int
    {
        return (int) hexdec(substr(sha1('doctor_payout_rules|' . $entityId), 0, 15));
    }

    it('fechar espera quem está editando a configuração (regras, participantes, taxas)', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00);

        $probe = payoutCloseProbe();

        try {
            // "Outra requisição" salvando regra/taxa segura o lock exclusivo.
            $probe->statement("set local lock_timeout = '2s'");
            $probe->select('select pg_advisory_xact_lock(?)', [payoutCloseConfigKey($this->entity->id)]);

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

    it('editar taxa espera fechamento em andamento; fechamentos não se bloqueiam entre si', function () {
        payoutCloseSchedule($this, '2026-06-02 09:00:00', 100.00);

        $probe = payoutCloseProbe();

        try {
            // "Outra requisição" fechando (outro médico) segura o lock COMPARTILHADO.
            $probe->statement("set local lock_timeout = '2s'");
            $probe->select('select pg_advisory_xact_lock_shared(?)', [payoutCloseConfigKey($this->entity->id)]);

            DB::statement("set local lock_timeout = '300ms'");

            expect(fn () => app(DoctorPayoutDeductionService::class)->create($this->entity->id, [
                'kind' => 'tax', 'percentage' => '6.00', 'valid_from' => '2026-01-01',
            ]))->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));

            // Fechar também toma o compartilhado: segue sem esperar.
            expect(payoutCloseDo($this)->status)->toBe(DoctorPayoutStatus::Closed);
        } finally {
            if ($probe->transactionLevel() > 0) {
                $probe->rollBack();
            }

            DB::purge(PAYOUT_CLOSE_PROBE_CONNECTION);
        }
    });
});
