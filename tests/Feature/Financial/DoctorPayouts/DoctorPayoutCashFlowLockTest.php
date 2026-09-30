<?php

/*
 * Repasse médico × Fluxo de caixa: a despesa gerada pelo pagamento de um
 * repasse (reference_type = doctor_payout) aparece com origem própria, fica
 * travada para edição/exclusão no caixa (só o estorno pela tela de repasse a
 * desfaz) e o índice único impede dois lançamentos ativos para o mesmo
 * fechamento.
 */

use App\Enums\{CashEntryReferenceType, FinancialEntryStatus, FinancialEntryType};
use App\Enums\DoctorPayout\DoctorPayoutStatus;
use App\Models\{DoctorPayout, DoctorPayoutPayment, Entity, FinancialCashEntry, FinancialCategory};
use Database\Seeders\FinancialCategoriesSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

function doctorPayoutCashLockPayout($test): DoctorPayout
{
    $doctor = createDoctorForEntity($test->entity);

    return DoctorPayout::query()->create([
        'entity_id'    => $test->entity->id,
        'doctor_id'    => $doctor->id,
        'doctor_name'  => 'Dra. Teste Repasse',
        'period_start' => '2026-06-01',
        'period_end'   => '2026-06-30',
        'status'       => DoctorPayoutStatus::Paid->value,
        'items_count'  => 2,
        'items_amount' => 300,
        'total_amount' => 300,
        'closed_at'    => now(),
        'paid_at'      => '2026-07-05',
        'paid_amount'  => 300,
    ]);
}

function doctorPayoutCashLockEntry($test, ?DoctorPayout $payout = null): FinancialCashEntry
{
    $payout ??= doctorPayoutCashLockPayout($test);

    return FinancialCashEntry::query()->create([
        'entity_id'      => $test->entity->id,
        'doctor_id'      => $payout->doctor_id,
        'entry_date'     => '2026-07-05',
        'description'    => 'Repasse médico',
        'type'           => FinancialEntryType::Expense->value,
        'status'         => FinancialEntryStatus::Paid->value,
        'amount'         => 300,
        'payment_method' => 'transfer',
        'reference_type' => CashEntryReferenceType::DoctorPayout->value,
        'reference_id'   => $payout->id,
        'active'         => true,
    ]);
}

/** Despesa de um pagamento (E5: reference_type doctor_payout_payment). */
function doctorPayoutCashLockPaymentEntry($test, ?DoctorPayoutPayment $payment = null): FinancialCashEntry
{
    if ($payment === null) {
        $payout  = doctorPayoutCashLockPayout($test);
        $payment = DoctorPayoutPayment::query()->create([
            'entity_id' => $test->entity->id, 'doctor_payout_id' => $payout->id, 'amount' => 300,
            'paid_at'   => '2026-07-05', 'payment_method' => 'transfer',
        ]);
    }

    return FinancialCashEntry::query()->create([
        'entity_id'      => $test->entity->id,
        'entry_date'     => '2026-07-05',
        'description'    => 'Repasse médico (pagamento)',
        'type'           => FinancialEntryType::Expense->value,
        'status'         => FinancialEntryStatus::Paid->value,
        'amount'         => 300,
        'payment_method' => 'transfer',
        'reference_type' => CashEntryReferenceType::DoctorPayoutPayment->value,
        'reference_id'   => $payment->id,
        'active'         => true,
    ]);
}

it('recusa PATCH com 422 traduzido e mantém a despesa do repasse', function () {
    $entry = doctorPayoutCashLockEntry($this);

    $this->patchJson(route('panel.financial.cash-flow.update', $entry->id), [
        'entry_date'  => '2026-07-05',
        'description' => 'Alterado',
        'type'        => 'expense',
        'status'      => 'paid',
        'amount'      => 1,
    ])->assertStatus(422)
        ->assertJsonPath('errors.reference_id.0', __('financial_cash_flow.locked_by_doctor_payout'));

    $fresh = $entry->fresh();
    expect((float) $fresh->amount)->toBe(300.0)
        ->and($fresh->description)->toBe('Repasse médico');
});

