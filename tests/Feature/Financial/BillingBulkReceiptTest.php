<?php

declare(strict_types=1);

use App\Enums\{BillingBatchStatus, BillingClaimStatus, CashEntryReferenceType, FinancialEntryType, PaymentMethod};
use App\Models\{BillingBatch, BillingClaim, CashClose, Covenant, Entity, FinancialCashEntry};
use App\Services\Financial\BillingBulkReceiptService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Fase 4 (parte 2) — recebimento em massa: as guias selecionadas na aba
 * Guias (valor por guia) e o lote inteiro (valor a receber de cada guia
 * pagável). Uma transação, as mesmas regras do recebimento individual por
 * guia, UM lançamento de caixa por guia, idempotente (guia já paga é
 * ignorada e informada) e tudo ou nada (422 com o código e o motivo de cada
 * guia, nada gravado). Locks/concorrência: BillingBulkLockTest.
 */

beforeEach(function (): void {
    $this->entity   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'active' => true]);
    actingAsFinancialEntityUser($this->entity);
});

/** @param array<string, mixed> $overrides */
function bbrClaim(Entity $entity, Covenant $covenant, array $overrides = []): BillingClaim
{
    return BillingClaim::query()->create(array_merge([
        'entity_id'       => $entity->id,
        'covenant_id'     => $covenant->id,
        'status'          => BillingClaimStatus::Submitted->value,
        'attendance_date' => now()->toDateString(),
        'amount'          => 200.00,
        'quantity'        => 1,
        'unit_price'      => 200.00,
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function bbrBatch(Entity $entity, Covenant $covenant, array $overrides = []): BillingBatch
{
    return BillingBatch::query()->create(array_merge([
        'entity_id'    => $entity->id,
        'covenant_id'  => $covenant->id,
        'status'       => BillingBatchStatus::Submitted->value,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end'   => now()->toDateString(),
        'issued_at'    => now(),
        'submitted_at' => now(),
    ], $overrides));
}

/**
 * @param list<array{0: BillingClaim, 1: float|int|string}> $pairs     guia → valor
 * @param array<string, mixed>                              $overrides
 */
function bbrPay(array $pairs, array $overrides = []): TestResponse
{
    return test()->postJson(route('panel.financial.billing.claims.bulk-receipt'), array_merge([
        'paid_at'        => now()->subDay()->toDateString(),
        'payment_method' => PaymentMethod::Transfer->value,
        'items'          => array_map(fn (array $pair): array => ['claim_id' => $pair[0]->id, 'paid_amount' => $pair[1]], $pairs),
    ], $overrides));
}

function bbrEntries(BillingClaim $claim): int
{
    return FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->count();
}

describe('guias selecionadas', function (): void {
    it('paga todas numa transação: status, valor de cada guia e UM lançamento de caixa por guia (mesmas regras do individual)', function (): void {
        $batch = bbrBatch($this->entity, $this->covenant);
        $a     = bbrClaim($this->entity, $this->covenant, ['batch_id' => $batch->id]);
        // Glosada parcial (a receber 200); receber 250 reverte parte da glosa, como no individual.
        $b      = bbrClaim($this->entity, $this->covenant, ['batch_id' => $batch->id, 'status' => BillingClaimStatus::Denied->value, 'amount' => 300, 'unit_price' => 300, 'glosa_amount' => 100]);
        $single = bbrClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Draft->value]); // individual sem lote

        $response = bbrPay([[$a, 200], [$b, 250], [$single, 120.5]], ['payment_method' => PaymentMethod::Cash->value, 'notes' => 'Depósito do convênio'])
            ->assertOk()
            ->assertJsonPath('total_paid', 570.5)
            ->assertJsonPath('message', __('financial_billing.receipt_result.paid', ['count' => 3]))
            ->assertJsonCount(3, 'paid')
            ->assertJsonCount(0, 'skipped');

        expect(collect($response->json('paid'))->pluck('paid_amount', 'claim_id')->all())
            ->toEqual([$a->id => 200.0, $b->id => 250.0, $single->id => 120.5]);

        foreach ([[$a, 200.0], [$b, 250.0], [$single, 120.5]] as [$claim, $amount]) {
            $claim->refresh();
            $entry = FinancialCashEntry::query()->where('billing_claim_id', $claim->id)->sole();

            expect($claim->status)->toBe(BillingClaimStatus::Paid)
                ->and((float) $claim->paid_amount)->toBe($amount)
                ->and($claim->paid_at->toDateString())->toBe(now()->subDay()->toDateString())
                ->and((float) $entry->amount)->toBe($amount)
                ->and($entry->type)->toBe(FinancialEntryType::Income)
                ->and($entry->entry_date->toDateString())->toBe(now()->subDay()->toDateString())
                ->and($entry->payment_method)->toBe(PaymentMethod::Cash)
                ->and($entry->reference_type)->toBe(CashEntryReferenceType::BillingClaim->value)
                ->and((string) $entry->reference_id)->toBe((string) $claim->id)
                ->and($entry->notes)->toBe('Depósito do convênio')
                ->and((string) $entry->entity_id)->toBe((string) $this->entity->id);
        }

        expect((float) $b->fresh()->glosa_amount)->toBe(50.0);
    });

    it('idempotente: reenviar o mesmo pedido ignora as guias já pagas, informa e não duplica o caixa', function (): void {
        $a = bbrClaim($this->entity, $this->covenant);
        $b = bbrClaim($this->entity, $this->covenant);

        bbrPay([[$a, 200], [$b, 200]])->assertOk();

        $again = bbrPay([[$a, 200], [$b, 200]])
            ->assertOk()
            ->assertJsonPath('message', __('financial_billing.receipt_result.none'))
            ->assertJsonCount(0, 'paid')
            ->assertJsonCount(2, 'skipped')
            ->assertJsonPath('total_paid', 0);

        expect(collect($again->json('skipped'))->pluck('message')->all())
            ->toContain(__('financial_billing.receipt_result.skipped_item', ['code' => $a->code]))
            ->and(bbrEntries($a))->toBe(1)
            ->and(bbrEntries($b))->toBe(1);

        // Mistura: uma nova + uma já paga → paga a nova e informa a ignorada.
        $c = bbrClaim($this->entity, $this->covenant);

        bbrPay([[$a, 200], [$c, 200]])
            ->assertOk()
            ->assertJsonPath('message', __('financial_billing.receipt_result.paid_skipped', ['count' => 1, 'skipped' => 1]));

        expect(bbrEntries($c))->toBe(1)->and(bbrEntries($a))->toBe(1);
    });

    it('tudo ou nada: uma guia que não pode ser paga → 422 com o código e o motivo de cada uma e nada gravado', function (): void {
        $draftLot  = bbrBatch($this->entity, $this->covenant, ['status' => BillingBatchStatus::Draft->value, 'submitted_at' => null]);
        $ok        = bbrClaim($this->entity, $this->covenant);
        $cancelled = bbrClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Cancelled->value]);
        $unsent    = bbrClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Draft->value, 'batch_id' => $draftLot->id]);

        $errors = bbrPay([[$ok, 200], [$cancelled, 200], [$unsent, 200]])->assertStatus(422)->json('errors');

        expect($errors["claims.{$cancelled->id}"][0])->toBe(__('financial_billing.errors.claim_cancelled', ['code' => $cancelled->code]))
            ->and($errors["claims.{$unsent->id}"][0])->toBe(__('financial_billing.errors.claim_batch_not_submitted', ['code' => $unsent->code]))
            ->and($errors)->not->toHaveKey("claims.{$ok->id}")
            ->and($ok->fresh()->status)->toBe(BillingClaimStatus::Submitted)
            ->and(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->count())->toBe(0);
    });

    it('receita já lançada no caixa (legado) bloqueia a guia: 422 e nada gravado', function (): void {
        $ok       = bbrClaim($this->entity, $this->covenant);
        $launched = bbrClaim($this->entity, $this->covenant, ['status' => BillingClaimStatus::Denied->value, 'glosa_amount' => 50]);
        FinancialCashEntry::query()->create([
            'entity_id'   => $this->entity->id, 'billing_claim_id' => $launched->id, 'entry_date' => now()->toDateString(),
            'description' => 'Recebimento antigo', 'type' => FinancialEntryType::Income->value, 'status' => 'paid', 'amount' => 150, 'active' => true,
        ]);

        $errors = bbrPay([[$ok, 200], [$launched, 150]])->assertStatus(422)->json('errors');

        expect($errors["claims.{$launched->id}"][0])->toBe(__('financial_billing.errors.claim_has_cash_entry', ['code' => $launched->code]))
            ->and(bbrEntries($ok))->toBe(0);
    });

    it('data com caixa fechado → 422 no campo da data, nada gravado; data futura → 422', function (): void {
        $a = bbrClaim($this->entity, $this->covenant);
        $b = bbrClaim($this->entity, $this->covenant);

        CashClose::query()->create([
            'entity_id'    => $this->entity->id,
            'period_start' => now()->subDays(3)->toDateString(),
            'period_end'   => now()->subDay()->toDateString(),
            'closed_at'    => now(),
        ]);

        bbrPay([[$a, 200], [$b, 200]], ['paid_at' => now()->subDays(2)->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('errors.paid_at.0', __('financial_billing.errors.paid_at_closed_period', ['date' => now()->subDays(2)->isoFormat('L')]));

        bbrPay([[$a, 200]], ['paid_at' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('errors.paid_at.0', __('financial_billing.errors.paid_at_future'));

        expect($a->fresh()->status)->toBe(BillingClaimStatus::Submitted)
            ->and($b->fresh()->status)->toBe(BillingClaimStatus::Submitted)
            ->and(FinancialCashEntry::query()->where('entity_id', $this->entity->id)->count())->toBe(0);
    });

    it('valor acima do valor da guia, zero ou forma inválida → 422 no item/campo', function (): void {
        $a = bbrClaim($this->entity, $this->covenant);
        $b = bbrClaim($this->entity, $this->covenant);

        bbrPay([[$a, 200], [$b, 200.01]])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['items.1.paid_amount' => [__('financial_billing.errors.bulk_paid_amount_exceeds_claim', ['code' => $b->code])]]);

        bbrPay([[$a, 0]])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['items.0.paid_amount' => [__('financial_billing.validation.paid_amount')]]);

        // Cortesia (R$ 0) fica fora das formas do recebimento, como no individual.
        bbrPay([[$a, 200]], ['payment_method' => PaymentMethod::Courtesy->value])
            ->assertStatus(422)
            ->assertJsonPath('errors.payment_method.0', __('financial_billing.errors.payment_method_invalid'));

        expect(bbrEntries($a))->toBe(0)->and(bbrEntries($b))->toBe(0);
    });

    it('teto de guias por requisição → 422 sem consultar guia nenhuma', function (): void {
        $items = collect(range(1, BillingBulkReceiptService::MAX_CLAIMS + 1))
            ->map(fn () => ['claim_id' => (string) Str::uuid(), 'paid_amount' => 10])
            ->all();

        $this->postJson(route('panel.financial.billing.claims.bulk-receipt'), [
            'paid_at' => now()->toDateString(), 'payment_method' => 'transfer', 'items' => $items,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.items.0', __('financial_billing.validation.claims_max', ['max' => BillingBulkReceiptService::MAX_CLAIMS]));
    });

    it('guia de outra clínica no pedido → 422 no item e nada gravado; perfil sem permissão financeira → 403', function (): void {
        $mine          = bbrClaim($this->entity, $this->covenant);
        $other         = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirCovenant = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]);
        $theirs        = bbrClaim($other, $theirCovenant);

        bbrPay([[$mine, 200], [$theirs, 200]])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['items.1.claim_id' => [__('financial_billing.errors.bulk_claims_not_found')]]);

        expect($theirs->fresh()->status)->toBe(BillingClaimStatus::Submitted)
            ->and(bbrEntries($mine))->toBe(0)
            ->and(bbrEntries($theirs))->toBe(0);

        $doctor = createDoctorForEntity($this->entity)->entityUser;

        $this->actingAs($doctor->user)
            ->withSession(panelSession($doctor))
            ->postJson(route('panel.financial.billing.claims.bulk-receipt'), [
                'paid_at' => now()->toDateString(), 'payment_method' => 'transfer', 'items' => [['claim_id' => $mine->id, 'paid_amount' => 200]],
            ])
            ->assertForbidden();

        expect(bbrEntries($mine))->toBe(0);
    });
});

