<?php

use App\Enums\PaymentMethod;
use App\Models\{Entity, FinancialCashEntry};

beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
});

function makeEntry(array $attrs = []): FinancialCashEntry
{
    return FinancialCashEntry::create(array_merge([
        'entity_id'   => test()->entity->id,
        'entry_date'  => now()->toDateString(),
        'description' => 'Teste',
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => 100.00,
    ], $attrs));
}

describe('PaymentMethodCast (tolerante)', function () {
    it('lê valor legado desconhecido como null, sem lançar ValueError', function () {
        // 'transferencia' (legado do BillingService) não é um case do enum.
        $entry = makeEntry(['payment_method' => 'transferencia']);

        // Releitura do banco para forçar o cast no get().
        expect(FinancialCashEntry::findOrFail($entry->id)->payment_method)->toBeNull();
    });

    it('lê e grava um valor válido do enum corretamente', function () {
        $entry = makeEntry(['payment_method' => PaymentMethod::Transfer->value]);

        expect(FinancialCashEntry::findOrFail($entry->id)->payment_method)
            ->toBe(PaymentMethod::Transfer);
    });

    it('aceita instância do enum na escrita', function () {
        $entry = makeEntry(['payment_method' => PaymentMethod::CreditCash]);

        expect(FinancialCashEntry::findOrFail($entry->id)->payment_method)
            ->toBe(PaymentMethod::CreditCash);
    });
});
