<?php

use App\Enums\{BillingClaimStatus, CashEntryNature, PaymentMethod};
use App\Models\{BillingClaim, Covenant, Entity, FinancialCashEntry};
use App\Services\Financial\BillingService;

beforeEach(function () {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
    $this->service  = app(BillingService::class);
});

function makeClaim($test): BillingClaim
{
    return BillingClaim::create([
        'entity_id'       => $test->entity->id,
        'covenant_id'     => $test->covenant->id,
        'status'          => BillingClaimStatus::Submitted->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 250.00,
        'quantity'        => 1,
        'unit_price'      => 250.00,
    ]);
}

describe('BillingService::markClaimPaid -> lançamento de caixa', function () {
    it('cria a entrada com payment_method válido (legível como enum, sem ValueError)', function () {
        $claim = makeClaim($this);

        $this->service->markClaimPaid($claim);

        $entry = FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->firstOrFail();

        // O cast não deve lançar e o método deve resolver para o enum Transfer.
        expect($entry->payment_method)->toBe(PaymentMethod::Transfer)
            ->and($entry->nature)->toBe(CashEntryNature::Covenant)
            ->and((float) $entry->amount)->toBe(250.00)
            ->and($entry->reference_type)->toBe('billing_claim');
    });

    it('não duplica o lançamento ao marcar pago novamente', function () {
        $claim = makeClaim($this);

        $this->service->markClaimPaid($claim);
        $this->service->markClaimPaid($claim->fresh());

        expect(FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->count())->toBe(1);
    });
});