describe('lote inteiro', function (): void {
    it('prévia com quantidade e total; paga só as guias pagáveis, pelo valor a receber', function (): void {
        $batch      = bbrBatch($this->entity, $this->covenant);
        $a          = bbrClaim($this->entity, $this->covenant, ['batch_id' => $batch->id]);
        $b          = bbrClaim($this->entity, $this->covenant, ['batch_id' => $batch->id, 'status' => BillingClaimStatus::Denied->value, 'amount' => 300, 'unit_price' => 300, 'glosa_amount' => 100]);
        $totalGlosa = bbrClaim($this->entity, $this->covenant, ['batch_id' => $batch->id, 'status' => BillingClaimStatus::Denied->value, 'glosa_amount' => 200]);
        $paid       = bbrClaim($this->entity, $this->covenant, ['batch_id' => $batch->id, 'status' => BillingClaimStatus::Paid->value, 'paid_amount' => 200, 'paid_at' => now()]);
        // Receita já lançada (legado): fica de fora, contada como bloqueada.
        $blocked = bbrClaim($this->entity, $this->covenant, ['batch_id' => $batch->id]);
        FinancialCashEntry::query()->create([
            'entity_id'   => $this->entity->id, 'billing_claim_id' => $blocked->id, 'entry_date' => now()->toDateString(),
            'description' => 'Recebimento antigo', 'type' => FinancialEntryType::Income->value, 'status' => 'paid', 'amount' => 200, 'active' => true,
        ]);

        $row = collect($this->get(route('panel.financial.billing.index', ['tab' => 'batches']))->viewData('page')['props']['batches']['data'])
            ->firstWhere('id', $batch->id);

        expect($row['allowed_actions'])->toContain('receive')
            ->and($row['receipt_preview_url'])->toBe(route('panel.financial.billing.batches.receipt-preview', $batch->id))
            ->and($row['receipt_url'])->toBe(route('panel.financial.billing.batches.receipt', $batch->id));

        $this->getJson($row['receipt_preview_url'])
            ->assertOk()
            ->assertExactJson(['count' => 2, 'total' => 400, 'blocked' => 1, 'max' => BillingBulkReceiptService::MAX_CLAIMS, 'over_limit' => false]);

        $this->postJson($row['receipt_url'], ['paid_at' => now()->toDateString(), 'payment_method' => PaymentMethod::Transfer->value])
            ->assertOk()
            ->assertJsonCount(2, 'paid')
            ->assertJsonPath('total_paid', 400)
            ->assertJsonPath('message', __('financial_billing.receipt_result.paid', ['count' => 2]));

        expect($a->fresh()->status)->toBe(BillingClaimStatus::Paid)
            ->and((float) $a->fresh()->paid_amount)->toBe(200.0)
            ->and((float) $b->fresh()->paid_amount)->toBe(200.0)
            ->and($totalGlosa->fresh()->status)->toBe(BillingClaimStatus::Denied)
            ->and($blocked->fresh()->status)->toBe(BillingClaimStatus::Submitted)
            ->and(bbrEntries($a))->toBe(1)
            ->and(bbrEntries($b))->toBe(1)
            ->and(bbrEntries($totalGlosa))->toBe(0)
            ->and(bbrEntries($paid))->toBe(0);

        // Sobrou só a bloqueada: a prévia mostra 0 e confirmar é 422 traduzido.
        $this->getJson($row['receipt_preview_url'])->assertOk()->assertJsonPath('count', 0)->assertJsonPath('blocked', 1);
        $this->postJson($row['receipt_url'], ['paid_at' => now()->toDateString(), 'payment_method' => 'transfer'])
            ->assertStatus(422)
            ->assertJsonPath('errors.batch.0', __('financial_billing.errors.batch_receipt_nothing_to_pay', ['code' => $batch->code]));
    });

    it('lote em rascunho não recebe (422); lote sem guia a receber não oferece a ação; outra clínica → 404', function (): void {
        $draft = bbrBatch($this->entity, $this->covenant, ['status' => BillingBatchStatus::Draft->value, 'submitted_at' => null]);
        bbrClaim($this->entity, $this->covenant, ['batch_id' => $draft->id, 'status' => BillingClaimStatus::Draft->value]);
        $settled = bbrBatch($this->entity, $this->covenant);
        bbrClaim($this->entity, $this->covenant, ['batch_id' => $settled->id, 'status' => BillingClaimStatus::Paid->value, 'paid_amount' => 200]);

        $this->getJson(route('panel.financial.billing.batches.receipt-preview', $draft->id))
            ->assertStatus(422)
            ->assertJsonPath('errors.batch.0', __('financial_billing.errors.batch_receipt_not_submitted', ['code' => $draft->code, 'status' => mb_strtolower(BillingBatchStatus::Draft->label())]));

        $this->postJson(route('panel.financial.billing.batches.receipt', $draft->id), ['paid_at' => now()->toDateString(), 'payment_method' => 'transfer'])
            ->assertStatus(422);

        $rows = collect($this->get(route('panel.financial.billing.index', ['tab' => 'batches']))->viewData('page')['props']['batches']['data'])->keyBy('id');

        expect($rows[$settled->id]['allowed_actions'])->not->toContain('receive')
            ->and($rows[$settled->id]['receipt_url'])->toBeNull()
            ->and($rows[$draft->id]['allowed_actions'])->not->toContain('receive');

        $other         = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirCovenant = Covenant::factory()->create(['entity_id' => $other->id, 'active' => true]);
        $theirBatch    = bbrBatch($other, $theirCovenant);
        bbrClaim($other, $theirCovenant, ['batch_id' => $theirBatch->id]);

        $this->getJson(route('panel.financial.billing.batches.receipt-preview', $theirBatch->id))->assertNotFound();
        $this->postJson(route('panel.financial.billing.batches.receipt', $theirBatch->id), ['paid_at' => now()->toDateString(), 'payment_method' => 'transfer'])
            ->assertNotFound();

        expect(FinancialCashEntry::query()->count())->toBe(0);
    });
});