it('recusa DELETE com 422 e a despesa continua no caixa', function () {
    $entry = doctorPayoutCashLockEntry($this);

    $this->deleteJson(route('panel.financial.cash-flow.destroy', $entry->id))
        ->assertStatus(422)
        ->assertJsonPath('errors.reference_id.0', __('financial_cash_flow.locked_by_doctor_payout'));

    expect(FinancialCashEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
});

it('lista a despesa com origem e trava doctor_payout', function () {
    $entry = doctorPayoutCashLockEntry($this);

    $this->get(route('panel.financial.cash-flow.index', ['from' => '2026-07-01', 'to' => '2026-07-31']))
        ->assertOk()
        ->assertInertia(function ($page) use ($entry) {
            $page->component('Panel/Financial/CashFlow/Index')
                ->where('t.lock_doctor_payout', __('financial_cash_flow.lock_doctor_payout'))
                ->where('t.origins.doctor_payout', __('financial_cash_flow.origins.doctor_payout'));

            $row = collect($page->toArray()['props']['entries']['data'])->firstWhere('id', $entry->id);

            expect($row['origin'])->toBe('doctor_payout')
                ->and($row['lock_reason'])->toBe('doctor_payout');
        });
});

it('não permite dois lançamentos ativos para o mesmo fechamento de repasse', function () {
    $payout = doctorPayoutCashLockPayout($this);
    doctorPayoutCashLockEntry($this, $payout);

    expect(fn () => doctorPayoutCashLockEntry($this, $payout))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('libera novo lançamento depois que o anterior foi excluído (estorno)', function () {
    $payout = doctorPayoutCashLockPayout($this);
    doctorPayoutCashLockEntry($this, $payout)->delete();

    expect(doctorPayoutCashLockEntry($this, $payout)->exists)->toBeTrue();
});

it('despesa de pagamento (parcial ou total) fica travada, com origem de repasse, e é única por pagamento', function () {
    $entry   = doctorPayoutCashLockPaymentEntry($this);
    $payment = DoctorPayoutPayment::query()->findOrFail($entry->reference_id);

    $this->deleteJson(route('panel.financial.cash-flow.destroy', $entry->id))
        ->assertStatus(422)
        ->assertJsonPath('errors.reference_id.0', __('financial_cash_flow.locked_by_doctor_payout'));

    $this->get(route('panel.financial.cash-flow.index', ['from' => '2026-07-01', 'to' => '2026-07-31']))
        ->assertOk()
        ->assertInertia(function ($page) use ($entry) {
            $row = collect($page->toArray()['props']['entries']['data'])->firstWhere('id', $entry->id);

            expect($row['origin'])->toBe('doctor_payout')
                ->and($row['lock_reason'])->toBe('doctor_payout');
        });

    // Último passo: a violação do índice aborta a transação do teste.
    expect(fn () => doctorPayoutCashLockPaymentEntry($this, $payment))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('a auditoria de vínculos aceita a despesa de pagamento de repasse', function () {
    doctorPayoutCashLockPaymentEntry($this);

    $this->artisan('financial:audit-cash-references')
        ->expectsOutputToContain(__('financial_cash_flow.audit_references.none'))
        ->assertExitCode(0);
});

it('semeia a categoria global de sistema REPASSE MÉDICO (despesa)', function () {
    $categories = FinancialCategory::query()->whereNull('entity_id')->where('name', 'REPASSE MÉDICO')->get();

    expect($categories)->toHaveCount(1)
        ->and($categories->first()->type)->toBe(FinancialEntryType::Expense)
        ->and($categories->first()->is_system)->toBeTrue();
});

it('o seeder de categorias não duplica nas reexecuções e inclui REPASSE MÉDICO', function () {
    $this->seed(FinancialCategoriesSeeder::class);
    $this->seed(FinancialCategoriesSeeder::class);

    $names = FinancialCategory::query()->whereNull('entity_id')->pluck('name');

    expect($names->duplicates())->toBeEmpty()
        ->and($names)->toContain('REPASSE MÉDICO');
});

it('a auditoria de vínculos aceita a despesa de repasse legítima', function () {
    doctorPayoutCashLockEntry($this);

    $this->artisan('financial:audit-cash-references')
        ->expectsOutputToContain(__('financial_cash_flow.audit_references.none'))
        ->assertExitCode(0);
});
